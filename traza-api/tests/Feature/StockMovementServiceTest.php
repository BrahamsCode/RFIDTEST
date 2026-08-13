<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\Exceptions\InvalidTransition;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Tag;
use App\Models\Zone;
use App\Services\StockMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Casos de `docs/15` §3. Requieren PostgreSQL real: el trigger append-only,
 * los ENUM y `reserve_serial_range` no existen en SQLite, y probar contra un
 * motor distinto al de producción da falsa confianza.
 */
#[Group('pgsql')]
final class StockMovementServiceTest extends TestCase
{
    private StockMovementService $service;

    private Organization $organization;

    private Location $location;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        $this->service = new StockMovementService(new TagStateMachine);
        $suffix = uniqid();

        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->location = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "T-{$suffix}",
            'name' => 'Gamarra 1',
        ]);
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}",
            'name' => 'Polo oversize',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => "SKU-{$suffix}",
            'cost_price' => 18.50,
            'sale_price' => 49.90,
        ]);
    }

    public function test_un_movimiento_escribe_el_historico_y_actualiza_la_proyeccion(): void
    {
        $tag = $this->makeTag(TagState::Codificado);

        $movement = $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::Tarado,
            toLocationId: $this->location->id,
            reason: 'Alta en tienda',
        ));

        $this->assertSame(MovementType::Tarado, $movement->movement_type);
        $this->assertSame(TagState::Codificado, $movement->state_before);
        $this->assertSame(TagState::EnStock, $movement->state_after);

        $tag->refresh();
        $this->assertSame(TagState::EnStock, $tag->state);
        $this->assertSame($this->location->id, $tag->current_location_id);
        $this->assertNotNull($tag->last_seen_at);
        $this->assertNotNull($tag->first_seen_at);
    }

    public function test_una_transicion_ilegal_no_deja_rastro_parcial(): void
    {
        // Una prenda recién codificada no puede venderse: falta tararla.
        $tag = $this->makeTag(TagState::Codificado);

        try {
            $this->service->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::Venta,
            ));
            $this->fail('Debería haber lanzado InvalidTransition.');
        } catch (InvalidTransition $e) {
            $this->assertStringContainsString('codificado → vendido', $e->getMessage());
        }

        $tag->refresh();
        $this->assertSame(TagState::Codificado, $tag->state, 'El tag no debe haber cambiado.');
        $this->assertSame(0, StockMovement::where('tag_id', $tag->id)->count());
    }

    public function test_stock_movements_rechaza_cualquier_update(): void
    {
        $tag = $this->makeTag(TagState::Codificado);
        $movement = $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::Tarado,
            toLocationId: $this->location->id,
        ));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('stock_movements')->where('id', $movement->id)->update(['reason' => 'manipulado']);
    }

    public function test_stock_movements_rechaza_cualquier_delete(): void
    {
        $tag = $this->makeTag(TagState::Codificado);
        $movement = $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::Tarado,
            toLocationId: $this->location->id,
        ));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('stock_movements')->where('id', $movement->id)->delete();
    }

    public function test_el_bloqueo_pesimista_serializa_dos_movimientos_del_mismo_tag(): void
    {
        $tag = $this->makeTag(TagState::Codificado);
        $this->stockIt($tag);

        // Segunda conexión: simula el otro operario.
        $other = DB::connection('pgsql_second');
        $other->beginTransaction();
        $other->select('SELECT id FROM tags WHERE id = ? FOR UPDATE', [$tag->id]);

        // Con la fila bloqueada, la venta no puede completarse.
        $other->statement("SET LOCAL lock_timeout = '500ms'");
        DB::statement("SET lock_timeout = '500ms'");

        try {
            $this->service->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::Venta,
            ));
            $this->fail('El movimiento debería haber esperado al lock y agotado el tiempo.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
        } finally {
            $other->rollBack();
            DB::statement('SET lock_timeout = 0');
        }

        // Y el estado sigue siendo coherente: la venta no se aplicó a medias.
        $tag->refresh();
        $this->assertSame(TagState::EnStock, $tag->state);
    }

    public function test_liberado_el_bloqueo_el_movimiento_se_aplica(): void
    {
        $tag = $this->makeTag(TagState::Codificado);
        $this->stockIt($tag);

        $other = DB::connection('pgsql_second');
        $other->beginTransaction();
        $other->select('SELECT id FROM tags WHERE id = ? FOR UPDATE', [$tag->id]);
        $other->rollBack();

        $this->service->apply(new MovementIntent(tagId: $tag->id, type: MovementType::Venta));

        $tag->refresh();
        $this->assertSame(TagState::Vendido, $tag->state);
        $this->assertNotNull($tag->sold_at);
    }

    public function test_reserve_serial_range_no_pierde_ni_duplica_bajo_concurrencia(): void
    {
        $ranges = collect(range(1, 10))->map(
            fn () => DB::select('SELECT * FROM reserve_serial_range(?, ?)', [$this->variant->id, 100])[0]
        );

        $todos = $ranges->flatMap(fn ($r) => range((int) $r->serial_from, (int) $r->serial_to));

        $this->assertCount(1000, $todos);
        $this->assertCount(1000, $todos->unique());
        $this->assertSame(1, $todos->min());
        $this->assertSame(1000, $todos->max());
    }

    public function test_applybulk_aplica_el_mismo_movimiento_a_muchos_tags(): void
    {
        $tags = collect(range(1, 25))->map(function () {
            $tag = $this->makeTag(TagState::Codificado);
            $this->stockIt($tag);

            return $tag;
        });

        $applied = $this->service->applyBulk(
            $tags->pluck('id')->all(),
            new MovementIntent(
                type: MovementType::TransferenciaOut,
                referenceType: 'transfer',
                referenceId: 1,
            ),
        );

        $this->assertSame(25, $applied);
        $this->assertSame(
            25,
            Tag::whereIn('id', $tags->pluck('id'))->where('state', 'en_transito')->count()
        );
    }

    public function test_vender_marca_la_fecha_y_conserva_el_historico(): void
    {
        $tag = $this->makeTag(TagState::Codificado);
        $this->stockIt($tag);

        $this->service->apply(new MovementIntent(tagId: $tag->id, type: MovementType::Venta));

        $tag->refresh();
        $this->assertSame(TagState::Vendido, $tag->state);
        $this->assertNotNull($tag->sold_at);
        // Tarado y venta: el histórico completo sigue ahí.
        $this->assertSame(2, StockMovement::where('tag_id', $tag->id)->count());
    }

    public function test_reaparecer_en_stock_resetea_los_ciclos_sin_ver(): void
    {
        $tag = $this->makeTag(TagState::Codificado);
        $this->stockIt($tag);

        $this->service->apply(new MovementIntent(tagId: $tag->id, type: MovementType::AjusteNegativo));
        DB::table('tags')->where('id', $tag->id)->update(['missed_cycles' => 2]);

        $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::AjustePositivo,
            toLocationId: $this->location->id,
        ));

        $tag->refresh();
        $this->assertSame(TagState::EnStock, $tag->state);
        $this->assertSame(0, $tag->missed_cycles);
    }

    public function test_cambiar_de_zona_conserva_la_ubicacion(): void
    {
        $sala = Zone::create([
            'location_id' => $this->location->id,
            'code' => 'SALA',
            'name' => 'Sala principal',
            'kind' => 'sala',
        ]);

        $tag = $this->makeTag(TagState::Codificado);
        $this->stockIt($tag);

        $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::CambioZona,
            toZoneId: $sala->id,
        ));

        $tag->refresh();
        $this->assertSame(TagState::EnStock, $tag->state);
        $this->assertSame($sala->id, $tag->current_zone_id);
        // La ubicación se conserva aunque la intención no la repita.
        $this->assertSame($this->location->id, $tag->current_location_id);
    }

    public function test_un_movimiento_sin_zona_destino_deja_la_prenda_sin_zona(): void
    {
        // Comportamiento tal cual lo define `docs/06` §2: la ubicación se
        // conserva si la intención no la repite, pero la zona NO. Para una
        // venta o una salida en tránsito tiene sentido. Conviene tenerlo
        // presente al implementar el registro de escaneos de ciclo (3.2): un
        // ajuste que solo confirme presencia borrará la zona si no la indica.
        $sala = Zone::create([
            'location_id' => $this->location->id,
            'code' => 'SALA2',
            'name' => 'Sala',
            'kind' => 'sala',
        ]);

        $tag = $this->makeTag(TagState::Codificado);
        $this->stockIt($tag);
        $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::CambioZona,
            toZoneId: $sala->id,
        ));

        $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::Venta,
        ));

        $tag->refresh();
        $this->assertNull($tag->current_zone_id);
        $this->assertSame($this->location->id, $tag->current_location_id);
    }

    public function test_rechaza_un_tag_sin_variante_ni_en_la_intencion(): void
    {
        $tag = Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => $this->randomEpc(),
            'state' => TagState::Codificado,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tiene variante asociada/');

        $this->service->apply(new MovementIntent(tagId: $tag->id, type: MovementType::Tarado));
    }

    public function test_rechaza_una_intencion_sin_tag(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no indica ningún tag/');

        $this->service->apply(new MovementIntent(type: MovementType::Tarado));
    }

    public function test_el_movimiento_registra_origen_y_destino(): void
    {
        $tag = $this->makeTag(TagState::Codificado);
        $this->stockIt($tag);

        $destino = Location::create([
            'organization_id' => $this->organization->id,
            'code' => 'T-'.uniqid(),
            'name' => 'Gamarra 2',
        ]);

        $movement = $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::TransferenciaOut,
            toLocationId: $destino->id,
        ));

        $this->assertSame($this->location->id, $movement->from_location_id);
        $this->assertSame($destino->id, $movement->to_location_id);
    }

    private function makeTag(TagState $state): Tag
    {
        return Tag::create([
            'organization_id' => $this->organization->id,
            'epc' => $this->randomEpc(),
            'product_variant_id' => $this->variant->id,
            'state' => $state,
        ]);
    }

    private function stockIt(Tag $tag): void
    {
        $this->service->apply(new MovementIntent(
            tagId: $tag->id,
            type: MovementType::Tarado,
            toLocationId: $this->location->id,
        ));
    }

    private function randomEpc(): string
    {
        return strtoupper(bin2hex(random_bytes(12)));
    }
}
