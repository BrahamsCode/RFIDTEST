<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ListenPortalEvents;
use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\AlertKind;
use App\Enums\MovementType;
use App\Enums\RoleCode;
use App\Enums\TagState;
use App\Events\PortalAlarmRaised;
use App\Models\Alert;
use App\Models\Device;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PortalEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Services\PortalEventService;
use App\Services\SaleService;
use App\Services\StockMovementService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Épica 6: portal antihurto. Tareas 6.1 a 6.4 y proceso P09 de `docs/10`. */
#[Group('pgsql')]
final class PortalEventTest extends TestCase
{
    private const TOKEN = 'token-del-portal-de-prueba';

    private Organization $organization;

    private Location $tienda;

    private Location $otraTienda;

    private Device $portal;

    private ProductVariant $variant;

    private PortalEventService $service;

    private StockMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE portal_events, alerts, sale_lines, sale_transactions,
                       role_user, roles, stock_movements, tags, users
                       RESTART IDENTITY CASCADE');
        (new RoleSeeder)->run();

        $this->service = app(PortalEventService::class);
        $this->movements = new StockMovementService(new TagStateMachine);

        $suffix = uniqid();
        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->tienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-{$suffix}", 'name' => 'Gamarra 1',
        ]);
        $this->otraTienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM2-{$suffix}", 'name' => 'Gamarra 2',
        ]);
        $this->portal = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => "PORTAL-{$suffix}",
            'name' => 'Portal puerta principal',
            'kind' => 'lector_fijo',
            'regulatory_region' => 'FCC-PE',
            'status' => 'activo',
            'api_token_hash' => Hash::make(self::TOKEN),
        ]);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}", 'name' => 'Polo',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "SKU-{$suffix}",
            'color' => 'Azul', 'size' => 'M', 'sale_price' => 59.9,
        ]);
    }

    // ------------------------------------------- 6.3 período de gracia

    public function test_una_prenda_sin_vender_que_sale_dispara_la_alarma(): void
    {
        Event::fake([PortalAlarmRaised::class]);
        $tag = $this->stockTag();

        $event = $this->cross($tag->epc, confidence: 0.92);

        $this->assertTrue($event->alarm_raised);
        $this->assertFalse($event->was_sold);
        Event::assertDispatched(PortalAlarmRaised::class);

        $alert = Alert::where('kind', AlertKind::SalidaNoVendida)->firstOrFail();
        $this->assertSame($tag->id, $alert->tag_id);
        $this->assertSame($event->id, $alert->detail['portal_event_id']);
    }

    public function test_una_venta_dentro_de_los_120_s_no_dispara_la_alarma(): void
    {
        Event::fake([PortalAlarmRaised::class]);
        $tag = $this->stockTag();

        $this->sell($tag, secondsAgo: 30);
        $event = $this->cross($tag->epc, confidence: 0.95);

        $this->assertFalse($event->alarm_raised);
        $this->assertTrue($event->was_sold);
        Event::assertNotDispatched(PortalAlarmRaised::class);
        $this->assertSame(0, Alert::count());
    }

    public function test_una_venta_anterior_al_periodo_de_gracia_si_dispara(): void
    {
        /*
         * El límite existe para que un ladrón no pueda esperar a que otro
         * cliente pague y salir detrás con lo suyo.
         *
         * El caso se construye con una venta registrada por un POS externo,
         * que escribe la línea pero no toca el estado del tag: es la única
         * situación en la que la prenda sigue `en_stock` con una venta
         * antigua a su nombre, y es justo para lo que sirve la ventana. Si
         * la venta la hubiera hecho TRAZA, el estado `vendido` ya evitaría
         * la alarma por sí solo.
         */
        $tag = $this->stockTag();
        $this->sellFromExternalPos($tag, secondsAgo: 300);

        $this->assertFalse($this->service->soldRecently($tag));

        $event = $this->cross($tag->epc, confidence: 0.95);

        $this->assertTrue($event->alarm_raised);
        $this->assertFalse($event->was_sold);
    }

    public function test_una_venta_reciente_de_un_pos_externo_tambien_da_gracia(): void
    {
        $tag = $this->stockTag();
        $this->sellFromExternalPos($tag, secondsAgo: 45);

        $event = $this->cross($tag->epc, confidence: 0.95);

        $this->assertTrue($event->was_sold);
        $this->assertFalse($event->alarm_raised);
    }

    public function test_una_devolucion_no_cuenta_como_venta_reciente(): void
    {
        $tag = $this->stockTag();
        $sale = $this->sell($tag, secondsAgo: 20);
        DB::table('sale_transactions')->where('id', $sale)->update(['is_return' => true]);

        $this->assertFalse($this->service->soldRecently($tag));
    }

    // ------------------------------------ reglas de P09: cuándo NO sonar

    public function test_por_debajo_del_umbral_de_confianza_no_suena(): void
    {
        // Regla 4 de P09: si la confianza es baja, el sistema no debe ni
        // sonar. Un portal que grita sin razón se acaba desconectando.
        Event::fake([PortalAlarmRaised::class]);
        $tag = $this->stockTag();

        $event = $this->cross($tag->epc, confidence: 0.55);

        $this->assertFalse($event->alarm_raised);
        Event::assertNotDispatched(PortalAlarmRaised::class);
    }

    public function test_una_entrada_no_dispara_la_alarma(): void
    {
        $tag = $this->stockTag();

        $event = $this->cross($tag->epc, confidence: 0.99, direction: 'entrada');

        $this->assertFalse($event->alarm_raised);
    }

    public function test_un_epc_ajeno_no_dispara_la_alarma_pero_si_se_registra(): void
    {
        // Casi siempre es del local de al lado o una prenda que el cliente
        // ya traía puesta. Alarmar por eso quema la credibilidad.
        $event = $this->cross('E28011000000000000ABCD', confidence: 0.99);

        $this->assertFalse($event->alarm_raised);
        $this->assertNull($event->tag_id);
        $this->assertNull($event->was_sold);
        $this->assertDatabaseHas('portal_events', ['epc' => 'E28011000000000000ABCD']);
    }

    public function test_una_prenda_ya_vendida_hace_tiempo_que_vuelve_a_salir_no_suena(): void
    {
        // Es una devolución que el cliente se lleva de nuevo, no un hurto.
        $tag = $this->stockTag();
        $this->sell($tag, secondsAgo: 3600);
        $tag->refresh();
        $this->assertSame(TagState::Vendido, $tag->state);

        $event = $this->cross($tag->epc, confidence: 0.99);

        $this->assertFalse($event->alarm_raised);
    }

    public function test_el_tránsito_queda_registrado_con_su_evidencia(): void
    {
        $tag = $this->stockTag();

        $event = $this->cross($tag->epc, confidence: 0.45, evidence: [
            'samples' => [['t' => 1, 'side' => 'interior', 'rssi' => -45, 'port' => 1]],
            'deltaMs' => 620,
        ]);

        $this->assertSame(620, $event->evidence['deltaMs']);
        $this->assertFalse($event->alarm_raised);
    }

    public function test_la_alarma_nombra_la_prenda_y_no_solo_el_epc(): void
    {
        Event::fake([PortalAlarmRaised::class]);
        $tag = $this->stockTag();

        $this->cross($tag->epc, confidence: 0.9);

        Event::assertDispatched(
            PortalAlarmRaised::class,
            fn (PortalAlarmRaised $e) => $e->productName === "{$this->variant->sku} Azul M"
                && $e->locationId === $this->tienda->id,
        );
    }

    // ------------------------------------------ 6.4 falsos positivos

    public function test_marcar_un_falso_positivo_descarta_la_alerta(): void
    {
        $tag = $this->stockTag();
        $event = $this->cross($tag->epc, confidence: 0.9);
        $vendedor = $this->userWith(RoleCode::Vendedor);

        $this->service->markFalsePositive($event, $vendedor->id, 'Era el bolso del cliente');

        $this->assertFalse($event->fresh()->alarm_raised);
        $alert = Alert::where('kind', AlertKind::SalidaNoVendida)->firstOrFail();
        $this->assertSame('descartada', $alert->status);
        $this->assertSame($vendedor->id, $alert->acknowledged_by);
        $this->assertSame('Era el bolso del cliente', $alert->resolution_note);
    }

    public function test_el_falso_positivo_deja_quien_y_cuando_en_la_evidencia(): void
    {
        // Sin esto no se puede detectar a alguien que descarta sus propias
        // alarmas, que es el riesgo de dejar la acción a cualquiera.
        $tag = $this->stockTag();
        $event = $this->cross($tag->epc, confidence: 0.9);
        $vendedor = $this->userWith(RoleCode::Vendedor);

        $this->service->markFalsePositive($event, $vendedor->id);

        $marca = $event->fresh()->evidence['false_positive'];
        $this->assertSame($vendedor->id, $marca['marked_by']);
        $this->assertNotEmpty($marca['marked_at']);
    }

    public function test_el_indicador_de_falsos_positivos_usa_esos_datos(): void
    {
        // Indicador de `docs/13` §2. Por encima del 20 % hay que recalibrar.
        $tags = [$this->stockTag(), $this->stockTag(), $this->stockTag(), $this->stockTag()];
        $eventos = array_map(fn (Tag $t) => $this->cross($t->epc, confidence: 0.9), $tags);

        $this->service->markFalsePositive($eventos[0]);

        $stats = $this->service->falsePositiveRate($this->tienda->id, now()->subDay());

        $this->assertSame(4, $stats['alarms']);
        $this->assertSame(1, $stats['dismissed']);
        $this->assertSame(25.0, $stats['rate']);
    }

    public function test_sin_alarmas_el_indicador_no_divide_entre_cero(): void
    {
        $stats = $this->service->falsePositiveRate($this->tienda->id, now()->subDay());

        $this->assertSame(['alarms' => 0, 'dismissed' => 0, 'rate' => 0.0], $stats);
    }

    // ---------------------------------------------------- superficie HTTP

    public function test_el_borde_puede_enviar_un_transito_por_http(): void
    {
        $tag = $this->stockTag();

        $this->withHeaders([
            'X-Device-Code' => $this->portal->code,
            'X-Device-Token' => self::TOKEN,
        ])->postJson('/api/v1/ingest/portal-event', [
            'epc' => $tag->epc,
            'direction' => 'salida',
            'confidence' => 0.88,
        ])->assertCreated()->assertJson(['alarm_raised' => true, 'direction' => 'salida']);
    }

    public function test_el_endpoint_http_exige_credenciales_de_dispositivo(): void
    {
        $this->postJson('/api/v1/ingest/portal-event', ['epc' => '3035D9000000000000000001'])
            ->assertUnauthorized();
    }

    public function test_un_handheld_no_puede_declarar_transitos_de_portal(): void
    {
        $handheld = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'HH-'.uniqid(), 'name' => 'Handheld', 'kind' => 'handheld',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
            'api_token_hash' => Hash::make(self::TOKEN),
        ]);

        $this->withHeaders([
            'X-Device-Code' => $handheld->code,
            'X-Device-Token' => self::TOKEN,
        ])->postJson('/api/v1/ingest/portal-event', [
            'epc' => '3035D9000000000000000001',
            'direction' => 'salida',
            'confidence' => 0.9,
        ])->assertStatus(422);
    }

    public function test_un_vendedor_marca_el_falso_positivo_de_un_toque(): void
    {
        $tag = $this->stockTag();
        $event = $this->cross($tag->epc, confidence: 0.9);
        $vendedor = $this->userWith(RoleCode::Vendedor);

        $this->actingAs($vendedor)
            ->postJson("/api/v1/portal-events/{$event->id}/false-positive")
            ->assertOk()
            ->assertJson(['alarm_raised' => false, 'status' => 'descartada']);
    }

    public function test_no_se_marca_el_falso_positivo_de_otra_tienda(): void
    {
        $tag = $this->stockTag();
        $event = $this->cross($tag->epc, confidence: 0.9);
        $ajeno = $this->userWith(RoleCode::Vendedor, $this->otraTienda);

        $this->actingAs($ajeno)
            ->postJson("/api/v1/portal-events/{$event->id}/false-positive")
            ->assertForbidden();
    }

    public function test_un_transito_sin_alarma_no_se_puede_descartar(): void
    {
        $tag = $this->stockTag();
        $event = $this->cross($tag->epc, confidence: 0.4);

        $this->actingAs($this->userWith(RoleCode::Vendedor))
            ->postJson("/api/v1/portal-events/{$event->id}/false-positive")
            ->assertForbidden();
    }

    public function test_el_listado_de_transitos_no_cruza_tiendas(): void
    {
        $this->cross($this->stockTag()->epc, confidence: 0.9);

        $ajeno = $this->userWith(RoleCode::Vendedor, $this->otraTienda);

        $this->actingAs($ajeno)
            ->getJson('/api/v1/portal-events')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_el_indicador_avisa_cuando_hay_que_recalibrar(): void
    {
        $eventos = [];
        for ($i = 0; $i < 4; $i++) {
            $eventos[] = $this->cross($this->stockTag()->epc, confidence: 0.9);
        }
        $this->service->markFalsePositive($eventos[0]);
        $this->service->markFalsePositive($eventos[1]);

        $this->actingAs($this->userWith(RoleCode::JefeTienda))
            ->getJson("/api/v1/portal-events/stats?location={$this->tienda->id}")
            ->assertOk()
            ->assertJson(['rate' => 50.0, 'needs_recalibration' => true, 'threshold' => 20.0]);
    }

    // ---------------------------------------------- 6.2 suscriptor MQTT

    public function test_el_suscriptor_mqtt_registra_el_transito(): void
    {
        $tag = $this->stockTag();
        $command = app(ListenPortalEvents::class);

        $command->dispatchMessage($this->service, "traza/{$this->tienda->code}/portal", json_encode([
            'deviceCode' => $this->portal->code,
            'epc' => $tag->epc,
            'direction' => 'salida',
            'confidence' => 0.93,
            'occurredAt' => now()->toIso8601String(),
            'evidence' => ['deltaMs' => 700],
        ], JSON_THROW_ON_ERROR));

        $event = PortalEvent::firstOrFail();
        $this->assertSame($this->portal->id, $event->device_id);
        $this->assertTrue($event->alarm_raised);
    }

    public function test_el_suscriptor_deduce_el_portal_desde_el_topico(): void
    {
        // Un mensaje del borde antiguo, sin deviceCode, no puede perderse.
        $tag = $this->stockTag();

        app(ListenPortalEvents::class)->dispatchMessage(
            $this->service,
            "traza/{$this->tienda->code}/portal",
            json_encode(['epc' => $tag->epc, 'direction' => 'salida', 'confidence' => 0.9], JSON_THROW_ON_ERROR),
        );

        $this->assertSame($this->portal->id, PortalEvent::firstOrFail()->device_id);
    }

    public function test_si_reverb_no_responde_la_alarma_se_registra_igual(): void
    {
        /*
         * `PortalAlarmRaised` es `ShouldBroadcastNow`: se difunde en el mismo
         * proceso. Con Reverb caído, la excepción reventaba el registro del
         * tránsito entero — al revés de lo que hace falta, porque el zumbador
         * del arco suena por su cuenta y lo que importa es dejar la alerta
         * escrita para que alguien la revise después.
         */
        Event::listen(PortalAlarmRaised::class, function (): void {
            throw new \RuntimeException('Pusher error: Could not resolve host: reverb');
        });

        $tag = $this->stockTag();
        $event = $this->cross($tag->epc, confidence: 0.9);

        $this->assertTrue($event->alarm_raised);
        $this->assertSame(1, Alert::where('kind', AlertKind::SalidaNoVendida)->count());
    }

    public function test_el_suscriptor_registra_el_transito_llamado_fuera_del_comando(): void
    {
        // El método es público justo para poder ejercitarlo sin broker. Si
        // toca `$this->output`, que ahí es null, el tránsito se pierde en su
        // propio bloque catch sin que nadie lo note.
        $tag = $this->stockTag();

        (new ListenPortalEvents)->dispatchMessage(
            $this->service,
            "traza/{$this->tienda->code}/portal",
            json_encode([
                'deviceCode' => $this->portal->code,
                'epc' => $tag->epc, 'direction' => 'salida', 'confidence' => 0.9,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(1, PortalEvent::count());
    }

    public function test_un_mensaje_ilegible_no_tumba_el_suscriptor(): void
    {
        // Si el proceso cae, la tienda se queda sin antihurto y nadie se
        // entera hasta que roban algo.
        $command = app(ListenPortalEvents::class);

        $command->dispatchMessage($this->service, 'traza/LIM-01/portal', '{esto no es json');
        $command->dispatchMessage($this->service, 'traza/LIM-01/portal', '{"sin":"epc"}');
        $command->dispatchMessage($this->service, 'traza/DESCONOCIDA/portal', '{"epc":"3035D9000000000000000001"}');

        $this->assertSame(0, PortalEvent::count());
    }

    /**
     * Presupuesto de la tarea 6.2: 800 ms extremo a extremo. Aquí se mide
     * solo el tramo del servidor —resolver el tag, mirar la venta reciente,
     * escribir el tránsito y levantar la alarma—, que es el único que este
     * repositorio controla. El salto MQTT y la latencia del lector se miden
     * con hardware, en la tarea 0.1.
     */
    public function test_el_tramo_de_servidor_cabe_de_sobra_en_el_presupuesto(): void
    {
        Event::fake([PortalAlarmRaised::class]);

        $tags = [];
        for ($i = 0; $i < 50; $i++) {
            $tags[] = $this->stockTag();
        }

        $samples = [];
        foreach ($tags as $tag) {
            $start = hrtime(true);
            $this->service->record($this->portal, [
                'epc' => $tag->epc, 'direction' => 'salida', 'confidence' => 0.9,
            ]);
            $samples[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($samples);
        $p99 = $samples[(int) floor(0.99 * (count($samples) - 1))];

        // 200 ms deja 600 ms para el borde, el broker y la red de la tienda.
        $this->assertLessThan(200, $p99, sprintf('p99 del tramo servidor: %.1f ms', $p99));
    }

    // ------------------------------------------------------------ helpers

    private function cross(
        string $epc,
        float $confidence,
        string $direction = 'salida',
        array $evidence = [],
    ): PortalEvent {
        return $this->service->record($this->portal, [
            'epc' => $epc,
            'direction' => $direction,
            'confidence' => $confidence,
            'evidence' => $evidence,
        ]);
    }

    private function stockTag(): Tag
    {
        $tag = Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
            'product_variant_id' => $this->variant->id,
            'state' => TagState::Codificado,
        ]);

        $this->movements->apply(new MovementIntent(
            tagId: $tag->id, type: MovementType::Tarado, toLocationId: $this->tienda->id,
        ));

        return $tag->refresh();
    }

    /** Vende la prenda y retrasa la marca de tiempo para simular el hueco. */
    private function sell(Tag $tag, int $secondsAgo): int
    {
        $result = app(SaleService::class)->sell(
            $this->tienda,
            'V-'.uniqid(),
            [$tag->epc],
        );

        $saleId = $result['sale']->id;

        DB::table('sale_transactions')
            ->where('id', $saleId)
            ->update(['sold_at' => now()->subSeconds($secondsAgo)]);

        return $saleId;
    }

    /**
     * Venta llegada de un POS ajeno: escribe la línea pero no mueve el tag.
     * El estado sigue siendo `en_stock`.
     */
    private function sellFromExternalPos(Tag $tag, int $secondsAgo): void
    {
        $saleId = DB::table('sale_transactions')->insertGetId([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'POS-'.uniqid(),
            'currency' => 'PEN',
            'sold_at' => now()->subSeconds($secondsAgo),
        ]);

        DB::table('sale_lines')->insert([
            'sale_transaction_id' => $saleId,
            'tag_id' => $tag->id,
            'product_variant_id' => $tag->product_variant_id,
            'quantity' => 1,
        ]);
    }

    private function userWith(RoleCode $role, ?Location $location = null): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id,
            'name' => $role->label(),
            'email' => uniqid($role->value.'-').'@vivatech-peru.com',
            'password' => 'secreto',
            'default_location_id' => ($location ?? $this->tienda)->id,
        ]);

        $user->roles()->attach(Role::where('code', $role->value)->value('id'));

        return $user->fresh();
    }
}
