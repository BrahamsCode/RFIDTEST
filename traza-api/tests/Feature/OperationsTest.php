<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Inventory\CycleReconciler;
use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReceivingOrder;
use App\Models\Tag;
use App\Models\User;
use App\Services\InventoryCycleService;
use App\Services\ReceivingService;
use App\Services\SaleService;
use App\Services\StockMovementService;
use App\Services\TagReplacementService;
use App\Services\TransferService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/** Épica 7: recepción, transferencias, ventas y re-etiquetado. */
#[Group('pgsql')]
final class OperationsTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private Location $otraTienda;

    private ProductVariant $polo;

    private ProductVariant $jean;

    private StockMovementService $movements;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE sale_lines, sale_transactions, transfer_tags, transfers,
                       receiving_order_lines, receiving_orders, tag_replacements,
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
        $this->otraTienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-02-{$suffix}", 'name' => 'Gamarra 2',
        ]);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}", 'name' => 'Surtido',
        ]);
        $this->polo = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "POLO-M-{$suffix}",
            'cost_price' => 18.0, 'sale_price' => 49.9,
        ]);
        $this->jean = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "JEAN-30-{$suffix}",
            'cost_price' => 40.0, 'sale_price' => 119.9,
        ]);

        $this->userId = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Jefa de tienda',
            'email' => "jefa-{$suffix}@vivatech-peru.com",
            'password' => 'secreto',
        ])->id;
    }

    // ------------------------------------------------------- 7.1 recepción

    public function test_la_recepcion_da_de_alta_lo_leido(): void
    {
        $order = $this->makeOrder([[$this->polo, 3], [$this->jean, 2]]);
        $tags = array_merge($this->codedTags($this->polo, 3), $this->codedTags($this->jean, 2));

        $result = $this->receiving()->recordPass($order, $this->epcsOf($tags));

        $this->assertSame(5, $result['matched']);

        foreach ($tags as $tag) {
            $tag->refresh();
            $this->assertSame(TagState::EnStock, $tag->state);
            $this->assertSame($this->tienda->id, $tag->current_location_id);
        }
    }

    public function test_repetir_la_pasada_no_duplica_movimientos(): void
    {
        $order = $this->makeOrder([[$this->polo, 2]]);
        $tags = $this->codedTags($this->polo, 2);

        $this->receiving()->recordPass($order, $this->epcsOf($tags));
        $this->receiving()->recordPass($order, $this->epcsOf($tags));

        // Una recepción por prenda, no dos.
        $this->assertSame(2, DB::table('stock_movements')
            ->where('movement_type', 'recepcion')->count());
        $this->assertSame(2, $this->receiving()->differences($order)['received_total']);
    }

    public function test_una_diferencia_pequena_se_acepta_sin_supervisor(): void
    {
        // 1 de menos sobre 100 es un 1 %: por debajo del umbral del 3 %.
        $order = $this->makeOrder([[$this->polo, 100]]);
        $tags = $this->codedTags($this->polo, 99);
        $this->receiving()->recordPass($order, $this->epcsOf($tags));

        $differences = $this->receiving()->differences($order);
        $this->assertSame(1.0, $differences['difference_pct']);
        $this->assertFalse($differences['requires_supervisor']);

        $order = $this->receiving()->accept($order, userId: $this->userId);
        $this->assertSame('recibida_con_diferencia', $order->status);
    }

    public function test_una_diferencia_mayor_al_3_por_ciento_exige_supervisor(): void
    {
        $order = $this->makeOrder([[$this->polo, 100]]);
        $tags = $this->codedTags($this->polo, 95);
        $this->receiving()->recordPass($order, $this->epcsOf($tags));

        $differences = $this->receiving()->differences($order);
        $this->assertSame(5.0, $differences['difference_pct']);
        $this->assertTrue($differences['requires_supervisor']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/supervisor/');
        $this->receiving()->accept($order, userId: $this->userId);
    }

    public function test_el_supervisor_puede_aceptar_la_diferencia(): void
    {
        $order = $this->makeOrder([[$this->polo, 100]]);
        $this->receiving()->recordPass($order, $this->epcsOf($this->codedTags($this->polo, 95)));

        $order = $this->receiving()->accept($order, userId: $this->userId, supervisorApproval: true);

        $this->assertSame('recibida_con_diferencia', $order->status);
        $this->assertNotNull($order->received_at);
    }

    public function test_una_prenda_de_un_sku_ajeno_a_la_orden_no_cuenta(): void
    {
        $order = $this->makeOrder([[$this->polo, 2]]);
        $tags = array_merge($this->codedTags($this->polo, 2), $this->codedTags($this->jean, 1));

        $result = $this->receiving()->recordPass($order, $this->epcsOf($tags));

        $this->assertSame(2, $result['matched']);
        $this->assertSame(1, $result['foreign']);
    }

    // -------------------------------------------------- 7.2 transferencias

    public function test_transferencia_completa_entre_dos_tiendas(): void
    {
        $tags = $this->stockedTags($this->polo, 3, $this->tienda);
        $service = $this->transfers();

        $transfer = $service->create($this->tienda, $this->otraTienda, 'TR-001');
        $dispatched = $service->dispatch($transfer, $this->epcsOf($tags));

        $this->assertSame(3, $dispatched);
        foreach ($tags as $tag) {
            $this->assertSame(TagState::EnTransito, $tag->refresh()->state);
        }

        $result = $service->receive($transfer->refresh(), $this->epcsOf($tags));

        $this->assertSame(['received' => 3, 'missing' => 0, 'unexpected' => 0], $result);
        foreach ($tags as $tag) {
            $tag->refresh();
            $this->assertSame(TagState::EnStock, $tag->state);
            $this->assertSame($this->otraTienda->id, $tag->current_location_id);
        }
        $this->assertSame('recibida', $transfer->refresh()->status);
    }

    public function test_lo_que_no_llega_se_queda_en_transito(): void
    {
        $tags = $this->stockedTags($this->polo, 3, $this->tienda);
        $service = $this->transfers();

        $transfer = $service->create($this->tienda, $this->otraTienda, 'TR-002');
        $service->dispatch($transfer, $this->epcsOf($tags));

        // Solo llegan 2 de las 3.
        $result = $service->receive($transfer->refresh(), [$tags[0]->epc, $tags[1]->epc]);

        $this->assertSame(1, $result['missing']);
        $this->assertSame(TagState::EnTransito, $tags[2]->refresh()->state);
        $this->assertSame('recibida_con_diferencia', $transfer->refresh()->status);
    }

    public function test_alerta_las_transferencias_estancadas_mas_de_7_dias(): void
    {
        $tags = $this->stockedTags($this->polo, 1, $this->tienda);
        $service = $this->transfers();

        $reciente = $service->create($this->tienda, $this->otraTienda, 'TR-003');
        $service->dispatch($reciente, $this->epcsOf($tags));

        $this->assertCount(0, $service->stale());

        // Se retrasa el despacho más allá de la semana.
        $reciente->forceFill(['dispatched_at' => now()->subDays(8)])->save();

        $stale = $service->stale();
        $this->assertCount(1, $stale);
        $this->assertSame('TR-003', $stale->first()->code);
    }

    public function test_no_se_puede_transferir_a_la_misma_tienda(): void
    {
        $this->expectException(RuntimeException::class);
        $this->transfers()->create($this->tienda, $this->tienda, 'TR-004');
    }

    // ------------------------------------------------- 7.3 ventas y devol.

    public function test_una_venta_marca_las_prendas_como_vendidas(): void
    {
        $tags = $this->stockedTags($this->polo, 2, $this->tienda);

        $result = $this->sales()->sell($this->tienda, 'V-001', $this->epcsOf($tags));

        $this->assertSame(2, $result['sold']);
        $this->assertSame('99.8000', $result['sale']->total_amount);
        foreach ($tags as $tag) {
            $tag->refresh();
            $this->assertSame(TagState::Vendido, $tag->state);
            $this->assertNotNull($tag->sold_at);
        }
    }

    public function test_un_epc_desconocido_no_impide_cobrar(): void
    {
        // El cliente lleva una prenda de otra tienda encima.
        $tags = $this->stockedTags($this->polo, 1, $this->tienda);

        $result = $this->sales()->sell($this->tienda, 'V-002', [
            $tags[0]->epc,
            'E28011AABBCCDDEEFF001122',
        ]);

        $this->assertSame(1, $result['sold']);
        $this->assertSame(1, $result['ignored']);
    }

    public function test_una_devolucion_de_un_epc_nunca_vendido_se_rechaza(): void
    {
        $tags = $this->stockedTags($this->polo, 1, $this->tienda);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no consta como vendida/');

        $this->sales()->acceptReturn($this->tienda, $tags[0]->epc, 'D-001');
    }

    public function test_una_devolucion_de_un_epc_ajeno_se_rechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no pertenece a esta organización/');

        $this->sales()->acceptReturn($this->tienda, 'E28011AABBCCDDEEFF001122', 'D-002');
    }

    public function test_una_devolucion_legitima_devuelve_la_prenda_a_stock(): void
    {
        $tags = $this->stockedTags($this->polo, 1, $this->tienda);
        $this->sales()->sell($this->tienda, 'V-003', $this->epcsOf($tags));

        $return = $this->sales()->acceptReturn($this->tienda, $tags[0]->epc, 'D-003');

        $this->assertTrue($return->is_return);
        $this->assertSame('-49.9000', $return->total_amount);

        $tags[0]->refresh();
        $this->assertSame(TagState::EnStock, $tags[0]->state);
        $this->assertSame($this->tienda->id, $tags[0]->current_location_id);
    }

    public function test_no_se_puede_devolver_dos_veces_la_misma_prenda(): void
    {
        $tags = $this->stockedTags($this->polo, 1, $this->tienda);
        $this->sales()->sell($this->tienda, 'V-004', $this->epcsOf($tags));
        $this->sales()->acceptReturn($this->tienda, $tags[0]->epc, 'D-004');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no "vendido"/');

        $this->sales()->acceptReturn($this->tienda, $tags[0]->epc, 'D-005');
    }

    public function test_muestra_el_detalle_de_la_venta_original(): void
    {
        $tags = $this->stockedTags($this->polo, 1, $this->tienda);
        $this->sales()->sell($this->tienda, 'V-006', $this->epcsOf($tags), externalRef: 'B001-123');

        $info = $this->sales()->saleInfoFor($tags[0]->refresh());

        $this->assertSame('V-006', $info['sale_code']);
        $this->assertSame('B001-123', $info['external_ref']);
        $this->assertSame('49.9000', $info['unit_price']);
    }

    // ------------------------------------------------- 7.4 re-etiquetado

    public function test_una_prenda_reetiquetada_no_se_cuenta_dos_veces(): void
    {
        $viejo = $this->stockedTags($this->polo, 1, $this->tienda)[0];
        $nuevo = $this->codedTags($this->polo, 1)[0];

        $this->replacements()->replace($viejo, $nuevo, 'arrancado');

        $viejo->refresh();
        $nuevo->refresh();
        $this->assertSame(TagState::Baja, $viejo->state, 'El tag viejo sale del inventario.');
        $this->assertSame(TagState::EnStock, $nuevo->state);
        $this->assertSame($viejo->id, $nuevo->replaces_tag_id);

        // Lo que importa: el ciclo siguiente espera una prenda, no dos.
        $cycle = (new InventoryCycleService)->create($this->tienda, 'INV-RE-01');
        $this->assertSame(1, $cycle->expected_count);
    }

    public function test_el_nuevo_tag_hereda_la_ubicacion_del_viejo(): void
    {
        $viejo = $this->stockedTags($this->polo, 1, $this->otraTienda)[0];
        $nuevo = $this->codedTags($this->polo, 1)[0];

        $this->replacements()->replace($viejo, $nuevo, 'ilegible');

        $this->assertSame($this->otraTienda->id, $nuevo->refresh()->current_location_id);
    }

    public function test_un_epc_no_puede_sustituir_a_dos_prendas(): void
    {
        $viejo1 = $this->stockedTags($this->polo, 1, $this->tienda)[0];
        $viejo2 = $this->stockedTags($this->polo, 1, $this->tienda)[0];
        $nuevo = $this->codedTags($this->polo, 1)[0];

        $this->replacements()->replace($viejo1, $nuevo, 'arrancado');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya se usó como sustituto/');
        $this->replacements()->replace($viejo2, $nuevo, 'arrancado');
    }

    public function test_queda_registrada_la_sustitucion(): void
    {
        $viejo = $this->stockedTags($this->polo, 1, $this->tienda)[0];
        $nuevo = $this->codedTags($this->polo, 1)[0];

        $replacement = $this->replacements()->replace($viejo, $nuevo, 'arrancado', userId: null);

        $this->assertSame($viejo->id, $replacement->old_tag_id);
        $this->assertSame($nuevo->id, $replacement->new_tag_id);
        $this->assertSame('arrancado', $replacement->reason);
    }

    // ------------------------------------------------- flujo E2E combinado

    public function test_flujo_completo_recepcion_venta_devolucion_y_ciclo(): void
    {
        // Llega mercadería.
        $order = $this->makeOrder([[$this->polo, 3]]);
        $tags = $this->codedTags($this->polo, 3);
        $this->receiving()->recordPass($order, $this->epcsOf($tags));
        $this->receiving()->accept($order, userId: $this->userId);

        // Se vende una y se devuelve.
        $this->sales()->sell($this->tienda, 'V-E2E', [$tags[0]->epc]);
        $this->sales()->acceptReturn($this->tienda, $tags[0]->epc, 'D-E2E');

        // Se hace inventario y se barren las 3.
        $cycles = new InventoryCycleService;
        $cycle = $cycles->create($this->tienda, 'INV-E2E');
        $this->assertSame(3, $cycle->expected_count);

        $cycles->registerScans($cycle, array_map(
            fn (Tag $t) => ['epc' => $t->epc], $tags
        ));

        $result = (new CycleReconciler($this->movements))->reconcile($cycle);

        $this->assertSame(3, $result->found);
        $this->assertSame(0, $result->missing);
        $this->assertSame(100.0, $cycle->refresh()->accuracy_pct);
    }

    // ------------------------------------------------------------- helpers

    private function receiving(): ReceivingService
    {
        return new ReceivingService($this->movements);
    }

    private function transfers(): TransferService
    {
        return new TransferService($this->movements);
    }

    private function sales(): SaleService
    {
        return new SaleService($this->movements);
    }

    private function replacements(): TagReplacementService
    {
        return new TagReplacementService($this->movements);
    }

    /** @param list<array{0: ProductVariant, 1: int}> $lines */
    private function makeOrder(array $lines): ReceivingOrder
    {
        $order = ReceivingOrder::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'OC-'.uniqid(),
            'status' => 'pendiente',
        ]);

        foreach ($lines as [$variant, $qty]) {
            $order->lines()->create([
                'product_variant_id' => $variant->id,
                'expected_qty' => $qty,
                'unit_cost' => $variant->cost_price,
            ]);
        }

        return $order;
    }

    /** @return list<Tag> */
    private function codedTags(ProductVariant $variant, int $count): array
    {
        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $tags[] = Tag::create([
                'organization_id' => $this->organization->id,
                'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
                'product_variant_id' => $variant->id,
                'state' => TagState::Codificado,
            ]);
        }

        return $tags;
    }

    /** @return list<Tag> */
    private function stockedTags(ProductVariant $variant, int $count, Location $location): array
    {
        $tags = $this->codedTags($variant, $count);

        foreach ($tags as $tag) {
            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::Tarado,
                toLocationId: $location->id,
            ));
            $tag->refresh();
        }

        return $tags;
    }

    /** @param list<Tag> $tags @return list<string> */
    private function epcsOf(array $tags): array
    {
        return array_map(fn (Tag $t) => $t->epc, $tags);
    }
}
