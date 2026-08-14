<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Devices\DeviceToken;
use App\Enums\AlertKind;
use App\Jobs\ProcessReadBatch;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceAntenna;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\Zone;
use App\Services\AlertService;
use App\Services\InventoryCycleService;
use App\Services\TagResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Tarea 2.1. Requiere PostgreSQL: `tag_reads` está particionada. */
#[Group('pgsql')]
final class IngestReadsTest extends TestCase
{
    private const TOKEN = 'token-de-prueba-del-borde';

    /**
     * Token propio de cada prueba.
     *
     * Antes todas compartían `self::TOKEN`. Los dispositivos se acumulan
     * entre pruebas —aquí no se vacía `devices`—, así que dos equipos
     * acababan con el mismo token, que es justo lo que el índice único de
     * `devices.api_token_hash` prohíbe y lo que nunca debería pasar en
     * producción: el token es lo que identifica al dispositivo.
     */
    private string $token;

    private Organization $organization;

    private Location $location;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        $suffix = uniqid();
        $this->token = self::TOKEN.'-'.$suffix;

        $this->organization = Organization::create([
            'name' => 'VivaTech Pruebas',
            'epc_filter_mask' => '3035D9',
        ]);
        $this->location = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "T-{$suffix}",
            'name' => 'Gamarra 1',
        ]);
        $this->device = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'code' => "EDGE-{$suffix}",
            'name' => 'Borde de tienda',
            'kind' => 'edge',
            'regulatory_region' => 'FCC-PE',
            'status' => 'activo',
            'api_token_hash' => DeviceToken::hash($this->token),
        ]);

        cache()->forget("epc_mask:{$this->organization->id}");

        // Estas pruebas consultan tablas globales (`tag_reads`, `alerts`…),
        // así que necesitan partir de cero. No se envuelven en transacciones
        // porque `tag_reads` está particionada y las particiones se verifican
        // directamente.
        DB::statement('TRUNCATE tag_reads, unknown_epcs, alerts, device_health_beats');
    }

    // ---------------------------------------------------------------- auth

    public function test_rechaza_una_peticion_sin_credenciales(): void
    {
        $this->postJson('/api/v1/ingest/reads', $this->payload())->assertUnauthorized();
    }

    public function test_rechaza_un_token_incorrecto(): void
    {
        $this->withHeaders(['X-Device-Token' => 'token-equivocado'])
            ->postJson('/api/v1/ingest/reads', $this->payload())
            ->assertUnauthorized();
    }

    public function test_rechaza_un_dispositivo_dado_de_baja(): void
    {
        $this->device->forceFill(['status' => 'baja'])->save();

        $this->ingest($this->payload())->assertForbidden();
    }

    // ------------------------------------------------------------- ingesta

    public function test_acepta_un_lote_y_lo_guarda(): void
    {
        Queue::fake();

        $payload = $this->payload(reads: [
            $this->read('3035D9000000000000000001'),
            $this->read('3035D9000000000000000002'),
        ]);

        $this->ingest($payload)
            ->assertStatus(202)
            ->assertJson(['accepted' => 2, 'rejected' => 0, 'queued_job' => 'ProcessReadBatch']);

        $this->assertSame(2, DB::table('tag_reads')->where('device_id', $this->device->id)->count());
        Queue::assertPushedOn('reads', ProcessReadBatch::class);
    }

    public function test_los_epc_fuera_de_mascara_no_llegan_a_tag_reads(): void
    {
        Queue::fake();

        $payload = $this->payload(reads: [
            $this->read('3035D9000000000000000001'),
            // Del local de al lado: prefijo ajeno.
            $this->read('E28011AABBCCDDEEFF001122'),
            $this->read('E28011AABBCCDDEEFF003344'),
        ]);

        $this->ingest($payload)
            ->assertStatus(202)
            ->assertJson(['accepted' => 1, 'rejected' => 2]);

        $stored = DB::table('tag_reads')->where('device_id', $this->device->id)->pluck('epc');
        $this->assertSame(['3035D9000000000000000001'], $stored->all());
    }

    public function test_el_mismo_batch_id_no_duplica_lecturas(): void
    {
        Queue::fake();

        $payload = $this->payload(reads: [$this->read('3035D9000000000000000001')]);

        $this->ingest($payload)->assertStatus(202);
        // El borde reintenta porque no recibió la respuesta.
        $this->ingest($payload)
            ->assertOk()
            ->assertJson(['status' => 'duplicate', 'accepted' => 0]);

        $this->assertSame(1, DB::table('tag_reads')->where('device_id', $this->device->id)->count());
    }

    public function test_inserta_un_lote_grande_en_trozos(): void
    {
        Queue::fake();
        // Por encima del tope por defecto de 1000, que se prueba aparte.
        config()->set('traza.ingest.max_batch', 2000);

        $reads = [];
        for ($i = 1; $i <= 1200; $i++) {
            $reads[] = $this->read(sprintf('3035D9%018d', $i));
        }

        // 1200 lecturas caben en 3 trozos de 500; se cuentan las sentencias
        // de inserción para comprobar que no va fila a fila.
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'insert into "tag_reads"')) {
                $inserts++;
            }
        });

        $this->ingest($this->payload(reads: $reads))
            ->assertStatus(202)
            ->assertJson(['accepted' => 1200]);

        $this->assertSame(3, $inserts, 'Deberían ser 3 sentencias de 500, 500 y 200.');
        $this->assertSame(1200, DB::table('tag_reads')->where('device_id', $this->device->id)->count());
    }

    public function test_rechaza_un_lote_por_encima_del_maximo(): void
    {
        config()->set('traza.ingest.max_batch', 10);

        $reads = [];
        for ($i = 1; $i <= 11; $i++) {
            $reads[] = $this->read(sprintf('3035D9%018d', $i));
        }

        $this->ingest($this->payload(reads: $reads))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reads');
    }

    public function test_rechaza_un_epc_que_no_es_hexadecimal(): void
    {
        $this->ingest($this->payload(reads: [$this->read('ZZZZ-NO-ES-HEX')]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reads.0.epc');
    }

    public function test_exige_un_batch_id_con_formato_uuid(): void
    {
        $payload = $this->payload();
        $payload['batch_id'] = 'no-es-uuid';

        $this->ingest($payload)->assertStatus(422)->assertJsonValidationErrors('batch_id');
    }

    public function test_asigna_la_zona_segun_la_antena_del_dispositivo(): void
    {
        Queue::fake();

        $sala = Zone::create([
            'location_id' => $this->location->id,
            'code' => 'SALA',
            'name' => 'Sala principal',
            'kind' => 'sala',
        ]);
        DeviceAntenna::create([
            'device_id' => $this->device->id,
            'zone_id' => $sala->id,
            'port_number' => 2,
        ]);

        $this->ingest($this->payload(reads: [
            $this->read('3035D9000000000000000001', antenna: 2),
            $this->read('3035D9000000000000000002', antenna: 7),
        ]))->assertStatus(202);

        $rows = DB::table('tag_reads')->orderBy('epc')->get();
        $this->assertSame($sala->id, (int) $rows[0]->zone_id);
        // Una antena sin zona configurada no inventa ninguna.
        $this->assertNull($rows[1]->zone_id);
    }

    public function test_la_lectura_cae_en_la_particion_del_mes(): void
    {
        Queue::fake();

        $this->ingest($this->payload(reads: [
            $this->read('3035D9000000000000000001', readAt: '2026-09-15T10:00:00Z'),
        ]))->assertStatus(202);

        $this->assertSame(1, (int) DB::scalar('SELECT count(*) FROM tag_reads_2026_09'));
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM tag_reads_default'));
    }

    // -------------------------------------------------------------- latido

    public function test_registra_el_latido_del_dispositivo(): void
    {
        $this->withHeaders(['X-Device-Token' => $this->token])
            ->postJson('/api/v1/ingest/heartbeat', [
                'device_code' => $this->device->code,
                'reads_last_min' => 420,
                'buffer_depth' => 17,
                'uptime_s' => 3600,
                'version' => '0.1.0',
                'readers' => ['SIM-01' => true],
            ])
            ->assertStatus(202);

        $beat = DB::table('device_health_beats')->where('device_id', $this->device->id)->first();
        $this->assertSame(420, $beat->reads_last_min);
        $this->assertSame(17, $beat->buffer_depth);

        $this->device->refresh();
        $this->assertNotNull($this->device->last_seen_at);
    }

    // ----------------------------------------------------------------- job

    public function test_el_job_registra_los_epc_desconocidos(): void
    {
        Queue::fake();

        $this->ingest($this->payload(reads: [$this->read('3035D9000000000000000099')]))
            ->assertStatus(202);

        (new ProcessReadBatch('lote', $this->device->id))
            ->handle(new TagResolver, new AlertService, new InventoryCycleService);

        $unknown = DB::table('unknown_epcs')->where('epc', '3035D9000000000000000099')->first();
        $this->assertNotNull($unknown);
        $this->assertSame(1, $unknown->seen_count);
    }

    public function test_un_epc_con_dos_tid_distintos_genera_alerta(): void
    {
        Queue::fake();

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => 'P-'.uniqid(),
            'name' => 'Polo',
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.uniqid()]);

        $epc = '3035D9000000000000000123';
        Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => $epc,
            'product_variant_id' => $variant->id,
            'tid' => 'E2801190200070C8A1B2C3D4',
            'state' => 'en_stock',
        ]);

        // Se lee el mismo EPC pero con un TID que no es el grabado en fábrica.
        $this->ingest($this->payload(reads: [
            $this->read($epc, tid: 'E28011FFFFFFFFFFFFFFFFFF'),
        ]))->assertStatus(202);

        (new ProcessReadBatch('lote', $this->device->id))
            ->handle(new TagResolver, new AlertService, new InventoryCycleService);

        $alert = Alert::where('kind', AlertKind::TidDiscrepante)->first();
        $this->assertNotNull($alert, 'Debería haberse levantado una alerta de clonación.');
        $this->assertSame(1, $alert->severity, 'Una posible clonación es crítica.');
    }

    public function test_las_lecturas_del_handheld_entran_en_el_ciclo_en_curso(): void
    {
        /*
         * Enrutado por tipo de dispositivo, tarea 2.2. El handheld también
         * puede llamar directamente a `/inventory-cycles/{id}/scans`; que las
         * lecturas ingeridas cuenten igual es lo que hace que un barrido no
         * dependa de que la app acierte con el ciclo.
         */
        Queue::fake();

        $handheld = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'code' => 'HH-'.uniqid(), 'name' => 'Handheld', 'kind' => 'handheld',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
            'api_token_hash' => DeviceToken::hash(Str::random(64)),
        ]);

        $tag = $this->stockTagForCycle();
        $cycle = app(InventoryCycleService::class)->create($this->location, 'INV-'.uniqid());
        DB::table('inventory_cycles')->where('id', $cycle->id)->update(['status' => 'en_curso']);

        DB::table('tag_reads')->insert([
            'device_id' => $handheld->id,
            'location_id' => $this->location->id,
            'epc' => $tag->epc,
            'rssi' => -50,
            'read_at' => now(),
            'ingested_at' => now(),
        ]);

        (new ProcessReadBatch('lote', $handheld->id))
            ->handle(new TagResolver, new AlertService, app(InventoryCycleService::class));

        $this->assertSame(
            1,
            DB::table('inventory_cycle_scans')->where('inventory_cycle_id', $cycle->id)->count(),
        );
    }

    public function test_un_ciclo_pausado_no_recibe_lecturas(): void
    {
        // La pausa existe porque el operario paró para atender a alguien.
        // Seguir contándole lecturas la volvería inútil.
        Queue::fake();

        $handheld = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'code' => 'HH-'.uniqid(), 'name' => 'Handheld', 'kind' => 'handheld',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
            'api_token_hash' => DeviceToken::hash(Str::random(64)),
        ]);

        $tag = $this->stockTagForCycle();
        $cycle = app(InventoryCycleService::class)->create($this->location, 'INV-'.uniqid());
        DB::table('inventory_cycles')->where('id', $cycle->id)->update(['status' => 'pausado']);

        DB::table('tag_reads')->insert([
            'device_id' => $handheld->id,
            'location_id' => $this->location->id,
            'epc' => $tag->epc,
            'rssi' => -50,
            'read_at' => now(),
            'ingested_at' => now(),
        ]);

        (new ProcessReadBatch('lote', $handheld->id))
            ->handle(new TagResolver, new AlertService, app(InventoryCycleService::class));

        $this->assertSame(
            0,
            DB::table('inventory_cycle_scans')->where('inventory_cycle_id', $cycle->id)->count(),
        );
    }

    public function test_un_lector_fijo_no_crea_eventos_de_portal_desde_la_ingesta(): void
    {
        /*
         * El portal va por el camino rápido MQTT y ya escribió su
         * `portal_events` con dirección y confianza. Evaluarlo otra vez desde
         * la ingesta duplicaría la alarma, y encima sin dirección —`tag_reads`
         * no la guarda—, así que todo saldría como `indeterminado`.
         */
        Queue::fake();

        $portal = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'code' => 'PORT-'.uniqid(), 'name' => 'Portal', 'kind' => 'lector_fijo',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
            'api_token_hash' => DeviceToken::hash(Str::random(64)),
        ]);

        $tag = $this->stockTagForCycle();

        DB::table('tag_reads')->insert([
            'device_id' => $portal->id,
            'location_id' => $this->location->id,
            'epc' => $tag->epc,
            'rssi' => -50,
            'read_at' => now(),
            'ingested_at' => now(),
        ]);

        (new ProcessReadBatch('lote', $portal->id))
            ->handle(new TagResolver, new AlertService, app(InventoryCycleService::class));

        $this->assertSame(0, DB::table('portal_events')->count());
    }

    private function stockTagForCycle(): Tag
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => 'P-'.uniqid(), 'name' => 'Polo',
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.uniqid()]);

        return Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
            'product_variant_id' => $variant->id,
            'state' => 'en_stock',
            'current_location_id' => $this->location->id,
        ]);
    }

    public function test_no_alerta_cuando_el_tid_coincide(): void
    {
        Queue::fake();

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => 'P-'.uniqid(),
            'name' => 'Polo',
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.uniqid()]);

        $epc = '3035D9000000000000000124';
        $tid = 'E2801190200070C8A1B2C3D4';
        Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => $epc,
            'product_variant_id' => $variant->id,
            'tid' => $tid,
            'state' => 'en_stock',
        ]);

        $this->ingest($this->payload(reads: [$this->read($epc, tid: $tid)]))->assertStatus(202);

        (new ProcessReadBatch('lote', $this->device->id))
            ->handle(new TagResolver, new AlertService, new InventoryCycleService);

        $this->assertSame(0, Alert::where('kind', AlertKind::TidDiscrepante)->count());
    }

    // ------------------------------------------------------------- helpers

    /** @param list<array<string, mixed>> $reads */
    private function payload(?array $reads = null): array
    {
        return [
            'device_code' => $this->device->code,
            'batch_id' => (string) Str::uuid(),
            'session_ref' => (string) Str::uuid(),
            'reads' => $reads ?? [$this->read('3035D9000000000000000001')],
        ];
    }

    /** @return array<string, mixed> */
    private function read(
        string $epc,
        ?string $tid = null,
        int $antenna = 1,
        string $readAt = '2026-08-10T14:22:11Z',
    ): array {
        return array_filter([
            'epc' => $epc,
            'tid' => $tid,
            'antenna' => $antenna,
            'rssi' => -52.4,
            'read_at' => $readAt,
            'read_count' => 3,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $payload */
    private function ingest(array $payload): TestResponse
    {
        return $this->withHeaders(['X-Device-Token' => $this->token])
            ->postJson('/api/v1/ingest/reads', $payload);
    }
}
