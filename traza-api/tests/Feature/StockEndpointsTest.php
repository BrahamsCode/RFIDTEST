<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\RoleCode;
use App\Enums\TagState;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Models\Zone;
use App\Services\StockMovementService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Endpoints de stock sobre las vistas analíticas. Base de la épica 4. */
#[Group('pgsql')]
final class StockEndpointsTest extends TestCase
{
    private Location $tienda;

    private Zone $sala;

    private Zone $trastienda;

    private ProductVariant $variant;

    private User $user;

    private StockMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE alerts, role_user, roles, stock_movements, tags, users
                       RESTART IDENTITY CASCADE');
        (new RoleSeeder)->run();

        $this->movements = new StockMovementService(new TagStateMachine);

        $suffix = uniqid();
        $organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->tienda = Location::create([
            'organization_id' => $organization->id,
            'code' => "LIM-{$suffix}", 'name' => 'Gamarra 1',
        ]);
        $this->sala = Zone::create([
            'location_id' => $this->tienda->id, 'code' => 'SALA',
            'name' => 'Sala', 'kind' => 'sala', 'counts_as_sellable' => true,
        ]);
        $this->trastienda = Zone::create([
            'location_id' => $this->tienda->id, 'code' => 'TRAS',
            'name' => 'Trastienda', 'kind' => 'trastienda', 'counts_as_sellable' => false,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'code' => "P-{$suffix}", 'name' => 'Polo oversize',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "SKU-{$suffix}",
            'cost_price' => 20.0, 'sale_price' => 50.0, 'min_stock' => 2,
        ]);

        $this->user = User::create([
            'organization_id' => $organization->id,
            'name' => 'Jefa', 'email' => "jefa-{$suffix}@vivatech-peru.com",
            'password' => 'secreto', 'default_location_id' => $this->tienda->id,
        ]);
        $this->user->roles()->attach(Role::where('code', RoleCode::JefeTienda->value)->value('id'));
        $this->user = $this->user->fresh();
    }

    public function test_el_listado_de_stock_distingue_sala_de_trastienda(): void
    {
        $this->stockTags(3, $this->sala);
        $this->stockTags(2, $this->trastienda);

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/stock')
            ->assertOk()
            ->json('data');

        $this->assertSame(5, array_sum(array_column($data, 'quantity')));
        $this->assertSame(3, array_sum(array_column($data, 'sellable_quantity')));
    }

    public function test_el_listado_filtra_por_sku(): void
    {
        $this->stockTags(2, $this->sala);

        $this->actingAs($this->user)
            ->getJson('/api/v1/stock?search='.$this->variant->sku)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->user)
            ->getJson('/api/v1/stock?search=NO-EXISTE')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_la_valorizacion_suma_coste_y_venta(): void
    {
        $this->stockTags(4, $this->sala);

        $totals = $this->actingAs($this->user)
            ->getJson('/api/v1/stock/valuation')
            ->assertOk()
            ->json('totals');

        // JSON no distingue 80 de 80.0, así que se compara el valor numérico.
        $this->assertSame(4, $totals['units']);
        $this->assertSame(80.0, (float) $totals['cost_value']);
        $this->assertSame(200.0, (float) $totals['retail_value']);
    }

    public function test_la_antiguedad_devuelve_siempre_los_cinco_tramos(): void
    {
        $this->stockTags(1, $this->sala);

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/stock/aging')
            ->assertOk()
            ->json('data');

        // Una gráfica con tramos que aparecen y desaparecen es ilegible.
        $this->assertSame(
            ['0-30', '31-60', '61-90', '91-180', '180+'],
            array_column($data, 'age_bucket'),
        );
    }

    public function test_la_reposicion_lista_lo_que_falta_en_sala(): void
    {
        // min_stock es 2: con 1 en sala y 5 en trastienda, hay que reponer.
        $this->stockTags(1, $this->sala);
        $this->stockTags(5, $this->trastienda);

        $this->actingAs($this->user)
            ->getJson('/api/v1/stock/replenishment')
            ->assertOk()
            ->assertJsonPath('data.0.on_floor', 1)
            ->assertJsonPath('data.0.in_back', 5);
    }

    public function test_el_resumen_da_los_cuatro_numeros_del_panel(): void
    {
        $this->stockTags(3, $this->sala);
        $this->stockTags(2, $this->trastienda);

        $this->actingAs($this->user)
            ->getJson('/api/v1/stock/summary')
            ->assertOk()
            ->assertJsonPath('units', 5)
            ->assertJsonPath('sellable_units', 3)
            ->assertJsonPath('open_alerts', 0)
            ->assertJsonStructure(['last_accuracy_pct', 'replenishment_needed']);
    }

    public function test_el_stock_requiere_sesion(): void
    {
        $this->getJson('/api/v1/stock')->assertUnauthorized();
        $this->getJson('/api/v1/stock/summary')->assertUnauthorized();
    }

    /** @return list<Tag> */
    private function stockTags(int $count, Zone $zone): array
    {
        $tags = [];

        for ($i = 0; $i < $count; $i++) {
            $tag = Tag::create([
                'organization_id' => $this->tienda->organization_id,
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
