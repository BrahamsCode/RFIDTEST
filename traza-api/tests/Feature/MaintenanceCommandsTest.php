<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ExportColdReads;
use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Alert;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Épica 8: rotación de particiones, integridad nocturna y exportación a frío. */
#[Group('pgsql')]
final class MaintenanceCommandsTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private ProductVariant $variant;

    private StockMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE alerts, tag_reads, stock_movements, tags, audit_logs
                       RESTART IDENTITY CASCADE');

        $this->movements = new StockMovementService(new TagStateMachine);

        $suffix = uniqid();
        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->tienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-{$suffix}", 'name' => 'Gamarra 1',
        ]);
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}", 'name' => 'Polo',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "SKU-{$suffix}",
        ]);
    }

    // ---------------------------------------------- 8.2 rotación

    public function test_crea_las_particiones_de_los_dos_meses_siguientes(): void
    {
        /*
         * Dos y no una: el trabajo corre el día 20, así que si falla queda un
         * mes entero para enterarse. Con solo la del mes que viene, un fallo
         * silencioso manda las lecturas a `tag_reads_default` desde el día 1.
         */
        foreach ([1, 2] as $ahead) {
            $name = 'tag_reads_'.now()->addMonths($ahead)->format('Y_m');
            DB::statement("DROP TABLE IF EXISTS {$name}");
        }

        $this->artisan('traza:rotate-partitions')->assertSuccessful();

        foreach ([1, 2] as $ahead) {
            $name = 'tag_reads_'.now()->addMonths($ahead)->format('Y_m');
            $this->assertNotNull(
                DB::selectOne('SELECT to_regclass(?) AS oid', ["public.{$name}"])->oid,
                "Falta la partición {$name}.",
            );
        }
    }

    public function test_ejecutarlo_dos_veces_no_falla(): void
    {
        // El cron reintenta, y una excepción por «ya existe» dejaría sin
        // ejecutar la purga y la comprobación de la partición por defecto.
        $this->artisan('traza:rotate-partitions')->assertSuccessful();
        $this->artisan('traza:rotate-partitions')->assertSuccessful();
    }

    public function test_filas_en_la_particion_por_defecto_disparan_alerta(): void
    {
        // Una fila ahí significa que hubo lecturas sin partición de destino:
        // no se perdieron, pero están fuera del esquema de retención y la
        // purga no las tocará nunca.
        $device = $this->device();

        DB::table('tag_reads')->insert([
            'device_id' => $device,
            'location_id' => $this->tienda->id,
            'epc' => '3035D9000000000000000001',
            'rssi' => -50,
            // Muy en el futuro: no cae en ninguna partición mensual.
            'read_at' => now()->addYears(5),
        ]);

        $this->artisan('traza:rotate-partitions')->assertFailed();

        $alert = Alert::query()->whereRaw("detail->>'origen' = 'traza:rotate-partitions'")->first();
        $this->assertNotNull($alert, 'No se levantó alerta por tag_reads_default.');
        $this->assertSame(1, $alert->severity);
    }

    public function test_sin_purge_no_borra_nada(): void
    {
        // La purga es irreversible y el cron corre sola: el comportamiento
        // por defecto tiene que ser informar.
        $old = 'tag_reads_'.now()->subMonths(8)->format('Y_m');
        DB::select('SELECT ensure_tag_reads_partition(?::date)', [
            now()->subMonths(8)->startOfMonth()->toDateString(),
        ]);

        $this->artisan('traza:rotate-partitions')->assertSuccessful();

        $this->assertNotNull(DB::selectOne('SELECT to_regclass(?) AS oid', ["public.{$old}"])->oid);
    }

    public function test_no_purga_una_particion_sin_exportacion_verificada(): void
    {
        /*
         * El orden de `docs/13` §6: exportar, verificar, purgar. Una
         * partición borrada sin exportar son tres meses de trazabilidad que
         * no vuelven, y es justo lo que hace falta en una investigación de
         * merma.
         */
        $old = 'tag_reads_'.now()->subMonths(8)->format('Y_m');
        DB::select('SELECT ensure_tag_reads_partition(?::date)', [
            now()->subMonths(8)->startOfMonth()->toDateString(),
        ]);

        $this->artisan('traza:rotate-partitions --purge')->assertSuccessful();

        $this->assertNotNull(
            DB::selectOne('SELECT to_regclass(?) AS oid', ["public.{$old}"])->oid,
            'Se purgó una partición que no estaba exportada.',
        );
    }

    public function test_purga_lo_que_si_esta_exportado(): void
    {
        $month = now()->subMonths(8);
        $old = 'tag_reads_'.$month->format('Y_m');
        DB::select('SELECT ensure_tag_reads_partition(?::date)', [
            $month->startOfMonth()->toDateString(),
        ]);

        DB::table('audit_logs')->insert([
            'action' => ExportColdReads::AUDIT_ACTION,
            'changes' => json_encode(['partition' => $old, 'verified' => true]),
            'created_at' => now(),
        ]);

        $this->artisan('traza:rotate-partitions --purge')->assertSuccessful();

        $this->assertNull(DB::selectOne('SELECT to_regclass(?) AS oid', ["public.{$old}"])->oid);
    }

    // ------------------------------------------- 8.3 integridad

    public function test_con_los_datos_coherentes_no_dice_nada(): void
    {
        $this->stockTag();

        $this->artisan('traza:check-projection')->assertSuccessful();
        $this->assertSame(0, Alert::count());
    }

    public function test_una_desincronizacion_provocada_dispara_la_alerta(): void
    {
        /*
         * Criterio de aceptación de la tarea 8.3. Se rompe la proyección a
         * mano —que es exactamente lo que haría un bug en un camino de código
         * que no pase por `StockMovementService`— y el control tiene que
         * verlo.
         */
        $tag = $this->stockTag();

        DB::table('tags')->where('id', $tag->id)->update(['state' => 'vendido']);

        $this->artisan('traza:check-projection')->assertFailed();

        $alert = Alert::query()->whereRaw("detail->>'origen' = 'traza:check-projection'")->firstOrFail();

        $this->assertSame(1, $alert->severity, 'La desincronización es crítica, no informativa.');
        $this->assertSame(1, $alert->detail['variantes_afectadas']);
        $this->assertSame(1, $alert->detail['unidades_de_diferencia']);
    }

    public function test_detecta_tambien_el_exceso_en_la_proyeccion(): void
    {
        // El caso contrario: un tag marcado en stock sin movimiento que lo
        // respalde. Es peor que el anterior, porque infla el inventario.
        Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
            'product_variant_id' => $this->variant->id,
            'state' => TagState::EnStock,
            'current_location_id' => $this->tienda->id,
        ]);

        $this->artisan('traza:check-projection')->assertFailed();

        $alert = Alert::query()->whereRaw("detail->>'origen' = 'traza:check-projection'")->firstOrFail();
        $this->assertSame(1, $alert->detail['unidades_de_diferencia']);
    }

    public function test_la_alerta_no_guarda_miles_de_filas_dentro(): void
    {
        // Una alerta con 4 000 filas de detalle no la lee nadie y llena la
        // tabla. Se guarda una muestra y el resto se saca reejecutando.
        for ($i = 0; $i < 25; $i++) {
            $tag = $this->stockTag();
            DB::table('tags')->where('id', $tag->id)->update(['state' => 'vendido']);
        }

        $this->artisan('traza:check-projection')->assertFailed();

        $alert = Alert::query()->whereRaw("detail->>'origen' = 'traza:check-projection'")->firstOrFail();
        $this->assertLessThanOrEqual(20, count($alert->detail['muestra']));
    }

    // ---------------------------------------- 8.4 exportación

    public function test_exporta_la_particion_y_la_marca_verificada(): void
    {
        Storage::fake('s3');

        $month = now()->format('Y_m');
        $this->readsForCurrentMonth(5);

        $this->artisan("traza:export-cold-reads --month={$month}")->assertSuccessful();

        Storage::disk('s3')->assertExists("cold-reads/tag_reads_{$month}.csv.gz");

        $audit = DB::table('audit_logs')->where('action', ExportColdReads::AUDIT_ACTION)->first();
        $this->assertNotNull($audit);

        $changes = json_decode((string) $audit->changes, true);
        $this->assertSame("tag_reads_{$month}", $changes['partition']);
        $this->assertSame(5, $changes['rows']);
        $this->assertTrue($changes['verified']);
    }

    public function test_el_fichero_exportado_contiene_las_lecturas(): void
    {
        // Verificar que el objeto existe no basta: un fichero vacío también
        // existe. Lo que importa es que los EPC estén dentro.
        Storage::fake('s3');

        $month = now()->format('Y_m');
        $this->readsForCurrentMonth(3);

        $this->artisan("traza:export-cold-reads --month={$month}")->assertSuccessful();

        $gz = Storage::disk('s3')->get("cold-reads/tag_reads_{$month}.csv.gz");
        $csv = gzdecode($gz);

        $this->assertStringContainsString('epc', $csv, 'Falta la cabecera.');
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1, 'Cabecera + 3 lecturas.');
    }

    public function test_una_particion_inexistente_no_es_un_error(): void
    {
        // El cron corre todos los meses; en una instalación nueva la
        // partición de hace cuatro meses no existe, y eso es normal.
        Storage::fake('s3');

        $this->artisan('traza:export-cold-reads --month=1999_01')->assertSuccessful();
        $this->assertSame(0, DB::table('audit_logs')->where('action', ExportColdReads::AUDIT_ACTION)->count());
    }

    public function test_un_mes_con_formato_invalido_se_rechaza(): void
    {
        $this->artisan('traza:export-cold-reads --month=agosto')->assertExitCode(2);
    }

    // ------------------------------------------------------------ helpers

    private function stockTag(): Tag
    {
        $tag = Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
            'product_variant_id' => $this->variant->id,
            'state' => TagState::Codificado,
        ]);

        $this->movements->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::Tarado,
            toLocationId: $this->tienda->id,
        ));

        return $tag->refresh();
    }

    private function device(): int
    {
        return (int) DB::table('devices')->insertGetId([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'DEV-'.uniqid(),
            'name' => 'Borde',
            'kind' => 'edge',
            'regulatory_region' => 'FCC-PE',
            'status' => 'activo',
        ]);
    }

    private function readsForCurrentMonth(int $count): void
    {
        $device = $this->device();

        DB::table('tag_reads')->insert(array_map(fn (int $i) => [
            'device_id' => $device,
            'location_id' => $this->tienda->id,
            'epc' => sprintf('3035D9%018X', $i),
            'rssi' => -50,
            'read_at' => now(),
        ], range(1, $count)));
    }
}
