<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Inventory\CycleReconciler;
use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\CycleScope;
use App\Enums\CycleStatus;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Device;
use App\Models\InventoryCycle;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\Zone;
use App\Services\InventoryCycleService;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/** Tareas 3.1, 3.2 y 3.3. */
#[Group('pgsql')]
final class InventoryCycleTest extends TestCase
{
    private const TOKEN = 'token-del-handheld';

    private Organization $organization;

    private Location $location;

    private ProductVariant $variant;

    private Device $handheld;

    private InventoryCycleService $cycles;

    private StockMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE inventory_cycle_results, inventory_cycle_scans,
                       inventory_cycle_expected, inventory_cycles,
                       stock_movements, tags RESTART IDENTITY CASCADE');

        $this->movements = new StockMovementService(new TagStateMachine());
        $this->cycles = new InventoryCycleService();

        $suffix = uniqid();
        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->location = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "T-{$suffix}",
            'name' => 'Gamarra 1',
        ]);
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}",
            'name' => 'Polo',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => "SKU-{$suffix}",
            'cost_price' => 20.0,
        ]);
        $this->handheld = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'code' => "HH-{$suffix}",
            'name' => 'Handheld 1',
            'kind' => 'handheld',
            'regulatory_region' => 'FCC-PE',
            'status' => 'activo',
            'api_token_hash' => Hash::make(self::TOKEN),
        ]);
    }

    // ------------------------------------------------------------ tarea 3.1

    public function test_congela_las_prendas_esperadas_al_arrancar(): void
    {
        $this->stockTags(5);

        $cycle = $this->cycles->create($this->location, 'INV-001');

        $this->assertSame(CycleStatus::EnCurso, $cycle->status);
        $this->assertSame(5, $cycle->expected_count);
        $this->assertSame(5, DB::table('inventory_cycle_expected')
            ->where('inventory_cycle_id', $cycle->id)->count());
    }

    public function test_una_venta_posterior_al_arranque_no_altera_los_esperados(): void
    {
        $tags = $this->stockTags(5);
        $cycle = $this->cycles->create($this->location, 'INV-002');

        // Alguien cobra una prenda mientras se barre la tienda.
        $this->movements->apply(new MovementIntent(
            tagId: $tags[0]->id,
            type: MovementType::Venta,
        ));

        $this->assertSame(5, DB::table('inventory_cycle_expected')
            ->where('inventory_cycle_id', $cycle->id)->count());
        $this->assertTrue(DB::table('inventory_cycle_expected')
            ->where('inventory_cycle_id', $cycle->id)
            ->where('tag_id', $tags[0]->id)->exists());
    }

    public function test_no_espera_lo_que_no_deberia_estar_presente(): void
    {
        $tags = $this->stockTags(4);
        $this->movements->apply(new MovementIntent(tagId: $tags[0]->id, type: MovementType::Venta));
        $this->movements->apply(new MovementIntent(
            tagId: $tags[1]->id,
            type: MovementType::TransferenciaOut,
        ));

        $cycle = $this->cycles->create($this->location, 'INV-003');

        // Solo las 2 que siguen en stock.
        $this->assertSame(2, $cycle->expected_count);
    }

    public function test_un_ciclo_por_zona_solo_espera_esa_zona(): void
    {
        $sala = Zone::create([
            'location_id' => $this->location->id,
            'code' => 'SALA', 'name' => 'Sala', 'kind' => 'sala',
        ]);
        $trastienda = Zone::create([
            'location_id' => $this->location->id,
            'code' => 'TRAS', 'name' => 'Trastienda', 'kind' => 'trastienda',
        ]);

        $tags = $this->stockTags(4);
        foreach ([0, 1] as $i) {
            $this->movements->apply(new MovementIntent(
                tagId: $tags[$i]->id, type: MovementType::CambioZona, toZoneId: $sala->id,
            ));
        }
        $this->movements->apply(new MovementIntent(
            tagId: $tags[2]->id, type: MovementType::CambioZona, toZoneId: $trastienda->id,
        ));

        $cycle = $this->cycles->create(
            $this->location, 'INV-004', CycleScope::Zona, [$sala->id]
        );

        $this->assertSame(2, $cycle->expected_count);
    }

    // ------------------------------------------------------------ tarea 3.2

    public function test_registra_escaneos_deduplicando_por_epc(): void
    {
        $tags = $this->stockTags(2);
        $cycle = $this->cycles->create($this->location, 'INV-010');

        // El handheld ve la misma prenda muchas veces en un barrido.
        $this->cycles->registerScans($cycle, [
            ['epc' => $tags[0]->epc, 'rssi' => -60, 'read_count' => 3],
            ['epc' => $tags[0]->epc, 'rssi' => -48, 'read_count' => 2],
            ['epc' => $tags[1]->epc, 'rssi' => -55],
        ], $this->handheld->id);

        $this->assertSame(2, $this->cycles->scannedCount($cycle));

        $row = DB::table('inventory_cycle_scans')
            ->where('inventory_cycle_id', $cycle->id)
            ->where('epc', $tags[0]->epc)->first();

        $this->assertSame(5, $row->read_count, 'Los conteos se acumulan.');
        $this->assertSame('-48.00', $row->max_rssi, 'Se conserva el RSSI máximo.');
    }

    public function test_deduplica_tambien_entre_lotes_distintos(): void
    {
        $tags = $this->stockTags(1);
        $cycle = $this->cycles->create($this->location, 'INV-011');

        $this->cycles->registerScans($cycle, [['epc' => $tags[0]->epc, 'read_count' => 2]]);
        $this->cycles->registerScans($cycle, [['epc' => $tags[0]->epc, 'read_count' => 3]]);

        $this->assertSame(1, $this->cycles->scannedCount($cycle));
        $this->assertSame(5, DB::table('inventory_cycle_scans')
            ->where('inventory_cycle_id', $cycle->id)->value('read_count'));
    }

    public function test_resuelve_el_tag_de_cada_epc_escaneado(): void
    {
        $tags = $this->stockTags(1);
        $cycle = $this->cycles->create($this->location, 'INV-012');

        $this->cycles->registerScans($cycle, [
            ['epc' => $tags[0]->epc],
            // Un EPC que no está en el sistema: se registra sin tag.
            ['epc' => '3035D9FFFFFFFFFFFFFFFFFF'],
        ]);

        $rows = DB::table('inventory_cycle_scans')
            ->where('inventory_cycle_id', $cycle->id)->orderBy('epc')->get();

        $this->assertSame($tags[0]->id, (int) $rows->firstWhere('epc', $tags[0]->epc)->tag_id);
        $this->assertNull($rows->firstWhere('epc', '3035D9FFFFFFFFFFFFFFFFFF')->tag_id);
    }

    public function test_un_ciclo_cerrado_no_admite_mas_escaneos(): void
    {
        $cycle = $this->cycles->create($this->location, 'INV-013');
        $cycle->update(['status' => CycleStatus::Cerrado]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no admite escaneos/');

        $this->cycles->registerScans($cycle->refresh(), [['epc' => '3035D9000000000000000001']]);
    }

    public function test_registra_20000_epc_en_lotes_de_500_en_menos_de_30_segundos(): void
    {
        $cycle = $this->cycles->create($this->location, 'INV-perf');

        $epcs = [];
        for ($i = 1; $i <= 20000; $i++) {
            $epcs[] = ['epc' => sprintf('3035D9%018d', $i), 'read_count' => 1];
        }

        $start = microtime(true);
        foreach (array_chunk($epcs, 500) as $chunk) {
            $this->cycles->registerScans($cycle, $chunk, $this->handheld->id);
        }
        $elapsed = microtime(true) - $start;

        $this->assertSame(20000, $this->cycles->scannedCount($cycle));
        $this->assertLessThan(30.0, $elapsed, sprintf('Tardó %.1f s.', $elapsed));
    }

    public function test_el_endpoint_de_escaneos_exige_token_de_dispositivo(): void
    {
        $cycle = $this->cycles->create($this->location, 'INV-014');

        $this->postJson("/api/v1/inventory-cycles/{$cycle->id}/scans", [
            'scans' => [['epc' => '3035D9000000000000000001']],
        ])->assertUnauthorized();
    }

    public function test_el_endpoint_registra_escaneos(): void
    {
        $tags = $this->stockTags(2);
        $cycle = $this->cycles->create($this->location, 'INV-015');

        $this->withHeaders(['X-Device-Token' => self::TOKEN, 'X-Device-Code' => $this->handheld->code])
            ->postJson("/api/v1/inventory-cycles/{$cycle->id}/scans", [
                'scans' => [
                    ['epc' => $tags[0]->epc, 'rssi' => -50],
                    ['epc' => $tags[1]->epc, 'rssi' => -62],
                ],
            ])
            ->assertStatus(202)
            ->assertJson(['registered' => 2, 'scanned_count' => 2]);
    }

    // ------------------------------------------------------------ tarea 3.3

    public function test_una_prenda_no_vista_una_vez_queda_en_no_visto(): void
    {
        $tags = $this->stockTags(3);
        $cycle = $this->cycles->create($this->location, 'INV-020');

        // Se barren solo 2 de las 3.
        $this->cycles->registerScans($cycle, [
            ['epc' => $tags[0]->epc], ['epc' => $tags[1]->epc],
        ]);

        $result = $this->reconciler()->reconcile($cycle);

        $this->assertSame(2, $result->found);
        $this->assertSame(1, $result->missing);
        $this->assertSame(0, $result->declaredLost, 'Nunca perdido en el primer ciclo.');

        $tags[2]->refresh();
        $this->assertSame(TagState::NoVisto, $tags[2]->state);
        $this->assertSame(1, $tags[2]->missed_cycles);
    }

    public function test_no_vista_dos_veces_pasa_a_perdido(): void
    {
        $tags = $this->stockTags(2);

        // Ciclo 1: no se ve.
        $cycle1 = $this->cycles->create($this->location, 'INV-021');
        $this->cycles->registerScans($cycle1, [['epc' => $tags[0]->epc]]);
        $this->reconciler()->reconcile($cycle1);

        $tags[1]->refresh();
        $this->assertSame(TagState::NoVisto, $tags[1]->state);

        // Ciclo 2: tampoco.
        $cycle2 = $this->cycles->create($this->location, 'INV-022');
        $this->cycles->registerScans($cycle2, [['epc' => $tags[0]->epc]]);
        $result = $this->reconciler()->reconcile($cycle2);

        $tags[1]->refresh();
        $this->assertSame(TagState::Perdido, $tags[1]->state);
        $this->assertSame(1, $result->declaredLost);
    }

    public function test_una_prenda_reencontrada_recupera_su_contador(): void
    {
        $tags = $this->stockTags(2);

        // Ciclo 1: no se ve → no_visto, missed_cycles = 1.
        $cycle1 = $this->cycles->create($this->location, 'INV-023');
        $this->cycles->registerScans($cycle1, [['epc' => $tags[0]->epc]]);
        $this->reconciler()->reconcile($cycle1);

        // Ciclo 2: sí se ve. Debe volver a en_stock y resetear el contador,
        // o el ciclo 3 la declararía merma pese a haberla visto.
        $cycle2 = $this->cycles->create($this->location, 'INV-024');
        $this->cycles->registerScans($cycle2, [
            ['epc' => $tags[0]->epc], ['epc' => $tags[1]->epc],
        ]);
        $this->reconciler()->reconcile($cycle2);

        $tags[1]->refresh();
        $this->assertSame(TagState::EnStock, $tags[1]->state);
        $this->assertSame(0, $tags[1]->missed_cycles);
    }

    public function test_lo_inesperado_entra_a_stock_con_ajuste_positivo(): void
    {
        $this->stockTags(1);
        $cycle = $this->cycles->create($this->location, 'INV-025');

        // Una prenda de otra tienda que nadie registró al recibirla.
        $intruso = Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => $this->randomEpc(),
            'product_variant_id' => $this->variant->id,
            'state' => TagState::Codificado,
        ]);

        $this->cycles->registerScans($cycle, [['epc' => $intruso->epc]]);
        $result = $this->reconciler()->reconcile($cycle);

        $this->assertSame(1, $result->unexpected);

        $intruso->refresh();
        $this->assertSame(TagState::EnStock, $intruso->state);
        $this->assertSame($this->location->id, $intruso->current_location_id);
    }

    public function test_el_ciclo_queda_cerrado_con_su_exactitud(): void
    {
        $tags = $this->stockTags(4);
        $cycle = $this->cycles->create($this->location, 'INV-026');

        $this->cycles->registerScans($cycle, [
            ['epc' => $tags[0]->epc], ['epc' => $tags[1]->epc], ['epc' => $tags[2]->epc],
        ]);
        $this->reconciler()->reconcile($cycle);

        $cycle->refresh();
        $this->assertSame(CycleStatus::Cerrado, $cycle->status);
        $this->assertNotNull($cycle->closed_at);
        $this->assertSame(3, $cycle->found_count);
        $this->assertSame(1, $cycle->missing_count);
        $this->assertSame(3, $cycle->counted_count);
        $this->assertSame(75.0, $cycle->accuracy_pct);
    }

    public function test_escribe_el_resultado_por_variante_con_su_diferencia(): void
    {
        $tags = $this->stockTags(4);
        $cycle = $this->cycles->create($this->location, 'INV-027');

        $this->cycles->registerScans($cycle, [
            ['epc' => $tags[0]->epc], ['epc' => $tags[1]->epc],
        ]);
        $this->reconciler()->reconcile($cycle);

        $row = DB::table('inventory_cycle_results')
            ->where('inventory_cycle_id', $cycle->id)
            ->where('product_variant_id', $this->variant->id)
            ->first();

        $this->assertSame(4, $row->expected_qty);
        $this->assertSame(2, $row->counted_qty);
        // Columna generada por la base.
        $this->assertSame(-2, $row->difference_qty);
        // 2 prendas de menos a 20.00 de coste.
        $this->assertSame('-40.0000', $row->value_difference);
    }

    public function test_un_ciclo_ya_cerrado_no_se_reconcilia_dos_veces(): void
    {
        $this->stockTags(1);
        $cycle = $this->cycles->create($this->location, 'INV-028');
        $this->reconciler()->reconcile($cycle);

        $user = \App\Models\User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Jefa de tienda',
            'email' => uniqid().'@vivatech-peru.com',
            'password' => Hash::make('secreto'),
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/inventory-cycles/{$cycle->id}/reconcile")
            ->assertStatus(409);
    }

    // ------------------------------------------------------------- helpers

    private function reconciler(): CycleReconciler
    {
        return new CycleReconciler($this->movements);
    }

    /** @return list<Tag> */
    private function stockTags(int $count): array
    {
        $tags = [];

        for ($i = 0; $i < $count; $i++) {
            $tag = Tag::create([
                'organization_id' => $this->organization->id,
                'epc' => $this->randomEpc(),
                'product_variant_id' => $this->variant->id,
                'state' => TagState::Codificado,
            ]);

            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::Tarado,
                toLocationId: $this->location->id,
            ));

            $tags[] = $tag->refresh();
        }

        return $tags;
    }

    private function randomEpc(): string
    {
        return '3035D9'.strtoupper(bin2hex(random_bytes(9)));
    }
}
