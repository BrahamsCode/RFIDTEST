<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Inventory\CycleReconciler;
use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Device;
use App\Models\DeviceHealthBeat;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\Zone;
use App\Services\InventoryCycleService;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Tarea 8.1: `sql/vistas-analiticas.sql` aplicado como migración. */
#[Group('pgsql')]
final class AnalyticViewsTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private Zone $sala;

    private Zone $trastienda;

    private ProductVariant $variant;

    private StockMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE device_health_beats, devices,
                       inventory_cycle_results, inventory_cycle_scans,
                       inventory_cycle_expected, inventory_cycles,
                       stock_movements, tags RESTART IDENTITY CASCADE');

        $this->movements = new StockMovementService(new TagStateMachine);

        $suffix = uniqid();
        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->tienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-01-{$suffix}", 'name' => 'Gamarra 1',
        ]);
        $this->sala = Zone::create([
            'location_id' => $this->tienda->id, 'code' => 'SALA',
            'name' => 'Sala principal', 'kind' => 'sala', 'counts_as_sellable' => true,
        ]);
        $this->trastienda = Zone::create([
            'location_id' => $this->tienda->id, 'code' => 'TRAS',
            'name' => 'Trastienda', 'kind' => 'trastienda', 'counts_as_sellable' => false,
        ]);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}", 'name' => 'Polo oversize',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "SKU-{$suffix}",
            'cost_price' => 20.0, 'sale_price' => 50.0, 'min_stock' => 2,
        ]);
    }

    public function test_estan_las_7_vistas_y_la_materializada(): void
    {
        $views = DB::table('information_schema.views')
            ->where('table_schema', 'public')
            ->pluck('table_name')
            ->all();

        foreach ([
            'v_current_stock', 'v_stock_valuation', 'v_inventory_accuracy',
            'v_shrinkage', 'v_stock_aging', 'v_replenishment_needed',
            'v_device_health',
        ] as $view) {
            $this->assertContains($view, $views, "Falta la vista {$view}.");
        }

        $this->assertSame(1, (int) DB::scalar(
            "SELECT count(*) FROM pg_matviews WHERE matviewname = 'mv_daily_stock'"
        ));
    }

    public function test_estan_las_3_funciones_de_reporte(): void
    {
        foreach (['cycle_zone_performance', 'stock_as_of', 'detect_cloned_tags'] as $fn) {
            $this->assertSame(1, (int) DB::scalar(
                'SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                  WHERE n.nspname = ? AND p.proname = ?',
                ['public', $fn]
            ), "Falta la función {$fn}.");
        }
    }

    public function test_el_stock_actual_distingue_lo_vendible(): void
    {
        $this->stockTags(3, $this->sala);
        $this->stockTags(2, $this->trastienda);

        $rows = DB::table('v_current_stock')
            ->where('location_id', $this->tienda->id)
            ->get();

        $this->assertSame(5, (int) $rows->sum('quantity'));
        // Solo la sala cuenta como disponible para el cliente.
        $this->assertSame(3, (int) $rows->sum('sellable_quantity'));
    }

    public function test_una_prenda_vendida_sale_del_stock_actual(): void
    {
        $tags = $this->stockTags(3, $this->sala);
        $this->movements->apply(new MovementIntent(
            tagId: $tags[0]->id, type: MovementType::Venta,
        ));

        $this->assertSame(2, (int) DB::table('v_current_stock')
            ->where('location_id', $this->tienda->id)->sum('quantity'));
    }

    public function test_la_valorizacion_usa_coste_y_precio_de_venta(): void
    {
        $this->stockTags(4, $this->sala);

        $row = DB::table('v_stock_valuation')
            ->where('location_id', $this->tienda->id)
            ->first();

        $this->assertSame(4, (int) $row->units);
        $this->assertSame('80.0000', $row->cost_value);   // 4 × 20
        $this->assertSame('200.0000', $row->retail_value); // 4 × 50
    }

    public function test_la_exactitud_solo_incluye_ciclos_cerrados(): void
    {
        $tags = $this->stockTags(4, $this->sala);
        $cycles = new InventoryCycleService;

        $cycle = $cycles->create($this->tienda, 'INV-VIS-01');
        $cycles->registerScans($cycle, [
            ['epc' => $tags[0]->epc], ['epc' => $tags[1]->epc], ['epc' => $tags[2]->epc],
        ]);

        // Aún abierto: no debe aparecer.
        $this->assertSame(0, DB::table('v_inventory_accuracy')->count());

        (new CycleReconciler($this->movements))->reconcile($cycle);

        $row = DB::table('v_inventory_accuracy')->first();
        $this->assertSame('75.000', $row->read_accuracy_pct);
        $this->assertSame(4, $row->expected_count);
    }

    public function test_la_merma_agrupa_por_mes_y_ubicacion(): void
    {
        $tags = $this->stockTags(2, $this->sala);

        // Para declarar merma hay que pasar antes por no_visto.
        foreach ($tags as $tag) {
            $this->movements->apply(new MovementIntent(
                tagId: $tag->id, type: MovementType::AjusteNegativo,
                toLocationId: $this->tienda->id,
            ));
            $this->movements->apply(new MovementIntent(
                tagId: $tag->id, type: MovementType::Merma,
                toLocationId: $this->tienda->id,
            ));
        }

        $row = DB::table('v_shrinkage')->first();

        // Dos ajustes negativos más dos mermas.
        $this->assertSame(4, (int) $row->units_lost);
    }

    public function test_la_antiguedad_clasifica_por_tramos(): void
    {
        $tags = $this->stockTags(2, $this->sala);

        DB::table('tags')->where('id', $tags[0]->id)
            ->update(['commissioned_at' => now()->subDays(5)]);
        DB::table('tags')->where('id', $tags[1]->id)
            ->update(['commissioned_at' => now()->subDays(200)]);

        $buckets = DB::table('v_stock_aging')
            ->orderBy('age_days')
            ->pluck('age_bucket')
            ->all();

        $this->assertSame(['0-30', '180+'], $buckets);
    }

    public function test_la_reposicion_avisa_cuando_hay_en_trastienda_y_no_en_sala(): void
    {
        // min_stock de la variante es 2.
        $this->stockTags(1, $this->sala);
        $this->stockTags(5, $this->trastienda);

        $row = DB::table('v_replenishment_needed')
            ->where('product_variant_id', $this->variant->id)
            ->first();

        $this->assertNotNull($row, 'Debería avisar: hay 1 en sala y 5 en trastienda.');
        $this->assertSame(1, (int) $row->on_floor);
        $this->assertSame(5, (int) $row->in_back);
    }

    public function test_la_reposicion_calla_si_la_sala_esta_surtida(): void
    {
        $this->stockTags(5, $this->sala);
        $this->stockTags(5, $this->trastienda);

        $this->assertSame(0, DB::table('v_replenishment_needed')
            ->where('product_variant_id', $this->variant->id)->count());
    }

    public function test_la_salud_del_dispositivo_refleja_el_ultimo_latido(): void
    {
        $device = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'EDGE-'.uniqid(), 'name' => 'Borde', 'kind' => 'edge',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
        ]);

        $sinDatos = DB::table('v_device_health')->where('device_id', $device->id)->first();
        $this->assertSame('sin_datos', $sinDatos->health);

        DeviceHealthBeat::create([
            'device_id' => $device->id, 'beat_at' => now(), 'buffer_depth' => 12,
        ]);
        $ok = DB::table('v_device_health')->where('device_id', $device->id)->first();
        $this->assertSame('ok', $ok->health);

        // Un lector que lleva un cuarto de hora callado está caído.
        DB::table('device_health_beats')->where('device_id', $device->id)
            ->update(['beat_at' => now()->subMinutes(15)]);
        $caido = DB::table('v_device_health')->where('device_id', $device->id)->first();
        $this->assertSame('caido', $caido->health);
    }

    public function test_la_vista_materializada_se_refresca(): void
    {
        $this->stockTags(3, $this->sala);

        // Se crea WITH NO DATA: hasta el primer refresco está vacía.
        DB::statement('REFRESH MATERIALIZED VIEW mv_daily_stock');

        $this->assertSame(3, (int) DB::table('mv_daily_stock')
            ->where('location_id', $this->tienda->id)->sum('quantity'));
    }

    public function test_cycle_zone_performance_delata_la_zona_no_barrida(): void
    {
        $enSala = $this->stockTags(2, $this->sala);
        $this->stockTags(2, $this->trastienda);

        $cycles = new InventoryCycleService;
        $cycle = $cycles->create($this->tienda, 'INV-VIS-02');
        $cycles->registerScans($cycle, array_map(
            fn (Tag $t) => ['epc' => $t->epc], $enSala
        ));

        $rows = collect(DB::select('SELECT * FROM cycle_zone_performance(?)', [$cycle->id]))
            ->keyBy('zone_name');

        $this->assertSame('100.00', $rows['Sala principal']->accuracy_pct);
        $this->assertSame('0.00', $rows['Trastienda']->accuracy_pct);
        $this->assertSame(2, (int) $rows['Trastienda']->missing);
    }

    public function test_stock_as_of_reconstruye_el_pasado_desde_los_movimientos(): void
    {
        $tags = $this->stockTags(3, $this->sala);
        $corte = now();

        // Después del corte se vende una.
        $this->travel(1)->minutes();
        $this->movements->apply(new MovementIntent(
            tagId: $tags[0]->id, type: MovementType::Venta,
        ));

        $antes = DB::select('SELECT * FROM stock_as_of(?, ?)', [$this->tienda->id, $corte]);
        $ahora = DB::select('SELECT * FROM stock_as_of(?, ?)', [$this->tienda->id, now()]);

        $this->assertSame(3, (int) $antes[0]->quantity, 'En el corte había 3.');
        $this->assertSame(2, (int) $ahora[0]->quantity, 'Ahora quedan 2.');
    }

    public function test_la_proyeccion_coincide_con_los_movimientos(): void
    {
        // Control de integridad de `docs/05` §6: `tags` es solo una
        // proyección de `stock_movements`. Si divergen, hay un bug.
        $tags = $this->stockTags(10, $this->sala);
        $this->movements->apply(new MovementIntent(tagId: $tags[0]->id, type: MovementType::Venta));
        $this->movements->apply(new MovementIntent(
            tagId: $tags[1]->id, type: MovementType::TransferenciaOut,
        ));

        $proyeccion = (int) DB::table('v_current_stock')
            ->where('location_id', $this->tienda->id)->sum('quantity');

        $derivado = (int) collect(
            DB::select('SELECT * FROM stock_as_of(?, ?)', [$this->tienda->id, now()])
        )->sum('quantity');

        $this->assertSame($proyeccion, $derivado);
        $this->assertSame(8, $proyeccion);
    }

    public function test_detect_cloned_tags_encuentra_un_epc_con_dos_tid(): void
    {
        $epc = '3035D9'.strtoupper(bin2hex(random_bytes(9)));
        $device = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'EDGE-'.uniqid(), 'name' => 'Borde', 'kind' => 'edge',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
        ]);

        foreach (['E28011AAAAAAAAAAAAAAAAAA', 'E28011BBBBBBBBBBBBBBBBBB'] as $tid) {
            DB::table('tag_reads')->insert([
                'read_at' => now(), 'epc' => $epc, 'tid' => $tid,
                'device_id' => $device->id, 'ingested_at' => now(),
            ]);
        }

        $cloned = DB::select('SELECT * FROM detect_cloned_tags(?)', [now()->subHour()]);

        $this->assertCount(1, $cloned);
        $this->assertSame($epc, $cloned[0]->epc);
        $this->assertSame(2, (int) $cloned[0]->distinct_tids);
    }

    /** @return list<Tag> */
    private function stockTags(int $count, Zone $zone): array
    {
        $tags = [];

        for ($i = 0; $i < $count; $i++) {
            $tag = Tag::create([
                'organization_id' => $this->organization->id,
                'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
                'product_variant_id' => $this->variant->id,
                'state' => TagState::Codificado,
                'commissioned_at' => now(),
            ]);

            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::Tarado,
                toLocationId: $this->tienda->id,
                toZoneId: $zone->id,
            ));

            $tags[] = $tag->refresh();
        }

        return $tags;
    }
}
