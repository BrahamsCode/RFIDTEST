<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Devices\DeviceToken;
use App\Enums\RoleCode;
use App\Models\Device;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\DeviceEnrollmentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Tarea 5.6: alta de un handheld por QR. Ver `docs/09` §10. */
#[Group('pgsql')]
final class DeviceEnrollmentTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private Device $handheld;

    private DeviceEnrollmentService $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE role_user, roles, users RESTART IDENTITY CASCADE');
        (new RoleSeeder)->run();
        Cache::flush();

        $this->enrollment = app(DeviceEnrollmentService::class);

        $suffix = uniqid();
        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->tienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-{$suffix}", 'name' => 'Gamarra 1',
        ]);
        $this->handheld = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => "HH-{$suffix}",
            'name' => 'Handheld 1',
            'kind' => 'handheld',
            'regulatory_region' => 'FCC-PE',
            'status' => 'inactivo',
        ]);
    }

    // ------------------------------------------------------- el servicio

    public function test_el_qr_lleva_los_cinco_campos_del_documento(): void
    {
        $issued = $this->enrollment->issue($this->handheld, 'https://traza.ejemplo.pe');

        $this->assertSame([
            'v', 'url', 'device_code', 'enrollment_token', 'location_id',
        ], array_keys($issued['payload']));

        $this->assertSame(1, $issued['payload']['v']);
        $this->assertSame($this->handheld->code, $issued['payload']['device_code']);
        $this->assertSame($this->tienda->id, $issued['payload']['location_id']);
    }

    public function test_el_token_caduca_a_los_quince_minutos(): void
    {
        $issued = $this->enrollment->issue($this->handheld);

        $this->assertEqualsWithDelta(
            15 * 60,
            now()->diffInSeconds($issued['expires_at']),
            2,
        );
    }

    public function test_el_token_caducado_ya_no_sirve(): void
    {
        // Un token de alta que no caduca es una puerta abierta permanente al
        // API, y el QR acaba fotografiado o impreso encima del mostrador.
        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $this->travel(16)->minutes();

        $this->assertNull($this->enrollment->redeem($this->handheld->code, $token));
    }

    public function test_el_token_es_de_un_solo_uso(): void
    {
        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $primero = $this->enrollment->redeem($this->handheld->code, $token);
        $segundo = $this->enrollment->redeem($this->handheld->code, $token);

        $this->assertNotNull($primero);
        $this->assertNull($segundo, 'El segundo canje debe fallar: dos equipos con el mismo código.');
    }

    public function test_el_canje_entrega_un_token_permanente_y_activa_el_equipo(): void
    {
        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $apiToken = $this->enrollment->redeem($this->handheld->code, $token);

        $this->handheld->refresh();
        $this->assertSame('activo', $this->handheld->status);
        $this->assertTrue(DeviceToken::matches($apiToken, $this->handheld->api_token_hash));

        // Y no en claro: quien lea la tabla no se puede dar de alta con ella.
        $this->assertNotSame($apiToken, $this->handheld->api_token_hash);
    }

    public function test_el_token_de_alta_no_se_guarda_en_claro(): void
    {
        // Quien pueda leer la caché no debe poder darse de alta con ella.
        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $stored = Cache::get('device-enrollment:'.$this->handheld->id);

        $this->assertIsArray($stored);
        $this->assertNotSame($token, $stored['hash']);
        $this->assertSame(hash('sha256', $token), $stored['hash']);
    }

    public function test_emitir_un_qr_nuevo_invalida_el_anterior(): void
    {
        // Es lo que espera quien vuelve a la pantalla porque perdió el primero.
        $viejo = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];
        $nuevo = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $this->assertNull($this->enrollment->redeem($this->handheld->code, $viejo));
        $this->assertNotNull($this->enrollment->redeem($this->handheld->code, $nuevo));
    }

    public function test_el_token_de_un_equipo_no_da_de_alta_a_otro(): void
    {
        $otro = Device::create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => 'HH-OTRO-'.uniqid(), 'name' => 'Handheld 2', 'kind' => 'handheld',
            'regulatory_region' => 'FCC-PE', 'status' => 'inactivo',
        ]);

        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $this->assertNull($this->enrollment->redeem($otro->code, $token));
    }

    public function test_un_codigo_de_equipo_inventado_no_revienta(): void
    {
        $this->assertNull($this->enrollment->redeem('NO-EXISTE', 'lo-que-sea'));
    }

    public function test_revocar_deja_el_qr_sin_valor(): void
    {
        // Si el papel con el QR se pierde, hay que poder anularlo sin esperar
        // los 15 minutos.
        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $this->enrollment->revoke($this->handheld);

        $this->assertNull($this->enrollment->redeem($this->handheld->code, $token));
    }

    // ------------------------------------------------------- superficie HTTP

    public function test_el_tecnico_genera_el_qr(): void
    {
        $this->actingAs($this->userWith(RoleCode::Tecnico))
            ->postJson("/api/v1/devices/{$this->handheld->id}/enrollment")
            ->assertCreated()
            ->assertJsonPath('qr_payload.device_code', $this->handheld->code)
            ->assertJsonPath('expires_in_minutes', 15);
    }

    public function test_un_vendedor_no_puede_dar_de_alta_un_equipo(): void
    {
        // Dar de alta un equipo es entregarle credenciales de ingesta: no es
        // una acción de tienda.
        $this->actingAs($this->userWith(RoleCode::Vendedor))
            ->postJson("/api/v1/devices/{$this->handheld->id}/enrollment")
            ->assertForbidden();
    }

    public function test_no_se_da_de_alta_un_equipo_dado_de_baja(): void
    {
        $this->handheld->forceFill(['status' => 'baja'])->save();

        $this->actingAs($this->userWith(RoleCode::Tecnico))
            ->postJson("/api/v1/devices/{$this->handheld->id}/enrollment")
            ->assertStatus(409);
    }

    public function test_el_canje_no_necesita_credenciales(): void
    {
        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $this->postJson('/api/v1/devices/enroll', [
            'device_code' => $this->handheld->code,
            'enrollment_token' => $token,
        ])
            ->assertCreated()
            ->assertJsonStructure(['device_code', 'device_name', 'location_id', 'api_token']);
    }

    public function test_el_equipo_recien_dado_de_alta_puede_ingestar(): void
    {
        // La prueba que importa: el token que sale del canje sirve de verdad
        // para lo que el equipo tiene que hacer.
        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $apiToken = $this->postJson('/api/v1/devices/enroll', [
            'device_code' => $this->handheld->code,
            'enrollment_token' => $token,
        ])->json('api_token');

        $this->withHeaders([
            'X-Device-Code' => $this->handheld->code,
            'X-Device-Token' => $apiToken,
        ])->postJson('/api/v1/ingest/heartbeat', ['battery_pct' => 78])
            ->assertStatus(202);
    }

    public function test_un_canje_invalido_no_dice_por_que(): void
    {
        $respuesta = $this->postJson('/api/v1/devices/enroll', [
            'device_code' => $this->handheld->code,
            'enrollment_token' => 'token-inventado',
        ])->assertStatus(422);

        // El mismo mensaje que para un equipo inexistente: distinguirlos le
        // diría a quien prueba a ciegas cuál de sus suposiciones acertó.
        $otro = $this->postJson('/api/v1/devices/enroll', [
            'device_code' => 'NO-EXISTE',
            'enrollment_token' => 'token-inventado',
        ])->assertStatus(422);

        $this->assertSame($respuesta->json('detail'), $otro->json('detail'));
    }

    public function test_el_listado_marca_los_equipos_con_alta_pendiente(): void
    {
        $this->enrollment->issue($this->handheld);

        $this->actingAs($this->userWith(RoleCode::Tecnico))
            ->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonPath('data.0.has_pending_enrollment', true)
            ->assertJsonPath('data.0.is_online', false);
    }

    public function test_revocar_por_http_anula_el_qr(): void
    {
        $token = $this->enrollment->issue($this->handheld)['payload']['enrollment_token'];

        $this->actingAs($this->userWith(RoleCode::Tecnico))
            ->deleteJson("/api/v1/devices/{$this->handheld->id}/enrollment")
            ->assertOk();

        $this->assertNull($this->enrollment->redeem($this->handheld->code, $token));
    }

    private function userWith(RoleCode $role): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id,
            'name' => $role->label(),
            'email' => uniqid($role->value.'-').'@vivatech-peru.com',
            'password' => 'secreto',
            'default_location_id' => $this->tienda->id,
        ]);

        $user->roles()->attach(Role::where('code', $role->value)->value('id'));

        return $user->fresh();
    }
}
