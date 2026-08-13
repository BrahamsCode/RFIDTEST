<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Acceso de la aplicación web: sesión con cookie sobre Sanctum. */
#[Group('pgsql')]
final class AuthTest extends TestCase
{
    private const PASSWORD = 'secreto-de-prueba';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE role_user, users RESTART IDENTITY CASCADE');

        $suffix = uniqid();
        $organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $location = Location::create([
            'organization_id' => $organization->id,
            'code' => "LIM-{$suffix}", 'name' => 'Gamarra 1',
        ]);

        $this->user = User::create([
            'organization_id' => $organization->id,
            'name' => 'Maria Quispe',
            'email' => "maria-{$suffix}@vivatech-peru.com",
            'password' => self::PASSWORD,
            'default_location_id' => $location->id,
        ]);
    }

    public function test_inicia_sesion_con_credenciales_correctas(): void
    {
        $this->postJson('/login', [
            'email' => $this->user->email,
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('email', $this->user->email);

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_registra_la_fecha_del_ultimo_acceso(): void
    {
        $this->assertNull($this->user->last_login_at);

        $this->postJson('/login', ['email' => $this->user->email, 'password' => self::PASSWORD]);

        $this->assertNotNull($this->user->fresh()->last_login_at);
    }

    public function test_una_contrasena_incorrecta_no_dice_cual_de_los_dos_campos_falla(): void
    {
        // Distinguirlos permitiría enumerar usuarios válidos.
        $response = $this->postJson('/login', [
            'email' => $this->user->email,
            'password' => 'equivocada',
        ])->assertStatus(422);

        $conCorreoInexistente = $this->postJson('/login', [
            'email' => 'no-existe@vivatech-peru.com',
            'password' => 'equivocada',
        ])->assertStatus(422);

        $this->assertSame($response->json('detail'), $conCorreoInexistente->json('detail'));
        $this->assertGuest();
    }

    public function test_una_cuenta_desactivada_no_entra(): void
    {
        $this->user->forceFill(['is_active' => false])->save();

        $this->postJson('/login', ['email' => $this->user->email, 'password' => self::PASSWORD])
            ->assertStatus(403);

        $this->assertGuest();
    }

    public function test_exige_correo_y_contrasena(): void
    {
        // El mensaje va en español y nombra el campo de forma legible: sin
        // las traducciones, Laravel devolvería la clave cruda.
        $this->postJson('/login', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'El campo el correo es obligatorio.')
            ->assertJsonPath('errors.password.0', 'El campo la contraseña es obligatorio.');
    }

    public function test_cerrar_sesion_invalida_la_sesion(): void
    {
        $this->actingAs($this->user);

        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_el_login_esta_limitado_por_tasa(): void
    {
        // Seis intentos por minuto: un ataque de fuerza bruta se topa con esto.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/login', ['email' => $this->user->email, 'password' => 'mal']);
        }

        $this->postJson('/login', ['email' => $this->user->email, 'password' => 'mal'])
            ->assertStatus(429);
    }
}
