<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\AlertKind;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\User;
use App\Models\Zone;
use App\Services\AlertService;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Superficie REST de `docs/06` §6 y sus convenciones. */
#[Group('pgsql')]
final class ApiSurfaceTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private Location $otraTienda;

    private ProductVariant $variant;

    private User $user;

    private StockMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE sale_lines, sale_transactions, transfer_tags, transfers,
                       receiving_order_lines, receiving_orders, tag_replacements, alerts,
                       inventory_cycle_results, inventory_cycle_scans,
                       inventory_cycle_expected, inventory_cycles,
                       stock_movements, tags, role_user, roles, users
                       RESTART IDENTITY CASCADE');

        $this->movements = new StockMovementService(new TagStateMachine());

        $suffix = uniqid();
        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->tienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-01-{$suffix}", 'name' => 'Gamarra 1',
        ]);
        $this->otraTienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-02-{$suffix}", 'name' => 'Gamarra 2',
        ]);
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}", 'name' => 'Polo',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "SKU-{$suffix}",
            'cost_price' => 18.0, 'sale_price' => 49.9,
        ]);
        // Con la épica 9 en marcha, un usuario sin rol no puede crear ni
        // cerrar ciclos: hay que darle uno explícitamente.
        (new \Database\Seeders\RoleSeeder())->run();

        $this->user = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Jefa de tienda',
            'email' => "jefa-{$suffix}@vivatech-peru.com",
            'password' => 'secreto',
            'default_location_id' => $this->tienda->id,
        ]);
        $this->user->roles()->attach(
            \App\Models\Role::where('code', \App\Enums\RoleCode::JefeTienda->value)->value('id')
        );
        $this->user = $this->user->fresh();
    }

    // ------------------------------------------------------- convenciones

    public function test_los_errores_de_validacion_van_en_rfc_7807(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales', ['code' => 'V-1']);

        $response->assertStatus(422)
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonStructure(['type', 'title', 'status', 'errors']);
    }

    public function test_un_recurso_inexistente_devuelve_problem_json(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/inventory-cycles/999999')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/problem+json');
    }

    public function test_la_paginacion_expone_meta_total(): void
    {
        $this->stockedTags(5);

        $this->actingAs($this->user)
            ->getJson('/api/v1/tags?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_per_page_no_supera_el_maximo_de_200(): void
    {
        $this->stockedTags(1);

        $this->actingAs($this->user)
            ->getJson('/api/v1/tags?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);
    }

    public function test_la_idempotency_key_evita_cobrar_dos_veces(): void
    {
        $tags = $this->stockedTags(1);
        $payload = [
            'location_id' => $this->tienda->id,
            'code' => 'V-IDEM',
            'epcs' => [$tags[0]->epc],
        ];

        $first = $this->actingAs($this->user)
            ->withHeaders(['Idempotency-Key' => 'clave-unica'])
            ->postJson('/api/v1/sales', $payload)
            ->assertStatus(201);

        // El cliente reintenta por un timeout de red.
        $second = $this->actingAs($this->user)
            ->withHeaders(['Idempotency-Key' => 'clave-unica'])
            ->postJson('/api/v1/sales', $payload)
            ->assertStatus(201)
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, DB::table('sale_transactions')->count());
    }

    public function test_la_misma_clave_con_otro_cuerpo_es_conflicto(): void
    {
        $tags = $this->stockedTags(2);

        $this->actingAs($this->user)
            ->withHeaders(['Idempotency-Key' => 'clave-dupe'])
            ->postJson('/api/v1/sales', [
                'location_id' => $this->tienda->id, 'code' => 'V-A', 'epcs' => [$tags[0]->epc],
            ])->assertStatus(201);

        $this->actingAs($this->user)
            ->withHeaders(['Idempotency-Key' => 'clave-dupe'])
            ->postJson('/api/v1/sales', [
                'location_id' => $this->tienda->id, 'code' => 'V-B', 'epcs' => [$tags[1]->epc],
            ])->assertStatus(409);
    }

    // ------------------------------------------------------------- ventas

    public function test_el_endpoint_de_venta_cobra_por_epc(): void
    {
        $tags = $this->stockedTags(2);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'location_id' => $this->tienda->id,
                'code' => 'V-001',
                'epcs' => array_map(fn (Tag $t) => $t->epc, $tags),
            ])
            ->assertStatus(201)
            ->assertJson(['sold' => 2, 'ignored' => 0]);

        $this->assertSame(TagState::Vendido, $tags[0]->refresh()->state);
    }

    public function test_devolver_un_epc_nunca_vendido_da_422_con_mensaje_claro(): void
    {
        $tags = $this->stockedTags(1);
        $sale = $this->actingAs($this->user)->postJson('/api/v1/sales', [
            'location_id' => $this->tienda->id, 'code' => 'V-002', 'epcs' => [$tags[0]->epc],
        ])->json();

        $otro = $this->stockedTags(1)[0];

        $this->actingAs($this->user)
            ->postJson("/api/v1/sales/{$sale['id']}/return", [
                'epc' => $otro->epc,
                'code' => 'D-001',
            ])
            ->assertStatus(422)
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('detail', "La prenda {$otro->epc} no consta como vendida. No se puede aceptar la devolución.");
    }

    public function test_una_devolucion_legitima_pasa_por_el_endpoint(): void
    {
        $tags = $this->stockedTags(1);
        $sale = $this->actingAs($this->user)->postJson('/api/v1/sales', [
            'location_id' => $this->tienda->id, 'code' => 'V-003', 'epcs' => [$tags[0]->epc],
        ])->json();

        $this->actingAs($this->user)
            ->postJson("/api/v1/sales/{$sale['id']}/return", [
                'epc' => $tags[0]->epc, 'code' => 'D-002',
            ])
            ->assertStatus(201)
            ->assertJsonPath('original_sale_id', $sale['id']);

        $this->assertSame(TagState::EnStock, $tags[0]->refresh()->state);
    }

    // ---------------------------------------------------------- recepción

    public function test_crear_una_orden_y_recibirla_por_lectura(): void
    {
        $order = $this->actingAs($this->user)->postJson('/api/v1/receiving-orders', [
            'location_id' => $this->tienda->id,
            'code' => 'OC-001',
            'lines' => [['product_variant_id' => $this->variant->id, 'expected_qty' => 3]],
        ])->assertStatus(201)->json();

        $tags = $this->codedTags(3);

        $this->actingAs($this->user)
            ->postJson("/api/v1/receiving-orders/{$order['id']}/receive", [
                'epcs' => array_map(fn (Tag $t) => $t->epc, $tags),
                'accept' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('pass.matched', 3)
            ->assertJsonPath('differences.difference_pct', 0);

        $this->assertSame(TagState::EnStock, $tags[0]->refresh()->state);
    }

    public function test_una_diferencia_grande_pide_supervisor_con_su_detalle(): void
    {
        $order = $this->actingAs($this->user)->postJson('/api/v1/receiving-orders', [
            'location_id' => $this->tienda->id,
            'code' => 'OC-002',
            'lines' => [['product_variant_id' => $this->variant->id, 'expected_qty' => 100]],
        ])->json();

        $tags = $this->codedTags(90);

        $this->actingAs($this->user)
            ->postJson("/api/v1/receiving-orders/{$order['id']}/receive", [
                'epcs' => array_map(fn (Tag $t) => $t->epc, $tags),
                'accept' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('title', 'Se requiere confirmación de supervisor')
            ->assertJsonPath('differences.difference_pct', 10);
    }

    // ------------------------------------------------------ transferencias

    public function test_transferir_y_recibir_por_los_endpoints(): void
    {
        $tags = $this->stockedTags(2);
        $epcs = array_map(fn (Tag $t) => $t->epc, $tags);

        $dispatch = $this->actingAs($this->user)->postJson('/api/v1/movements/transfer', [
            'from_location_id' => $this->tienda->id,
            'to_location_id' => $this->otraTienda->id,
            'code' => 'TR-001',
            'action' => 'dispatch',
            'epcs' => $epcs,
        ])->assertStatus(202)->json();

        $this->assertSame(2, $dispatch['dispatched']);
        $this->assertSame(TagState::EnTransito, $tags[0]->refresh()->state);

        $this->actingAs($this->user)->postJson('/api/v1/movements/transfer', [
            'transfer_id' => $dispatch['transfer_id'],
            'action' => 'receive',
            'epcs' => $epcs,
        ])->assertStatus(202)->assertJson(['received' => 2, 'missing' => 0]);

        $this->assertSame($this->otraTienda->id, $tags[0]->refresh()->current_location_id);
    }

    public function test_cambiar_de_zona_por_el_endpoint(): void
    {
        $zona = Zone::create([
            'location_id' => $this->tienda->id,
            'code' => 'SALA', 'name' => 'Sala', 'kind' => 'sala',
        ]);
        $tags = $this->stockedTags(2);

        $this->actingAs($this->user)
            ->postJson('/api/v1/movements/zone-change', [
                'epcs' => array_map(fn (Tag $t) => $t->epc, $tags),
                'to_zone_id' => $zona->id,
            ])
            ->assertStatus(202)
            ->assertJson(['moved' => 2]);

        $this->assertSame($zona->id, $tags[0]->refresh()->current_zone_id);
    }

    // ---------------------------------------------------------------- tags

    public function test_la_ficha_de_prenda_incluye_su_venta(): void
    {
        $tags = $this->stockedTags(1);
        $this->actingAs($this->user)->postJson('/api/v1/sales', [
            'location_id' => $this->tienda->id, 'code' => 'V-004', 'epcs' => [$tags[0]->epc],
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/tags/{$tags[0]->epc}")
            ->assertOk()
            ->assertJsonPath('state', 'vendido')
            ->assertJsonPath('sale.sale_code', 'V-004');
    }

    public function test_el_historial_coincide_con_stock_movements(): void
    {
        $tags = $this->stockedTags(1);
        $this->actingAs($this->user)->postJson('/api/v1/sales', [
            'location_id' => $this->tienda->id, 'code' => 'V-005', 'epcs' => [$tags[0]->epc],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tags/{$tags[0]->epc}/history")
            ->assertOk();

        $this->assertSame(
            DB::table('stock_movements')->where('tag_id', $tags[0]->id)->count(),
            $response->json('meta.total'),
        );
    }

    public function test_sustituir_un_tag_por_el_endpoint(): void
    {
        $viejo = $this->stockedTags(1)[0];
        $nuevo = $this->codedTags(1)[0];

        $this->actingAs($this->user)
            ->postJson("/api/v1/tags/{$viejo->epc}/replace", [
                'new_epc' => $nuevo->epc,
                'reason' => 'arrancado',
            ])
            ->assertStatus(201)
            ->assertJsonPath('old_epc', $viejo->epc);

        $this->assertSame(TagState::Baja, $viejo->refresh()->state);
    }

    public function test_sustituir_por_un_epc_inexistente_da_422(): void
    {
        $viejo = $this->stockedTags(1)[0];

        $this->actingAs($this->user)
            ->postJson("/api/v1/tags/{$viejo->epc}/replace", [
                'new_epc' => '3035D9FFFFFFFFFFFFFFFFFF',
                'reason' => 'arrancado',
            ])
            ->assertStatus(422)
            ->assertJsonPath('detail', 'El EPC sustituto 3035D9FFFFFFFFFFFFFFFFFF no existe. Tárelo primero.');
    }

    // ------------------------------------------------------------ ciclos

    public function test_ciclo_completo_por_la_api(): void
    {
        $tags = $this->stockedTags(4);

        $cycle = $this->actingAs($this->user)->postJson('/api/v1/inventory-cycles', [
            'location_id' => $this->tienda->id,
            'code' => 'INV-API-01',
        ])->assertStatus(201)->assertJsonPath('expected_count', 4)->json();

        // El handheld registra su barrido con token de dispositivo; aquí se
        // usa el servicio para no duplicar la prueba de autenticación.
        app(\App\Services\InventoryCycleService::class)->registerScans(
            \App\Models\InventoryCycle::find($cycle['id']),
            array_map(fn (Tag $t) => ['epc' => $t->epc], array_slice($tags, 0, 3)),
        );

        // 75 % está por debajo del umbral del 90 %, así que cerrar exige
        // justificación escrita (`docs/12` §2).
        \App\Models\InventoryCycle::find($cycle['id'])
            ->update(['notes' => 'Zona de probadores en obra durante el conteo.']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/inventory-cycles/{$cycle['id']}/close")
            ->assertOk()
            ->assertJson(['found' => 3, 'missing' => 1, 'accuracy_pct' => 75.0]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/inventory-cycles/{$cycle['id']}/report")
            ->assertOk()
            ->assertJsonPath('lines.0.expected_qty', 4)
            ->assertJsonPath('lines.0.counted_qty', 3);
    }

    public function test_el_avance_por_zona_delata_la_zona_no_barrida(): void
    {
        $sala = Zone::create([
            'location_id' => $this->tienda->id, 'code' => 'SALA', 'name' => 'Sala', 'kind' => 'sala',
        ]);
        $trastienda = Zone::create([
            'location_id' => $this->tienda->id, 'code' => 'TRAS', 'name' => 'Trastienda', 'kind' => 'trastienda',
        ]);

        $tags = $this->stockedTags(4);
        foreach ([0, 1] as $i) {
            $this->movements->apply(new MovementIntent(
                tagId: $tags[$i]->id, type: MovementType::CambioZona, toZoneId: $sala->id,
            ));
        }
        foreach ([2, 3] as $i) {
            $this->movements->apply(new MovementIntent(
                tagId: $tags[$i]->id, type: MovementType::CambioZona, toZoneId: $trastienda->id,
            ));
        }

        $cycle = $this->actingAs($this->user)->postJson('/api/v1/inventory-cycles', [
            'location_id' => $this->tienda->id, 'code' => 'INV-ZONA-01',
        ])->json();

        // Solo se barre la sala.
        app(\App\Services\InventoryCycleService::class)->registerScans(
            \App\Models\InventoryCycle::find($cycle['id']),
            [['epc' => $tags[0]->epc], ['epc' => $tags[1]->epc]],
        );

        $zones = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory-cycles/{$cycle['id']}/zone-performance")
            ->assertOk()
            ->json('zones');

        $porNombre = collect($zones)->keyBy('zone_name');
        $this->assertSame('100.00', (string) $porNombre['Sala']['accuracy_pct']);
        $this->assertSame('0.00', (string) $porNombre['Trastienda']['accuracy_pct']);
        // La peor barrida sale primera.
        $this->assertSame('Trastienda', $zones[0]['zone_name']);
    }

    public function test_pausar_un_ciclo_cerrado_es_conflicto(): void
    {
        $this->stockedTags(1);
        $cycle = $this->actingAs($this->user)->postJson('/api/v1/inventory-cycles', [
            'location_id' => $this->tienda->id, 'code' => 'INV-API-02',
        ])->json();

        // Sin escaneos la exactitud es 0 %: hace falta justificar el cierre.
        \App\Models\InventoryCycle::find($cycle['id'])
            ->update(['notes' => 'Ciclo anulado por corte de luz.']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/inventory-cycles/{$cycle['id']}/close")
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/v1/inventory-cycles/{$cycle['id']}/pause")
            ->assertStatus(409);
    }

    // ----------------------------------------------------------- alertas

    public function test_la_bandeja_ordena_por_gravedad(): void
    {
        $alerts = new AlertService();
        $alerts->raise(AlertKind::ReposicionSala, organizationId: $this->organization->id);
        $alerts->raise(AlertKind::TidDiscrepante, organizationId: $this->organization->id);

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/alerts?status=abierta')
            ->assertOk()
            ->json('data');

        // La posible clonación va antes que la reposición.
        $this->assertSame('tid_discrepante', $data[0]['kind']);
        $this->assertSame(1, $data[0]['severity']);
    }

    public function test_reconocer_y_resolver_una_alerta(): void
    {
        $alert = (new AlertService())->raise(
            AlertKind::SalidaNoVendida, organizationId: $this->organization->id
        );

        $this->actingAs($this->user)
            ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")
            ->assertOk()->assertJsonPath('status', 'en_revision');

        $this->actingAs($this->user)
            ->postJson("/api/v1/alerts/{$alert->id}/resolve", ['false_positive' => true])
            ->assertOk()->assertJsonPath('status', 'descartada');
    }

    public function test_no_se_reconoce_dos_veces_la_misma_alerta(): void
    {
        $alert = (new AlertService())->raise(
            AlertKind::LectorSinLatido, organizationId: $this->organization->id
        );

        $this->actingAs($this->user)->postJson("/api/v1/alerts/{$alert->id}/acknowledge");

        $this->actingAs($this->user)
            ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")
            ->assertStatus(409);
    }

    public function test_las_rutas_privadas_exigen_sesion(): void
    {
        $this->getJson('/api/v1/tags')->assertUnauthorized();
        $this->getJson('/api/v1/alerts')->assertUnauthorized();
        $this->postJson('/api/v1/sales')->assertUnauthorized();
    }

    // ------------------------------------------------------------ helpers

    /** @return list<Tag> */
    private function codedTags(int $count): array
    {
        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $tags[] = Tag::create([
                'organization_id' => $this->organization->id,
                'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
                'product_variant_id' => $this->variant->id,
                'state' => TagState::Codificado,
            ]);
        }

        return $tags;
    }

    /** @return list<Tag> */
    private function stockedTags(int $count): array
    {
        $tags = $this->codedTags($count);

        foreach ($tags as $tag) {
            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::Tarado,
                toLocationId: $this->tienda->id,
            ));
            $tag->refresh();
        }

        return $tags;
    }
}
