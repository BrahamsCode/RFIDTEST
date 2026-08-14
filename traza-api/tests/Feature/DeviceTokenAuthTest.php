<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Devices\DeviceToken;
use App\Models\Device;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Autenticación de dispositivos.
 *
 * Cubre dos defectos encontrados montando la prueba larga de la tarea 2.6:
 * el dispositivo se buscaba por código —que es único solo dentro de la
 * organización— y el token se verificaba con bcrypt en el camino caliente de
 * la ingesta.
 */
#[Group('pgsql')]
final class DeviceTokenAuthTest extends TestCase
{
    private const RUTA = '/api/v1/ingest/reads';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE devices, locations, organizations RESTART IDENTITY CASCADE');
    }

    public function test_un_token_valido_entra(): void
    {
        $token = DeviceToken::generate();
        $device = $this->device('ACME', 'EDGE-01', DeviceToken::hash($token));

        $this->ingest($device->code, $token)->assertStatus(202);
    }

    public function test_dos_organizaciones_pueden_tener_el_mismo_codigo(): void
    {
        /*
         * Es el defecto que motiva la clase. `devices` es única por
         * `(organization_id, code)`, así que dos tiendas pueden llamar
         * `EDGE-01` a su borde con todo el derecho. Buscando por código y
         * quedándose con el primero, la que perdía el sorteo veía rechazado
         * un token perfectamente válido — y el fallo solo aparecía al
         * conectar la segunda tienda, nunca en desarrollo.
         */
        $tokenA = DeviceToken::generate();
        $tokenB = DeviceToken::generate();

        $a = $this->device('ACME', 'EDGE-01', DeviceToken::hash($tokenA));
        $b = $this->device('BETA', 'EDGE-01', DeviceToken::hash($tokenB));

        $this->ingest('EDGE-01', $tokenA)->assertStatus(202);
        $this->ingest('EDGE-01', $tokenB)->assertStatus(202);

        $this->assertNotSame($a->id, $b->id);
    }

    public function test_cada_uno_queda_atribuido_a_su_organizacion(): void
    {
        // Que ambos entren no basta: si los dos se resolvieran al mismo
        // dispositivo, las lecturas de una tienda acabarían contadas en la
        // otra, que es peor que un rechazo porque no se nota.
        $tokenA = DeviceToken::generate();
        $tokenB = DeviceToken::generate();

        $a = $this->device('ACME', 'EDGE-01', DeviceToken::hash($tokenA));
        $b = $this->device('BETA', 'EDGE-01', DeviceToken::hash($tokenB));

        $this->assertSame($a->id, $this->resolvedId('EDGE-01', $tokenA));
        $this->assertSame($b->id, $this->resolvedId('EDGE-01', $tokenB));
    }

    public function test_el_token_de_otro_con_el_codigo_propio_se_rechaza(): void
    {
        $tokenA = DeviceToken::generate();
        $this->device('ACME', 'EDGE-01', DeviceToken::hash($tokenA));
        $this->device('BETA', 'EDGE-02', DeviceToken::hash(DeviceToken::generate()));

        // Token de ACME presentado con el código de BETA: el token resuelve,
        // pero el código no cuadra. Es un borde mal configurado y tiene que
        // fallar ruidosamente, no atribuir lecturas a la tienda equivocada.
        $this->ingest('EDGE-02', $tokenA)->assertUnauthorized();
    }

    public function test_un_token_inventado_se_rechaza(): void
    {
        $this->device('ACME', 'EDGE-01', DeviceToken::hash(DeviceToken::generate()));

        $this->ingest('EDGE-01', 'no-es-el-token')->assertUnauthorized();
    }

    public function test_un_dispositivo_inactivo_da_403(): void
    {
        $token = DeviceToken::generate();
        $this->device('ACME', 'EDGE-01', DeviceToken::hash($token), status: 'baja');

        $this->ingest('EDGE-01', $token)->assertForbidden();
    }

    public function test_los_rechazos_van_en_formato_problem(): void
    {
        // `docs/06` §6 fija RFC 7807 para toda la API. Este middleware
        // devolvía JSON suelto.
        $this->device('ACME', 'EDGE-01', DeviceToken::hash(DeviceToken::generate()));

        $this->ingest('EDGE-01', 'mal')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/problem+json');
    }

    // ------------------------------------------------- compatibilidad bcrypt

    public function test_un_dispositivo_con_hash_bcrypt_antiguo_sigue_entrando(): void
    {
        $token = DeviceToken::generate();
        $this->device('ACME', 'EDGE-01', Hash::make($token));

        $this->ingest('EDGE-01', $token)->assertStatus(202);
    }

    public function test_el_hash_antiguo_se_reescribe_al_primer_acierto(): void
    {
        /*
         * Así cada dispositivo paga el bcrypt una sola vez en su vida y el
         * camino lento acaba desapareciendo solo, sin migración de datos ni
         * necesidad de volver a dar de alta a nadie.
         */
        $token = DeviceToken::generate();
        $device = $this->device('ACME', 'EDGE-01', Hash::make($token));

        $this->assertTrue(DeviceToken::isLegacy((string) $device->api_token_hash));

        $this->ingest('EDGE-01', $token)->assertStatus(202);

        $device->refresh();
        $this->assertFalse(DeviceToken::isLegacy((string) $device->api_token_hash));
        $this->assertSame(DeviceToken::hash($token), $device->api_token_hash);

        // Y sigue entrando después de la reescritura.
        $this->ingest('EDGE-01', $token)->assertStatus(202);
    }

    public function test_el_camino_antiguo_tambien_distingue_organizaciones(): void
    {
        /*
         * El mismo caso del código repetido, pero con los dos dispositivos
         * todavía en bcrypt. Aquí sí hay que buscar por código —no queda
         * otra—, así que se prueban todos los candidatos en vez de quedarse
         * con el primero. Es exactamente lo que fallaba antes.
         */
        $tokenA = DeviceToken::generate();
        $tokenB = DeviceToken::generate();

        $a = $this->device('ACME', 'EDGE-01', Hash::make($tokenA));
        $b = $this->device('BETA', 'EDGE-01', Hash::make($tokenB));

        // El segundo es el que perdía el sorteo con el código anterior.
        $this->assertSame($b->id, $this->resolvedId('EDGE-01', $tokenB));
        $this->assertSame($a->id, $this->resolvedId('EDGE-01', $tokenA));
    }

    public function test_un_token_basura_no_provoca_ni_una_comprobacion_bcrypt(): void
    {
        /*
         * bcrypt con coste 12 tarda ~231 ms. Si un token inventado lo
         * disparase, tumbar la ingesta costaría un puñado de peticiones por
         * segundo. Se mide el tiempo porque es la única forma de comprobar
         * que no se está ejecutando.
         */
        $this->device('ACME', 'EDGE-01', Hash::make(DeviceToken::generate()));

        $inicio = microtime(true);
        $this->ingest('EDGE-99', 'token-inventado')->assertUnauthorized();
        $transcurrido = (microtime(true) - $inicio) * 1000;

        $this->assertLessThan(
            150,
            $transcurrido,
            "Un código desconocido tardó {$transcurrido} ms: parece que se está ejecutando bcrypt.",
        );
    }

    // ------------------------------------------------------------ el hasheo

    public function test_el_hash_no_es_bcrypt(): void
    {
        // Un token de 380 bits no necesita estiramiento de clave, y bcrypt
        // costaba 231 ms en cada petición de ingesta.
        $hash = DeviceToken::hash('lo-que-sea');

        $this->assertSame(64, strlen($hash));
        $this->assertFalse(DeviceToken::isLegacy($hash));
    }

    public function test_el_mismo_token_da_siempre_el_mismo_hash(): void
    {
        // Es lo que permite buscar por hash con un índice en vez de recorrer
        // la tabla comprobando uno a uno.
        $token = DeviceToken::generate();

        $this->assertSame(DeviceToken::hash($token), DeviceToken::hash($token));
        $this->assertNotSame(DeviceToken::hash($token), DeviceToken::hash(DeviceToken::generate()));
    }

    public function test_un_hash_vacio_o_nulo_no_valida_nada(): void
    {
        // Un dispositivo aún sin dar de alta tiene el hash a NULL. Nadie
        // debería poder autenticarse contra eso.
        $this->assertFalse(DeviceToken::matches('cualquier-cosa', null));
        $this->assertFalse(DeviceToken::matches('cualquier-cosa', ''));
    }

    public function test_el_token_generado_tiene_entropia_suficiente(): void
    {
        $this->assertSame(64, strlen(DeviceToken::generate()));
        $this->assertNotSame(DeviceToken::generate(), DeviceToken::generate());
    }

    // ------------------------------------------------------------- ayudantes

    private function device(
        string $org,
        string $code,
        string $hash,
        string $status = 'activo',
    ): Device {
        $organization = Organization::create(['name' => $org]);
        $location = Location::create([
            'organization_id' => $organization->id,
            'code' => $org.'-'.$code, 'name' => 'Tienda '.$org,
        ]);

        return Device::create([
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'code' => $code, 'name' => 'Borde '.$code, 'kind' => 'edge',
            'regulatory_region' => 'FCC-PE', 'status' => $status,
            'api_token_hash' => $hash,
        ]);
    }

    private function ingest(string $code, string $token): TestResponse
    {
        return $this->withHeaders([
            'X-Device-Code' => $code,
            'X-Device-Token' => $token,
        ])->postJson(self::RUTA, [
            'device_code' => $code,
            'batch_id' => (string) Str::uuid(),
            'reads' => [[
                'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
                'antenna' => 1,
                'rssi' => -55,
                'read_at' => now()->toIso8601String(),
                'read_count' => 1,
            ]],
        ]);
    }

    /** Qué dispositivo resolvió el middleware para estas credenciales. */
    private function resolvedId(string $code, string $token): ?int
    {
        $this->ingest($code, $token)->assertStatus(202);

        return (int) DB::table('tag_reads')
            ->orderByDesc('id')
            ->value('device_id');
    }
}
