<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RoleCode;
use App\Enums\TagState;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Tarea 4.4: detecciones y RSSI de una prenda.
 *
 * El gráfico parece un detalle técnico y es lo primero que mira soporte
 * cuando alguien dice «el sistema dice que está y no está».
 */
#[Group('pgsql')]
final class TagDetectionsTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private Tag $tag;

    private int $device;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE role_user, roles, tag_reads, tags, users RESTART IDENTITY CASCADE');
        (new RoleSeeder)->run();

        $suffix = uniqid();
        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->tienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-{$suffix}", 'name' => 'Gamarra 1',
        ]);
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}", 'name' => 'Polo',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "SKU-{$suffix}",
        ]);

        $this->tag = Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => '3035D9'.strtoupper(bin2hex(random_bytes(9))),
            'product_variant_id' => $variant->id,
            'state' => TagState::EnStock,
            'current_location_id' => $this->tienda->id,
        ]);

        $this->device = (int) DB::table('devices')->insertGetId([
            'organization_id' => $this->organization->id,
            'location_id' => $this->tienda->id,
            'code' => "EDGE-{$suffix}", 'name' => 'Borde', 'kind' => 'edge',
            'regulatory_region' => 'FCC-PE', 'status' => 'activo',
        ]);
    }

    public function test_agrupa_las_lecturas_por_hora_y_antena(): void
    {
        // Devolver las lecturas crudas serían decenas de miles de puntos que
        // el navegador no dibuja y que además no dicen nada.
        $this->reads(hoursAgo: 2, antenna: 1, rssi: -52, count: 30);
        $this->reads(hoursAgo: 2, antenna: 2, rssi: -60, count: 20);
        $this->reads(hoursAgo: 1, antenna: 1, rssi: -50, count: 10);

        $response = $this->actingAs($this->user())
            ->getJson("/api/v1/tags/{$this->tag->epc}/detections")
            ->assertOk();

        // Tres grupos: dos antenas en una hora, una en la otra.
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(60, $response->json('summary.total_reads'));
    }

    public function test_da_medio_minimo_y_maximo_de_cada_grupo(): void
    {
        DB::table('tag_reads')->insert(array_map(fn (int $rssi) => [
            'device_id' => $this->device,
            'location_id' => $this->tienda->id,
            'epc' => $this->tag->epc,
            'antenna_port' => 1,
            'rssi' => $rssi,
            'read_at' => now()->subHour(),
        ], [-40, -50, -60]));

        $punto = $this->actingAs($this->user())
            ->getJson("/api/v1/tags/{$this->tag->epc}/detections")
            ->assertOk()
            ->json('data.0');

        $this->assertEqualsWithDelta(-50.0, $punto['rssi_avg'], 0.01);
        $this->assertEqualsWithDelta(-60.0, $punto['rssi_min'], 0.01);
        $this->assertEqualsWithDelta(-40.0, $punto['rssi_max'], 0.01);
    }

    public function test_avisa_cuando_la_senal_es_de_lejos(): void
    {
        /*
         * Un RSSI plano en torno a −75 dBm no prueba que la prenda esté
         * donde el sistema dice: prueba que se lee de lejos, y muy
         * probablemente desde el local de al lado. Sin este aviso, soporte
         * mira el gráfico y concluye lo contrario.
         */
        $this->reads(hoursAgo: 1, antenna: 1, rssi: -78, count: 25);

        $this->actingAs($this->user())
            ->getJson("/api/v1/tags/{$this->tag->epc}/detections")
            ->assertOk()
            ->assertJsonPath('summary.weak_signal', true);
    }

    public function test_una_senal_fuerte_no_dispara_el_aviso(): void
    {
        $this->reads(hoursAgo: 1, antenna: 1, rssi: -48, count: 25);

        $this->actingAs($this->user())
            ->getJson("/api/v1/tags/{$this->tag->epc}/detections")
            ->assertOk()
            ->assertJsonPath('summary.weak_signal', false);
    }

    public function test_la_ventana_recorta_lo_antiguo(): void
    {
        // El filtro por `read_at` es lo que recorta particiones: sin él la
        // consulta barrería los tres meses de retención.
        $this->reads(hoursAgo: 1, antenna: 1, rssi: -50, count: 5);
        $this->reads(hoursAgo: 200, antenna: 1, rssi: -50, count: 5);

        $this->assertSame(
            5,
            $this->actingAs($this->user())
                ->getJson("/api/v1/tags/{$this->tag->epc}/detections?hours=24")
                ->json('summary.total_reads'),
        );

        $this->assertSame(
            10,
            $this->actingAs($this->user())
                ->getJson("/api/v1/tags/{$this->tag->epc}/detections?hours=720")
                ->json('summary.total_reads'),
        );
    }

    public function test_sin_lecturas_devuelve_vacio_y_no_revienta(): void
    {
        $this->actingAs($this->user())
            ->getJson("/api/v1/tags/{$this->tag->epc}/detections")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary.rssi_avg', null)
            ->assertJsonPath('summary.weak_signal', false);
    }

    public function test_un_epc_inexistente_da_404_en_formato_problem(): void
    {
        $this->actingAs($this->user())
            ->getJson('/api/v1/tags/3035D9FFFFFFFFFFFFFFFFFF/detections')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/problem+json');
    }

    public function test_la_ventana_tiene_tope(): void
    {
        // Sin tope, `?hours=100000` recorrería la tabla entera.
        $this->actingAs($this->user())
            ->getJson("/api/v1/tags/{$this->tag->epc}/detections?hours=100000")
            ->assertOk()
            ->assertJsonPath('hours', 720);
    }

    public function test_exige_sesion(): void
    {
        $this->getJson("/api/v1/tags/{$this->tag->epc}/detections")->assertUnauthorized();
    }

    private function reads(int $hoursAgo, int $antenna, int $rssi, int $count): void
    {
        DB::table('tag_reads')->insert(array_map(fn (int $i) => [
            'device_id' => $this->device,
            'location_id' => $this->tienda->id,
            'epc' => $this->tag->epc,
            'antenna_port' => $antenna,
            'rssi' => $rssi,
            'read_at' => now()->subHours($hoursAgo),
        ], range(1, $count)));
    }

    private function user(): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id,
            'name' => 'Soporte',
            'email' => uniqid('soporte-').'@vivatech-peru.com',
            'password' => 'secreto',
            'default_location_id' => $this->tienda->id,
        ]);

        $user->roles()->attach(Role::where('code', RoleCode::Tecnico->value)->value('id'));

        return $user->fresh();
    }
}
