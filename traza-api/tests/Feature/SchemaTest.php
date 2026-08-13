<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * Verifica los invariantes que el esquema garantiza a nivel de base de datos.
 *
 * Requieren PostgreSQL real: ENUM, particiones, triggers y funciones no
 * existen en SQLite. Se ejecutan con:
 *
 *     DB_CONNECTION=pgsql php artisan test --group=pgsql
 */
#[Group('pgsql')]
final class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }
    }

    public function test_estan_las_40_tablas_del_esquema(): void
    {
        $count = DB::scalar(
            "SELECT count(*) FROM information_schema.tables
              WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
                AND table_name NOT IN ('migrations', 'personal_access_tokens')"
        );

        $this->assertSame(40, (int) $count);
    }

    public function test_el_enum_tag_state_tiene_los_10_valores_documentados(): void
    {
        $values = DB::select(
            "SELECT e.enumlabel FROM pg_enum e
               JOIN pg_type t ON t.oid = e.enumtypid
              WHERE t.typname = 'tag_state' ORDER BY e.enumsortorder"
        );

        $this->assertSame([
            'creado', 'codificado', 'en_stock', 'en_transito', 'no_visto',
            'perdido', 'vendido', 'danado', 'baja', 'anulado',
        ], array_column($values, 'enumlabel'));
    }

    public function test_tag_reads_esta_particionada_por_rango(): void
    {
        $strategy = DB::scalar(
            "SELECT p.partstrat FROM pg_partitioned_table p
               JOIN pg_class c ON c.oid = p.partrelid WHERE c.relname = 'tag_reads'"
        );
        $this->assertSame('r', $strategy, 'tag_reads debe estar particionada por RANGE.');

        $partitions = DB::scalar(
            "SELECT count(*) FROM pg_inherits i
               JOIN pg_class p ON p.oid = i.inhparent WHERE p.relname = 'tag_reads'"
        );
        // Tres meses iniciales más la partición DEFAULT de seguridad.
        $this->assertSame(4, (int) $partitions);
    }

    public function test_la_particion_default_esta_vacia(): void
    {
        // Si acumula filas, la rotación mensual fallará al crear ese mes.
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM tag_reads_default'));
    }

    public function test_stock_movements_rechaza_update_y_delete(): void
    {
        [$orgId, $variantId] = $this->seedMinimum();

        $movementId = DB::scalar(
            "INSERT INTO stock_movements (organization_id, product_variant_id, movement_type, state_after)
             VALUES (?, ?, 'tarado', 'codificado') RETURNING id",
            [$orgId, $variantId]
        );

        $this->assertUpdateForbidden(
            fn () => DB::update('UPDATE stock_movements SET reason = ? WHERE id = ?', ['x', $movementId])
        );
        $this->assertUpdateForbidden(
            fn () => DB::delete('DELETE FROM stock_movements WHERE id = ?', [$movementId])
        );
    }

    public function test_reserve_serial_range_entrega_rangos_disjuntos(): void
    {
        [, $variantId] = $this->seedMinimum();

        $first = DB::selectOne('SELECT * FROM reserve_serial_range(?, ?)', [$variantId, 100]);
        $second = DB::selectOne('SELECT * FROM reserve_serial_range(?, ?)', [$variantId, 100]);

        $this->assertSame(1, (int) $first->serial_from);
        $this->assertSame(100, (int) $first->serial_to);
        // El segundo rango empieza justo donde acabó el primero: sin solape.
        $this->assertSame(101, (int) $second->serial_from);
        $this->assertSame(200, (int) $second->serial_to);
    }

    public function test_reserve_serial_range_rechaza_una_cantidad_no_positiva(): void
    {
        [, $variantId] = $this->seedMinimum();

        $this->expectException(Throwable::class);
        DB::selectOne('SELECT * FROM reserve_serial_range(?, ?)', [$variantId, 0]);
    }

    public function test_los_epc_deben_ser_hexadecimales_en_mayuscula(): void
    {
        [$orgId] = $this->seedMinimum();

        $this->expectException(Throwable::class);
        DB::insert(
            'INSERT INTO tags (organization_id, epc) VALUES (?, ?)',
            [$orgId, 'no-es-hex']
        );
    }

    public function test_una_prenda_no_puede_sustituirse_a_si_misma(): void
    {
        [$orgId] = $this->seedMinimum();

        $tagId = DB::scalar(
            'INSERT INTO tags (organization_id, epc) VALUES (?, ?) RETURNING id',
            [$orgId, strtoupper(bin2hex(random_bytes(12)))]
        );

        $this->expectException(Throwable::class);
        DB::insert(
            'INSERT INTO tag_replacements (old_tag_id, new_tag_id, reason) VALUES (?, ?, ?)',
            [$tagId, $tagId, 'ilegible']
        );
    }

    public function test_la_diferencia_del_resultado_de_ciclo_se_calcula_sola(): void
    {
        [$orgId, $variantId, $locationId] = $this->seedMinimum();

        $cycleId = DB::scalar(
            'INSERT INTO inventory_cycles (organization_id, location_id, code)
             VALUES (?, ?, ?) RETURNING id',
            [$orgId, $locationId, 'INV-'.uniqid()]
        );

        DB::insert(
            'INSERT INTO inventory_cycle_results (inventory_cycle_id, product_variant_id, expected_qty, counted_qty)
             VALUES (?, ?, 10, 7)',
            [$cycleId, $variantId]
        );

        $difference = DB::scalar(
            'SELECT difference_qty FROM inventory_cycle_results WHERE inventory_cycle_id = ?',
            [$cycleId]
        );

        // Faltan 3 prendas: la columna generada lo calcula sin que nadie lo escriba.
        $this->assertSame(-3, (int) $difference);
    }

    public function test_updated_at_se_actualiza_solo(): void
    {
        [$orgId] = $this->seedMinimum();

        $before = DB::scalar('SELECT updated_at FROM organizations WHERE id = ?', [$orgId]);
        DB::update('UPDATE organizations SET name = ? WHERE id = ?', ['Otro nombre', $orgId]);
        $after = DB::scalar('SELECT updated_at FROM organizations WHERE id = ?', [$orgId]);

        $this->assertNotSame($before, $after);
    }

    /**
     * Siembra el mínimo imprescindible con códigos únicos.
     *
     * No se envuelve cada prueba en una transacción porque varias provocan
     * errores de base de datos a propósito, y en PostgreSQL un error aborta
     * la transacción entera y deja inservible el resto de la prueba.
     *
     * @return array{0:int,1:int,2:int} organización, variante y ubicación
     */
    private function seedMinimum(): array
    {
        $suffix = uniqid();

        $orgId = (int) DB::scalar(
            "INSERT INTO organizations (name) VALUES ('Prueba') RETURNING id"
        );
        $locationId = (int) DB::scalar(
            'INSERT INTO locations (organization_id, code, name) VALUES (?, ?, ?) RETURNING id',
            [$orgId, "T-{$suffix}", 'Tienda de prueba']
        );
        $productId = (int) DB::scalar(
            'INSERT INTO products (organization_id, code, name) VALUES (?, ?, ?) RETURNING id',
            [$orgId, "P-{$suffix}", 'Polo']
        );
        $variantId = (int) DB::scalar(
            'INSERT INTO product_variants (product_id, sku) VALUES (?, ?) RETURNING id',
            [$productId, "SKU-{$suffix}"]
        );

        return [$orgId, $variantId, $locationId];
    }

    private function assertUpdateForbidden(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La operación debería haber sido rechazada por el trigger append-only.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
}
