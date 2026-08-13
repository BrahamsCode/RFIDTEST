<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\RoleCode;
use App\Enums\TagState;
use App\Models\Device;
use App\Models\InventoryCycle;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Policies\InventoryCyclePolicy;
use App\Policies\StockAdjustmentPolicy;
use App\Services\InventoryCycleService;
use App\Services\StockMovementService;
use App\Services\TagAccessPasswordService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/** Épica 9: roles, auditoría y clave de acceso. */
#[Group('pgsql')]
final class SecurityTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private Location $otraTienda;

    private ProductVariant $variant;

    private StockMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE audit_logs, role_user, roles, devices,
                       inventory_cycle_results, inventory_cycle_scans,
                       inventory_cycle_expected, inventory_cycles,
                       stock_movements, tags, users RESTART IDENTITY CASCADE');

        (new RoleSeeder)->run();

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
            'code' => "P-{$suffix}", 'name' => 'Polo',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "SKU-{$suffix}", 'cost_price' => 20.0,
        ]);
    }

    // --------------------------------------------------------- 9.1 roles

    public function test_estan_los_7_roles_documentados(): void
    {
        $this->assertCount(7, RoleCode::cases());
        $this->assertSame(7, Role::count());
    }

    /** @return array<string, array{RoleCode, bool}> */
    public static function stockAdjustmentByRole(): array
    {
        return [
            'vendedor no ajusta' => [RoleCode::Vendedor, false],
            'almacen no ajusta' => [RoleCode::Almacen, false],
            'jefe de tienda sí ajusta' => [RoleCode::JefeTienda, true],
            'supervisor regional sí ajusta' => [RoleCode::SupervisorRegional, true],
            'gerencia no ajusta' => [RoleCode::Gerencia, false],
            'técnico NO ajusta' => [RoleCode::Tecnico, false],
            'admin sí ajusta' => [RoleCode::Admin, true],
        ];
    }

    #[DataProvider('stockAdjustmentByRole')]
    public function test_permiso_de_ajuste_de_stock_por_rol(RoleCode $role, bool $allowed): void
    {
        $user = $this->userWith($role);
        $policy = new StockAdjustmentPolicy;

        $this->assertSame(
            $allowed,
            $policy->apply($user, MovementType::AjusteNegativo)->allowed(),
            "Rol {$role->value}.",
        );
    }

    public function test_el_tecnico_gestiona_dispositivos_pero_no_toca_stock(): void
    {
        // La separación que impide que un mismo actor provoque un fallo de
        // lectura y luego justifique la desaparición resultante.
        $tecnico = $this->userWith(RoleCode::Tecnico);

        $this->assertTrue($tecnico->hasPermission('device.read-profile'));
        $this->assertFalse($tecnico->hasPermission('adjustment.approve'));
    }

    public function test_el_jefe_de_tienda_declara_merma_pero_no_toca_lectores(): void
    {
        $jefe = $this->userWith(RoleCode::JefeTienda);

        $this->assertTrue($jefe->hasPermission('adjustment.approve'));
        $this->assertFalse($jefe->hasPermission('device.read-profile'));
    }

    public function test_gerencia_es_solo_lectura(): void
    {
        $gerencia = $this->userWith(RoleCode::Gerencia);

        $this->assertTrue($gerencia->hasPermission('report.view'));
        $this->assertFalse($gerencia->hasPermission('sale.create'));
        $this->assertFalse($gerencia->hasPermission('cycle.close'));
    }

    public function test_un_usuario_de_tienda_no_ve_otra_tienda(): void
    {
        $vendedor = $this->userWith(RoleCode::Vendedor, $this->tienda);

        $this->assertTrue($vendedor->canAccessLocation($this->tienda->id));
        $this->assertFalse($vendedor->canAccessLocation($this->otraTienda->id));
    }

    public function test_un_supervisor_regional_ve_todas_las_de_su_organizacion(): void
    {
        $supervisor = $this->userWith(RoleCode::SupervisorRegional, $this->tienda);

        $this->assertTrue($supervisor->canAccessLocation($this->otraTienda->id));
    }

    public function test_nadie_ve_las_tiendas_de_otra_organizacion(): void
    {
        $ajena = Organization::create(['name' => 'Otra empresa']);
        $tiendaAjena = Location::create([
            'organization_id' => $ajena->id, 'code' => 'X-01', 'name' => 'Ajena',
        ]);

        $admin = $this->userWith(RoleCode::Admin, $this->tienda);

        $this->assertFalse($admin->canAccessLocation($tiendaAjena->id));
    }

    // ------------------------------------------------- doble aprobación

    public function test_un_ajuste_de_mas_de_20_unidades_exige_supervisor_regional(): void
    {
        $policy = new StockAdjustmentPolicy;
        $jefe = $this->userWith(RoleCode::JefeTienda);
        $supervisor = $this->userWith(RoleCode::SupervisorRegional);

        $this->assertTrue($policy->apply($jefe, MovementType::AjusteNegativo, units: 20)->allowed());

        $denial = $policy->apply($jefe, MovementType::AjusteNegativo, units: 21);
        $this->assertTrue($denial->denied());
        $this->assertStringContainsString('supervisor regional', $denial->message());

        $this->assertTrue($policy->apply($supervisor, MovementType::AjusteNegativo, units: 21)->allowed());
    }

    public function test_una_merma_de_mas_de_2000_soles_exige_supervisor_regional(): void
    {
        $policy = new StockAdjustmentPolicy;
        $jefe = $this->userWith(RoleCode::JefeTienda);

        $this->assertTrue($policy->apply($jefe, MovementType::Merma, value: 2000.0)->allowed());
        $this->assertTrue($policy->apply($jefe, MovementType::Merma, value: 2000.01)->denied());
    }

    public function test_anular_un_lote_tarado_solo_lo_hace_un_admin(): void
    {
        $policy = new StockAdjustmentPolicy;

        $this->assertTrue($policy->voidTagBatch($this->userWith(RoleCode::Admin))->allowed());
        $this->assertTrue($policy->voidTagBatch($this->userWith(RoleCode::SupervisorRegional))->denied());
    }

    public function test_cambiar_la_mascara_epc_exige_admin_y_motivo_escrito(): void
    {
        $policy = new StockAdjustmentPolicy;
        $admin = $this->userWith(RoleCode::Admin);

        $this->assertTrue($policy->changeEpcMask($admin, 'Migración a nuevo prefijo GS1')->allowed());
        $this->assertTrue($policy->changeEpcMask($admin, null)->denied());
        $this->assertTrue($policy->changeEpcMask($this->userWith(RoleCode::JefeTienda), 'motivo')->denied());
    }

    // ------------------------------------------------ cierre de ciclo

    public function test_un_vendedor_no_puede_cerrar_un_ciclo(): void
    {
        $cycle = $this->cycleWithScans(scanned: 10, total: 10);

        $this->actingAs($this->userWith(RoleCode::Vendedor, $this->tienda))
            ->postJson("/api/v1/inventory-cycles/{$cycle->id}/close")
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Solo el jefe de tienda puede cerrar un ciclo.');
    }

    public function test_un_ciclo_con_exactitud_baja_no_se_cierra_sin_justificacion(): void
    {
        // 5 de 20: casi seguro que faltó barrer una zona. Cerrarlo generaría
        // merma falsa.
        $cycle = $this->cycleWithScans(scanned: 5, total: 20);

        $this->actingAs($this->userWith(RoleCode::JefeTienda, $this->tienda))
            ->postJson("/api/v1/inventory-cycles/{$cycle->id}/close")
            ->assertStatus(403)
            ->assertJsonPath('title', 'Acceso denegado');

        $this->assertStringContainsString(
            'justificación',
            (new InventoryCyclePolicy)
                ->close($this->userWith(RoleCode::JefeTienda, $this->tienda), $cycle)
                ->message(),
        );
    }

    public function test_con_justificacion_escrita_sí_se_cierra(): void
    {
        $cycle = $this->cycleWithScans(scanned: 5, total: 20);
        $cycle->update(['notes' => 'Inundación en trastienda: zona inaccesible.']);

        $this->actingAs($this->userWith(RoleCode::JefeTienda, $this->tienda))
            ->postJson("/api/v1/inventory-cycles/{$cycle->id}/close")
            ->assertOk();
    }

    public function test_una_exactitud_alta_se_cierra_sin_justificacion(): void
    {
        $cycle = $this->cycleWithScans(scanned: 19, total: 20);

        $this->actingAs($this->userWith(RoleCode::JefeTienda, $this->tienda))
            ->postJson("/api/v1/inventory-cycles/{$cycle->id}/close")
            ->assertOk();
    }

    public function test_un_jefe_no_cierra_el_ciclo_de_otra_tienda(): void
    {
        $cycle = $this->cycleWithScans(scanned: 10, total: 10);
        $jefeAjeno = $this->userWith(RoleCode::JefeTienda, $this->otraTienda);

        $this->actingAs($jefeAjeno)
            ->postJson("/api/v1/inventory-cycles/{$cycle->id}/close")
            ->assertStatus(403)
            ->assertJsonPath('detail', 'No tienes acceso a esta tienda.');
    }

    // ----------------------------------------------------- 9.2 auditoría

    public function test_todo_ajuste_manual_queda_registrado_con_usuario_e_ip(): void
    {
        $user = $this->userWith(RoleCode::JefeTienda, $this->tienda);
        $this->actingAs($user);

        $tag = $this->stockedTag();
        $this->movements->apply(new MovementIntent(
            tagId: $tag->id, type: MovementType::AjusteNegativo, toLocationId: $this->tienda->id,
        ));

        $log = DB::table('audit_logs')
            ->where('subject_type', Tag::class)
            ->where('subject_id', $tag->id)
            ->where('action', 'Tag.updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'El cambio de estado del tag debe quedar auditado.');
        $this->assertSame($user->id, $log->user_id);

        $changes = json_decode($log->changes, true);
        $this->assertSame('no_visto', $changes['after']['state']);
    }

    public function test_la_auditoria_no_guarda_contrasenas(): void
    {
        $this->actingAs($this->userWith(RoleCode::Admin, $this->tienda));

        Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'EDGE-AUD', 'name' => 'Borde', 'kind' => 'edge',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
            'api_token_hash' => Hash::make('un-token-secreto'),
        ]);

        $log = DB::table('audit_logs')->where('subject_type', Device::class)->latest('id')->first();
        $changes = json_decode($log->changes, true);

        $this->assertSame('«omitido»', $changes['after']['api_token_hash']);
        $this->assertStringNotContainsString('un-token-secreto', $log->changes);
    }

    public function test_un_cambio_que_solo_toca_updated_at_no_genera_ruido(): void
    {
        $this->actingAs($this->userWith(RoleCode::Admin, $this->tienda));
        $tag = $this->stockedTag();

        $antes = DB::table('audit_logs')->count();
        $tag->touch();

        $this->assertSame($antes, DB::table('audit_logs')->count());
    }

    // --------------------------------------------- 9.3 access password

    public function test_la_contrasena_se_deriva_y_es_estable(): void
    {
        config()->set('traza.epc.access_master_key', str_repeat('a1', 32));
        $service = new TagAccessPasswordService;

        $epc = '3035D919080C0E403B9ACA2A';

        $this->assertSame($service->for($epc), $service->for($epc));
        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $service->for($epc));
    }

    public function test_conocer_una_contrasena_no_revela_otra(): void
    {
        config()->set('traza.epc.access_master_key', str_repeat('a1', 32));
        $service = new TagAccessPasswordService;

        $this->assertNotSame(
            $service->for('3035D919080C0E403B9ACA2A'),
            $service->for('3035D919080C0E403B9ACA2B'),
        );
    }

    public function test_el_kill_password_es_distinto_del_de_acceso(): void
    {
        // Un kill password igual al de acceso, o el de fábrica, permite a
        // cualquiera desactivar la etiqueta de forma irreversible.
        config()->set('traza.epc.access_master_key', str_repeat('a1', 32));
        $service = new TagAccessPasswordService;

        $epc = '3035D919080C0E403B9ACA2A';
        $this->assertNotSame($service->for($epc), $service->killPasswordFor($epc));
        $this->assertNotSame('00000000', $service->for($epc));
    }

    public function test_cambiar_la_clave_maestra_cambia_todas_las_contrasenas(): void
    {
        $service = new TagAccessPasswordService;
        $epc = '3035D919080C0E403B9ACA2A';

        config()->set('traza.epc.access_master_key', str_repeat('a1', 32));
        $conClaveA = $service->for($epc);

        config()->set('traza.epc.access_master_key', str_repeat('b2', 32));
        $this->assertNotSame($conClaveA, $service->for($epc));
    }

    public function test_sin_clave_maestra_falla_con_un_mensaje_util(): void
    {
        config()->set('traza.epc.access_master_key', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/TRAZA_TAG_ACCESS_MASTER_KEY/');
        (new TagAccessPasswordService)->for('3035D919080C0E403B9ACA2A');
    }

    public function test_el_handheld_obtiene_la_contrasena_de_un_epc_suyo(): void
    {
        config()->set('traza.epc.access_master_key', str_repeat('a1', 32));

        $device = $this->handheld();
        $tag = $this->stockedTag();

        $this->withHeaders(['X-Device-Token' => 'token-hh', 'X-Device-Code' => $device->code])
            ->getJson("/api/v1/tags/{$tag->epc}/access-password")
            ->assertOk()
            ->assertJsonPath('epc', $tag->epc)
            ->assertJsonStructure(['access_password', 'kill_password']);
    }

    public function test_un_dispositivo_no_obtiene_contrasenas_de_otra_organizacion(): void
    {
        config()->set('traza.epc.access_master_key', str_repeat('a1', 32));

        $device = $this->handheld();
        $ajena = Organization::create(['name' => 'Otra empresa']);
        $tagAjeno = Tag::create([
            'organization_id' => $ajena->id,
            'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
            'state' => TagState::Codificado,
        ]);

        $this->withHeaders(['X-Device-Token' => 'token-hh', 'X-Device-Code' => $device->code])
            ->getJson("/api/v1/tags/{$tagAjeno->epc}/access-password")
            ->assertStatus(403);
    }

    public function test_sin_token_de_dispositivo_no_hay_contrasena(): void
    {
        $tag = $this->stockedTag();

        $this->getJson("/api/v1/tags/{$tag->epc}/access-password")->assertUnauthorized();
    }

    // ------------------------------------------------ 9.5 endurecimiento

    public function test_las_respuestas_llevan_cabeceras_de_seguridad(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_hsts_solo_se_envia_bajo_tls(): void
    {
        // Enviarlo por HTTP no protege y en desarrollo dejaría el dominio
        // local clavado en https durante un año.
        $this->assertFalse(
            $this->getJson('/api/v1/health')->headers->has('Strict-Transport-Security')
        );
    }

    // ------------------------------------------------------------ helpers

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

    private function handheld(): Device
    {
        return Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'HH-'.uniqid(), 'name' => 'Handheld', 'kind' => 'handheld',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
            'api_token_hash' => Hash::make('token-hh'),
        ]);
    }

    private function stockedTag(): Tag
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

    private function cycleWithScans(int $scanned, int $total): InventoryCycle
    {
        $tags = [];
        for ($i = 0; $i < $total; $i++) {
            $tags[] = $this->stockedTag();
        }

        $cycles = new InventoryCycleService;
        $cycle = $cycles->create($this->tienda, 'INV-SEC-'.uniqid());

        $cycles->registerScans($cycle, array_map(
            fn (Tag $t) => ['epc' => $t->epc],
            array_slice($tags, 0, $scanned),
        ));

        return $cycle->refresh();
    }
}
