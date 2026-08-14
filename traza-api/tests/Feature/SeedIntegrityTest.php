<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tagging\Epc\EpcCodecFactory;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Tarea 1.6: la semilla de desarrollo y su control de integridad.
 *
 * La prueba que de verdad importa es la última: el control nocturno de
 * `docs/05` §6 tiene que devolver 0 filas sobre datos recién sembrados. Si no,
 * el entorno de desarrollo arranca con una alerta crítica falsa y el equipo
 * aprende a ignorarla, que es exactamente lo que el documento prohíbe.
 */
#[Group('pgsql')]
#[Group('seed')]
final class SeedIntegrityTest extends TestCase
{
    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        // Sembrar cuesta ~10 s: se hace una vez para toda la clase. Cada
        // prueba solo lee.
        if (! self::$seeded) {
            Artisan::call('migrate:fresh', ['--drop-types' => true, '--force' => true]);
            (new DatabaseSeeder)->setContainer(app())->run();
            self::$seeded = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$seeded = false;

        parent::tearDownAfterClass();
    }

    public function test_genera_al_menos_mil_tags(): void
    {
        $this->assertGreaterThanOrEqual(1000, DB::table('tags')->count());
    }

    public function test_el_control_de_integridad_nocturno_devuelve_cero_filas(): void
    {
        /*
         * Consulta literal de `docs/05` §6. Con `sql/seeds.sql` devolvía 12
         * filas y 51 unidades de desajuste, por dos motivos: los tags en
         * `no_visto` y `perdido` no recibían movimiento del estado final, y
         * 28 ventas quedaban fechadas antes del tarado.
         */
        $locationId = DB::table('locations')->where('code', 'LIM-01')->value('id');

        $rows = DB::select(<<<'SQL'
            WITH proyectado AS (
                SELECT product_variant_id, COUNT(*) AS qty
                FROM tags
                WHERE state = 'en_stock' AND current_location_id = ?
                GROUP BY 1
            ),
            reconstruido AS (
                SELECT * FROM stock_as_of(?, now())
            )
            SELECT
                COALESCE(p.product_variant_id, r.product_variant_id) AS product_variant_id,
                COALESCE(p.qty, 0) AS proyectado,
                COALESCE(r.quantity, 0) AS reconstruido
            FROM proyectado p
            FULL OUTER JOIN reconstruido r USING (product_variant_id)
            WHERE COALESCE(p.qty, 0) <> COALESCE(r.quantity, 0)
        SQL, [$locationId, $locationId]);

        $this->assertSame([], $rows, 'La proyección y la reconstrucción no cuadran: '.json_encode($rows));
    }

    public function test_ningun_movimiento_queda_fechado_en_el_futuro(): void
    {
        // Un movimiento con fecha futura es invisible para
        // `stock_as_of(loc, now())`, así que el estado anterior pasa a ser el
        // último visible y la reconstrucción miente. Costó 8 filas de
        // desajuste antes de acotarlo.
        $this->assertSame(
            0,
            DB::table('stock_movements')->where('occurred_at', '>', now())->count(),
        );
    }

    public function test_ninguna_venta_es_anterior_al_tarado_de_su_prenda(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT t.id
            FROM tags t
            JOIN stock_movements alta ON alta.tag_id = t.id AND alta.movement_type = 'tarado'
            JOIN stock_movements venta ON venta.tag_id = t.id AND venta.movement_type = 'venta'
            WHERE venta.occurred_at < alta.occurred_at
        SQL);

        $this->assertSame([], $rows);
    }

    public function test_el_estado_del_tag_coincide_con_su_ultimo_movimiento(): void
    {
        // Es la invariante que sostiene todo lo demás: si se rompe, la
        // proyección deja de ser una vista de los movimientos y pasa a ser
        // una segunda fuente de verdad que nadie concilia.
        $rows = DB::select(<<<'SQL'
            SELECT t.id, t.state, u.state_after
            FROM tags t
            JOIN LATERAL (
                SELECT m.state_after FROM stock_movements m
                WHERE m.tag_id = t.id
                ORDER BY m.occurred_at DESC, m.id DESC LIMIT 1
            ) u ON TRUE
            WHERE u.state_after::text <> t.state::text
        SQL);

        $this->assertSame([], $rows);
    }

    public function test_los_epc_los_compone_el_codec_real(): void
    {
        // La semilla SQL concatenaba hex a mano; si el codec cambiara, los
        // datos de desarrollo dejarían de parecerse a los de producción sin
        // que nadie se enterase.
        $sample = DB::table('tags')->orderBy('id')->limit(50)->pluck('epc');

        foreach ($sample as $epc) {
            $decoded = app(EpcCodecFactory::class)->decode($epc);

            $this->assertSame('sgtin-96', $decoded['scheme']);
            $this->assertSame('7751234', $decoded['companyPrefix']);
        }
    }

    public function test_la_semilla_es_idempotente(): void
    {
        // Uno vuelve a sembrar en cuanto añade una variante. Si eso revienta
        // por clave única, se acaba trabajando con `migrate:fresh` a ciegas.
        $before = DB::table('locations')->count();

        (new ReferenceSeeder)->setContainer(app())->run();

        $this->assertSame($before, DB::table('locations')->count());
    }

    public function test_hay_datos_para_las_pantallas_de_operacion(): void
    {
        $this->assertGreaterThan(0, DB::table('alerts')->where('status', 'abierta')->count());
        $this->assertSame(1, DB::table('inventory_cycles')->where('status', 'cerrado')->count());
        $this->assertGreaterThan(0, DB::table('devices')->where('kind', 'lector_fijo')->count());

        // Las antenas del portal necesitan lado interior/exterior: sin eso el
        // borde no puede clasificar un cruce y el antihurto no funciona.
        $sides = DB::table('device_antennas')->whereNotNull('side')->distinct()->pluck('side');
        $this->assertEqualsCanonicalizing(['interior', 'exterior'], $sides->all());
    }
}
