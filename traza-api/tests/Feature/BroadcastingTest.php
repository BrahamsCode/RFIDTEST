<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasting\ChannelAccess;
use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\RoleCode;
use App\Enums\TagState;
use App\Events\InventoryCycleProgressed;
use App\Events\PortalAlarmRaised;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Services\InventoryCycleService;
use App\Services\StockMovementService;
use Database\Seeders\RoleSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Tarea 3.4: difusión de progreso y autorización de canales. */
#[Group('pgsql')]
final class BroadcastingTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private Location $otraTienda;

    private ProductVariant $variant;

    private StockMovementService $movements;

    private InventoryCycleService $cycles;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE role_user, roles, inventory_cycle_scans,
                       inventory_cycle_expected, inventory_cycles,
                       stock_movements, tags, users RESTART IDENTITY CASCADE');
        (new RoleSeeder)->run();

        $this->movements = new StockMovementService(new TagStateMachine);
        $this->cycles = new InventoryCycleService;

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
        ]);
    }

    public function test_registrar_escaneos_difunde_el_avance(): void
    {
        Event::fake([InventoryCycleProgressed::class]);

        $tags = $this->stockTags(4);
        $cycle = $this->cycles->create($this->tienda, 'INV-RT-01');

        $this->cycles->registerScans($cycle, [
            ['epc' => $tags[0]->epc],
            ['epc' => $tags[1]->epc],
        ]);

        Event::assertDispatched(
            InventoryCycleProgressed::class,
            fn (InventoryCycleProgressed $e) => $e->cycleId === $cycle->id
                && $e->scanned === 2
                && $e->expected === 4,
        );
    }

    public function test_difunde_un_evento_por_lote_y_no_uno_por_epc(): void
    {
        // En un barrido de 20 000 prendas, un evento por lectura serían
        // 20 000 eventos y el navegador no daría abasto.
        Event::fake([InventoryCycleProgressed::class]);

        $tags = $this->stockTags(10);
        $cycle = $this->cycles->create($this->tienda, 'INV-RT-02');

        $this->cycles->registerScans(
            $cycle,
            array_map(fn (Tag $t) => ['epc' => $t->epc], $tags),
        );

        Event::assertDispatchedTimes(InventoryCycleProgressed::class, 1);
    }

    public function test_el_evento_lleva_el_porcentaje_calculado(): void
    {
        $event = new InventoryCycleProgressed(cycleId: 1, scanned: 3, expected: 4);

        $this->assertSame([
            'scanned' => 3,
            'expected' => 4,
            'progress' => 75.0,
            'zone_id' => null,
        ], $event->broadcastWith());
    }

    public function test_el_evento_no_divide_entre_cero(): void
    {
        $event = new InventoryCycleProgressed(cycleId: 1, scanned: 0, expected: 0);

        $this->assertSame(0, $event->broadcastWith()['progress']);
    }

    public function test_el_avance_va_por_un_canal_privado_del_ciclo(): void
    {
        $channel = (new InventoryCycleProgressed(cycleId: 42, scanned: 1, expected: 2))
            ->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-inventory-cycle.42', $channel->name);
    }

    public function test_la_alarma_de_portal_va_por_el_canal_de_la_tienda(): void
    {
        $channel = (new PortalAlarmRaised(
            locationId: 7, epc: '3035D9000000000000000001', confidence: 0.92,
        ))->broadcastOn();

        $this->assertSame('private-location.7.alerts', $channel->name);
    }

    // ------------------------------------------ autorización de canales

    public function test_un_usuario_de_tienda_escucha_el_ciclo_de_su_tienda(): void
    {
        $cycle = $this->cycles->create($this->tienda, 'INV-RT-03');
        $vendedor = $this->userWith(RoleCode::Vendedor, $this->tienda);

        $this->assertTrue($this->authorizes($vendedor, "inventory-cycle.{$cycle->id}"));
    }

    public function test_un_usuario_no_escucha_el_ciclo_de_otra_tienda(): void
    {
        // Sin esto, cualquier usuario autenticado vería el avance de todas
        // las tiendas.
        $cycle = $this->cycles->create($this->otraTienda, 'INV-RT-04');
        $vendedor = $this->userWith(RoleCode::Vendedor, $this->tienda);

        $this->assertFalse($this->authorizes($vendedor, "inventory-cycle.{$cycle->id}"));
    }

    public function test_un_supervisor_escucha_cualquier_tienda_de_su_organizacion(): void
    {
        $cycle = $this->cycles->create($this->otraTienda, 'INV-RT-05');
        $supervisor = $this->userWith(RoleCode::SupervisorRegional, $this->tienda);

        $this->assertTrue($this->authorizes($supervisor, "inventory-cycle.{$cycle->id}"));
    }

    public function test_las_alarmas_de_portal_solo_las_oye_quien_tiene_esa_tienda(): void
    {
        $vendedor = $this->userWith(RoleCode::Vendedor, $this->tienda);

        $this->assertTrue($this->authorizes($vendedor, "location.{$this->tienda->id}.alerts"));
        $this->assertFalse($this->authorizes($vendedor, "location.{$this->otraTienda->id}.alerts"));
    }

    public function test_el_canal_de_dispositivos_exige_permiso_de_tecnico(): void
    {
        $canal = "org.{$this->organization->id}.devices";

        $this->assertTrue($this->authorizes($this->userWith(RoleCode::Tecnico), $canal));
        $this->assertFalse($this->authorizes($this->userWith(RoleCode::Vendedor), $canal));
    }

    public function test_un_ciclo_inexistente_no_autoriza(): void
    {
        $vendedor = $this->userWith(RoleCode::Vendedor, $this->tienda);

        $this->assertFalse($this->authorizes($vendedor, 'inventory-cycle.999999'));
    }

    // ------------------------------------------------------------ helpers

    /**
     * Evalúa la autorización del canal igual que hará Reverb, pero sin pasar
     * por la fontanería HTTP de difusión: ahí un fallo de sesión se confunde
     * con un fallo de permisos, y las pruebas de denegación pasarían por el
     * motivo equivocado.
     */
    private function authorizes(User $user, string $channel): bool
    {
        if (preg_match('/^inventory-cycle\.(\d+)$/', $channel, $m) === 1) {
            return ChannelAccess::inventoryCycle($user, $m[1]);
        }

        if (preg_match('/^location\.(\d+)\.alerts$/', $channel, $m) === 1) {
            return ChannelAccess::locationAlerts($user, $m[1]);
        }

        if (preg_match('/^org\.(\d+)\.devices$/', $channel, $m) === 1) {
            return ChannelAccess::organizationDevices($user, $m[1]);
        }

        throw new \RuntimeException("Canal no contemplado en la prueba: {$channel}.");
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

    /** @return list<Tag> */
    private function stockTags(int $count): array
    {
        $tags = [];

        for ($i = 0; $i < $count; $i++) {
            $tag = Tag::create([
                'organization_id' => $this->organization->id,
                'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
                'product_variant_id' => $this->variant->id,
                'state' => TagState::Codificado,
            ]);

            $this->movements->apply(new MovementIntent(
                tagId: $tag->id, type: MovementType::Tarado, toLocationId: $this->tienda->id,
            ));

            $tags[] = $tag->refresh();
        }

        return $tags;
    }
}
