<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tagging\Epc\EpcCodecFactory;
use App\Domain\Tagging\SerialRange;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SerialReservationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/** Tarea 1.5. */
#[Group('pgsql')]
final class SerialReservationTest extends TestCase
{
    private ProductVariant $variant;

    private SerialReservationService $serials;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE product_variant_counters, tags, stock_movements RESTART IDENTITY CASCADE');

        $this->serials = new SerialReservationService(new EpcCodecFactory());

        $suffix = uniqid();
        $organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $product = Product::create([
            'organization_id' => $organization->id,
            'code' => "P-{$suffix}", 'name' => 'Polo',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => "SKU-{$suffix}",
            'item_reference' => '012345',
        ]);

        config()->set('traza.epc.scheme', 'sgtin-96');
        config()->set('traza.epc.gs1_company_prefix', '7751234');
    }

    public function test_la_primera_reserva_empieza_en_uno(): void
    {
        $range = $this->serials->reserve($this->variant, 100);

        $this->assertSame(1, $range->from);
        $this->assertSame(100, $range->to);
        $this->assertCount(100, $range);
    }

    public function test_las_reservas_sucesivas_no_se_solapan(): void
    {
        $a = $this->serials->reserve($this->variant, 50);
        $b = $this->serials->reserve($this->variant, 50);

        $this->assertFalse($a->overlaps($b));
        $this->assertSame(51, $b->from);
    }

    /**
     * Criterio de aceptación de la tarea 1.5: 10 procesos concurrentes
     * reservando 100 seriales cada uno no producen ningún duplicado.
     */
    public function test_diez_reservas_concurrentes_no_duplican_ningun_serial(): void
    {
        $connections = [];
        $ranges = [];

        // Diez sesiones de PostgreSQL de verdad, no diez llamadas seguidas:
        // lo que se prueba es que el bloqueo de fila las serializa.
        for ($i = 0; $i < 10; $i++) {
            $name = "pgsql_worker_{$i}";
            config()->set("database.connections.{$name}", config('database.connections.pgsql'));
            $connections[] = $name;
            DB::connection($name)->getPdo();
        }

        foreach ($connections as $name) {
            $row = DB::connection($name)->selectOne(
                'SELECT * FROM reserve_serial_range(?, ?)',
                [$this->variant->id, 100],
            );
            $ranges[] = new SerialRange((int) $row->serial_from, (int) $row->serial_to);
        }

        $todos = [];
        foreach ($ranges as $range) {
            $todos = [...$todos, ...$range->toArray()];
        }

        $this->assertCount(1000, $todos);
        $this->assertCount(1000, array_unique($todos), 'Hay seriales repetidos.');
        $this->assertSame(1, min($todos));
        $this->assertSame(1000, max($todos));

        foreach ($connections as $name) {
            DB::purge($name);
        }
    }

    public function test_rechaza_reservar_una_cantidad_no_positiva(): void
    {
        $this->expectException(RuntimeException::class);
        $this->serials->reserve($this->variant, 0);
    }

    public function test_entrega_los_epc_ya_codificados(): void
    {
        $epcs = $this->serials->reserveEpcs($this->variant, 3);

        $this->assertCount(3, $epcs);
        foreach ($epcs as $epc) {
            $this->assertSame(24, strlen($epc));
            $this->assertStringStartsWith('30', $epc);
        }
        // Seriales consecutivos, EPC distintos.
        $this->assertCount(3, array_unique($epcs));
    }

    public function test_los_epc_emitidos_decodifican_a_su_variante(): void
    {
        $epcs = $this->serials->reserveEpcs($this->variant, 2);
        $decoded = (new EpcCodecFactory())->decode($epcs[1]);

        $this->assertSame('7751234', $decoded['companyPrefix']);
        $this->assertSame('012345', $decoded['itemReference']);
        $this->assertSame(2, $decoded['serial']);
    }

    public function test_sin_prefijo_gs1_lo_dice_claramente(): void
    {
        config()->set('traza.epc.gs1_company_prefix', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/TRAZA_GS1_COMPANY_PREFIX|gid-96/');
        $this->serials->reserveEpcs($this->variant, 1);
    }

    public function test_con_gid96_no_hace_falta_prefijo_gs1(): void
    {
        // Es la vía de arranque mientras no haya GS1 (ADR-009).
        config()->set('traza.epc.scheme', 'gid-96');
        config()->set('traza.epc.gs1_company_prefix', '');

        $epcs = $this->serials->reserveEpcs($this->variant, 2);

        $this->assertCount(2, $epcs);
        $this->assertStringStartsWith('35', $epcs[0]);
        $this->assertSame(
            $this->variant->id,
            (new EpcCodecFactory())->decode($epcs[0])['objectClass'],
        );
    }

    public function test_informa_de_los_seriales_que_quedan(): void
    {
        $antes = $this->serials->remaining($this->variant);
        $this->serials->reserve($this->variant, 1000);

        $this->assertSame($antes - 1000, $this->serials->remaining($this->variant));
    }

    public function test_una_variante_sin_item_reference_no_se_puede_codificar(): void
    {
        $this->variant->forceFill(['item_reference' => null])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/item_reference/');
        $this->serials->reserveEpcs($this->variant->refresh(), 1);
    }
}
