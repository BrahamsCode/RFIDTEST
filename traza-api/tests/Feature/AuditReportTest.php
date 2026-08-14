<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\RoleCode;
use App\Enums\TagState;
use App\Models\Alert;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditReportService;
use App\Services\StockMovementService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Tarea 9.2: informes periódicos de auditoría. Ver `docs/12` §6. */
#[Group('pgsql')]
final class AuditReportTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private ProductVariant $variant;

    private StockMovementService $movements;

    private AuditReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE alerts, audit_logs, role_user, roles, stock_movements,
                       tags, users RESTART IDENTITY CASCADE');
        (new RoleSeeder)->run();

        $this->movements = new StockMovementService(new TagStateMachine);
        $this->reports = app(AuditReportService::class);

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
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => "SKU-{$suffix}", 'cost_price' => 30,
        ]);
    }

    public function test_cuenta_los_ajustes_manuales_por_usuario(): void
    {
        $ana = $this->user('Ana');
        $luis = $this->user('Luis');

        $this->adjust($ana, 2);
        $this->adjust($luis, 1);

        $filas = $this->reports->manualAdjustments(now()->subDays(30))['filas'];

        $porUsuario = collect($filas)->keyBy('usuario');
        $this->assertSame(2, (int) $porUsuario['Ana']['negativos']);
        $this->assertSame(1, (int) $porUsuario['Luis']['negativos']);
    }

    public function test_senala_a_quien_triplica_la_mediana_del_equipo(): void
    {
        /*
         * Es el informe más útil contra el fraude interno: alguien que
         * concentra un número anormal de ajustes negativos merece una
         * conversación, aunque cada ajuste por separado parezca razonable.
         */
        $this->adjust($this->user('Ana'), 1);
        $this->adjust($this->user('Beto'), 2);
        $this->adjust($this->user('Carla'), 1);
        $this->adjust($this->user('Sospechoso'), 30);

        $filas = collect($this->reports->manualAdjustments(now()->subDays(30))['filas'])
            ->keyBy('usuario');

        $this->assertTrue($filas['Sospechoso']['atipico']);
        $this->assertFalse($filas['Ana']['atipico']);
        $this->assertFalse($filas['Beto']['atipico']);
    }

    public function test_usa_la_mediana_y_no_la_media(): void
    {
        /*
         * Con la media, una sola persona con cien ajustes arrastra el umbral
         * hasta el punto de que ella misma parece normal. Aquí: cuatro
         * personas con 1 y una con 40. La media sería 8.8 y 40 > 3 × 8.8 = 26
         * también la marcaría… pero con 4 personas a 1 y una a 20, la media
         * es 4.8, el triple es 14.4 y 20 lo supera por poco; con mediana 1,
         * el umbral es 3 y la detección es inequívoca.
         */
        foreach (['A', 'B', 'C', 'D'] as $n) {
            $this->adjust($this->user($n), 1);
        }
        $this->adjust($this->user('Concentrador'), 20);

        $filas = collect($this->reports->manualAdjustments(now()->subDays(30))['filas'])
            ->keyBy('usuario');

        $this->assertTrue($filas['Concentrador']['atipico']);
        foreach (['A', 'B', 'C', 'D'] as $n) {
            $this->assertFalse($filas[$n]['atipico'], "{$n} no debería estar marcado.");
        }
    }

    public function test_un_equipo_homogeneo_no_marca_a_nadie(): void
    {
        // Si marcase a alguien siempre, el informe dejaría de leerse.
        foreach (['A', 'B', 'C'] as $n) {
            $this->adjust($this->user($n), 3);
        }

        $filas = $this->reports->manualAdjustments(now()->subDays(30))['filas'];

        $this->assertSame([], array_values(array_filter($filas, fn (array $f) => $f['atipico'])));
    }

    public function test_las_mermas_se_valoran_en_dinero(): void
    {
        $ana = $this->user('Ana');
        $this->shrink($ana, 3);

        $fila = $this->reports->shrinkage(now()->subDays(30))['filas'][0];

        $this->assertSame(3, (int) $fila['unidades']);
        $this->assertEqualsWithDelta(90.0, (float) $fila['valor'], 0.01);
    }

    public function test_la_ventana_excluye_lo_de_fuera_del_periodo(): void
    {
        $ana = $this->user('Ana');
        $this->adjust($ana, 1, daysAgo: 2);
        $this->adjust($ana, 1, daysAgo: 90);

        $filas = $this->reports->manualAdjustments(now()->subDays(30))['filas'];

        $this->assertSame(1, (int) $filas[0]['negativos']);
    }

    public function test_detecta_los_accesos_fuera_de_horario(): void
    {
        $ana = $this->user('Ana');

        // 03:00 hora de Lima: fuera de la franja 07:00–22:00.
        $this->auditAt($ana, now()->setTimezone('America/Lima')->setTime(3, 0)->utc());
        $this->auditAt($ana, now()->setTimezone('America/Lima')->setTime(14, 0)->utc());

        $filas = $this->reports->afterHoursAccess(now()->subDays(7))['filas'];

        $this->assertCount(1, $filas);
        $this->assertSame(3, (int) $filas[0]['hora_local']);
    }

    public function test_el_informe_de_accesos_levanta_alerta(): void
    {
        $ana = $this->user('Ana');
        $this->auditAt($ana, now()->setTimezone('America/Lima')->setTime(2, 30)->utc());

        $this->artisan('traza:audit-report accesos --days=7')->assertSuccessful();

        $alerta = Alert::query()->whereRaw("detail->>'origen' = 'traza:audit-report'")->firstOrFail();

        $this->assertSame(1, $alerta->detail['total']);
        // Severidad baja: un acceso nocturno no prueba nada por sí solo.
        $this->assertSame(4, $alerta->severity);
    }

    public function test_los_informes_mensuales_no_levantan_alerta(): void
    {
        // Casi siempre tienen filas. Convertirlos en alerta haría que se
        // acabaran silenciando, y con ellos los que sí importan.
        $this->adjust($this->user('Ana'), 5);

        $this->artisan('traza:audit-report ajustes --days=31')->assertSuccessful();

        $this->assertSame(0, Alert::count());
    }

    public function test_la_salida_json_sirve_para_archivar(): void
    {
        $this->adjust($this->user('Ana'), 1);

        $this->artisan('traza:audit-report ajustes --days=31 --json')->assertSuccessful();
    }

    public function test_un_tipo_desconocido_se_rechaza(): void
    {
        $this->artisan('traza:audit-report inventado')->assertExitCode(2);
    }

    // ------------------------------------------------------------ helpers

    private function adjust(User $user, int $count, int $daysAgo = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $tag = $this->stockTag();

            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::AjusteNegativo,
                toLocationId: $this->tienda->id,
                userId: $user->id,
                unitCost: 30,
                occurredAt: now()->subDays($daysAgo),
            ));
        }
    }

    private function shrink(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $tag = $this->stockTag();

            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::AjusteNegativo,
                toLocationId: $this->tienda->id,
                userId: $user->id,
                occurredAt: now()->subDays(3),
            ));

            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::Merma,
                userId: $user->id,
                unitCost: 30,
                occurredAt: now()->subDays(2),
            ));
        }
    }

    private function auditAt(User $user, Carbon $at): void
    {
        DB::table('audit_logs')->insert([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'action' => 'auth.login',
            'changes' => '{}',
            'ip_address' => '192.168.1.50',
            'created_at' => $at,
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
            tagId: $tag->id,
            type: MovementType::Tarado,
            toLocationId: $this->tienda->id,
            occurredAt: now()->subDays(10),
        ));

        return $tag->refresh();
    }

    private function user(string $name): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'email' => uniqid(strtolower($name).'-').'@vivatech-peru.com',
            'password' => 'secreto',
            'default_location_id' => $this->tienda->id,
        ]);

        $user->roles()->attach(Role::where('code', RoleCode::Almacen->value)->value('id'));

        return $user->fresh();
    }
}
