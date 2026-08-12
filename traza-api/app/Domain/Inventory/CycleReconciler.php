<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Movements\MovementIntent;
use App\Enums\CycleStatus;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\InventoryCycle;
use App\Models\Tag;
use App\Services\StockMovementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Concilia un ciclo de inventario. Ver `docs/06` §5.
 *
 * Las tres poblaciones se calculan en SQL, no en PHP: con 20 000 prendas,
 * traerlas a memoria y comparar arrays es órdenes de magnitud más lento y
 * consume cientos de MB.
 */
final class CycleReconciler
{
    public function __construct(
        private readonly StockMovementService $movements,
    ) {}

    public function reconcile(InventoryCycle $cycle): ReconciliationResult
    {
        return DB::transaction(function () use ($cycle): ReconciliationResult {
            $found = $this->countFound($cycle);
            $missingIds = $this->missingTagIds($cycle);
            $unexpectedIds = $this->unexpectedTagIds($cycle);

            $this->applyFound($cycle);
            $declaredLost = $this->applyMissing($cycle, $missingIds);
            $this->applyUnexpected($cycle, $unexpectedIds);
            $this->writeResults($cycle);

            $result = new ReconciliationResult(
                found: $found,
                missing: $missingIds->count(),
                unexpected: $unexpectedIds->count(),
                declaredLost: $declaredLost,
            );

            $cycle->update([
                'status' => CycleStatus::Cerrado,
                'closed_at' => now(),
                'found_count' => $result->found,
                'missing_count' => $result->missing,
                'unexpected_count' => $result->unexpected,
                'counted_count' => $result->counted(),
                'accuracy_pct' => $result->accuracyPct($cycle->expected_count ?? 0),
            ]);

            return $result;
        });
    }

    /** esperados ∩ contados */
    private function countFound(InventoryCycle $cycle): int
    {
        return DB::table('inventory_cycle_expected as e')
            ->join('inventory_cycle_scans as s', function ($join) use ($cycle): void {
                $join->on('s.tag_id', '=', 'e.tag_id')
                    ->where('s.inventory_cycle_id', '=', $cycle->id);
            })
            ->where('e.inventory_cycle_id', $cycle->id)
            ->count();
    }

    /**
     * esperados − contados
     *
     * @return Collection<int, int>
     */
    private function missingTagIds(InventoryCycle $cycle): Collection
    {
        return DB::table('inventory_cycle_expected as e')
            ->where('e.inventory_cycle_id', $cycle->id)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('inventory_cycle_scans as s')
                ->whereColumn('s.tag_id', 'e.tag_id')
                ->where('s.inventory_cycle_id', $cycle->id))
            ->pluck('e.tag_id');
    }

    /**
     * contados − esperados
     *
     * @return Collection<int, int>
     */
    private function unexpectedTagIds(InventoryCycle $cycle): Collection
    {
        return DB::table('inventory_cycle_scans as s')
            ->where('s.inventory_cycle_id', $cycle->id)
            ->whereNotNull('s.tag_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('inventory_cycle_expected as e')
                ->whereColumn('e.tag_id', 's.tag_id')
                ->where('e.inventory_cycle_id', $cycle->id))
            ->pluck('s.tag_id');
    }

    /**
     * Una prenda que estaba en `no_visto` y vuelve a detectarse regresa a
     * `en_stock`, lo que resetea su contador de ciclos sin ver.
     *
     * `docs/05` §2.4 dice "encontrados → sin acción", pero eso deja un hueco:
     * como los esperados incluyen `no_visto`, una prenda reencontrada seguiría
     * con `missed_cycles = 1`, y al primer ciclo posterior que fallara la
     * lectura se declararía merma. Es decir, se perdería stock real que se
     * había visto hace un ciclo. `docs/02` §8 sí recoge la transición
     * `no_visto → en_stock (reaparece)`, así que se aplica esa.
     */
    private function applyFound(InventoryCycle $cycle): void
    {
        $reappeared = DB::table('inventory_cycle_expected as e')
            ->join('inventory_cycle_scans as s', function ($join) use ($cycle): void {
                $join->on('s.tag_id', '=', 'e.tag_id')
                    ->where('s.inventory_cycle_id', '=', $cycle->id);
            })
            ->join('tags as t', 't.id', '=', 'e.tag_id')
            ->where('e.inventory_cycle_id', $cycle->id)
            ->where('t.state', TagState::NoVisto->value)
            ->pluck('e.tag_id');

        $this->movements->applyBulk($reappeared->all(), new MovementIntent(
            type: MovementType::AjustePositivo,
            toLocationId: $cycle->location_id,
            referenceType: 'inventory_cycle',
            referenceId: $cycle->id,
            reason: 'Reaparece en el ciclo',
        ));
    }

    /**
     * Los faltantes NO se dan por perdidos de inmediato: se incrementa
     * `missed_cycles` y solo tras N ciclos consecutivos pasan a `perdido`.
     * Un solo fallo de lectura no puede borrar stock que existe.
     *
     * @param  Collection<int, int>  $tagIds
     * @return int  cuántos se declararon perdidos
     */
    private function applyMissing(InventoryCycle $cycle, Collection $tagIds): int
    {
        if ($tagIds->isEmpty()) {
            return 0;
        }

        $threshold = (int) config('traza.inventory.missing_cycles_threshold', 2);
        $ids = $tagIds->all();

        Tag::whereIn('id', $ids)->increment('missed_cycles');

        // Solo puede declararse perdido lo que ya estaba en `no_visto` o
        // `en_transito`: la máquina de estados prohíbe ir de `en_stock` a
        // `perdido` de un salto, que es la garantía de "nunca en el primer
        // ciclo" a nivel de dominio.
        $toLose = Tag::whereIn('id', $ids)
            ->where('missed_cycles', '>=', $threshold)
            ->whereIn('state', [TagState::NoVisto->value, TagState::EnTransito->value])
            ->pluck('id');

        $this->movements->applyBulk($toLose->all(), new MovementIntent(
            type: MovementType::Merma,
            toLocationId: $cycle->location_id,
            referenceType: 'inventory_cycle',
            referenceId: $cycle->id,
            reason: "No detectado en {$threshold} ciclos consecutivos",
        ));

        // Los que aún no llegan al umbral pasan a `no_visto`.
        $notSeen = Tag::whereIn('id', $ids)
            ->where('state', TagState::EnStock->value)
            ->pluck('id');

        $this->movements->applyBulk($notSeen->all(), new MovementIntent(
            type: MovementType::AjusteNegativo,
            toLocationId: $cycle->location_id,
            referenceType: 'inventory_cycle',
            referenceId: $cycle->id,
            reason: 'No detectado en el ciclo',
        ));

        return $toLose->count();
    }

    /**
     * Apareció algo que no se esperaba: o llegó sin registrar la recepción, o
     * es una transferencia no anotada, o reaparece una prenda dada por
     * perdida. En todos los casos entra a stock con un ajuste positivo.
     *
     * @param  Collection<int, int>  $tagIds
     */
    private function applyUnexpected(InventoryCycle $cycle, Collection $tagIds): void
    {
        if ($tagIds->isEmpty()) {
            return;
        }

        // Las que ya están en stock en esta ubicación no necesitan ajuste: el
        // ciclo no las esperaba por su zona, no por su existencia.
        $needAdjustment = Tag::whereIn('id', $tagIds->all())
            ->where(function ($q) use ($cycle): void {
                $q->where('state', '!=', TagState::EnStock->value)
                    ->orWhere('current_location_id', '!=', $cycle->location_id)
                    ->orWhereNull('current_location_id');
            })
            ->pluck('id');

        $this->movements->applyBulk($needAdjustment->all(), new MovementIntent(
            type: MovementType::AjustePositivo,
            toLocationId: $cycle->location_id,
            referenceType: 'inventory_cycle',
            referenceId: $cycle->id,
            reason: 'Detectado en ciclo sin estar esperado',
        ));
    }

    /** Resultado por variante, con la diferencia y su valorización. */
    private function writeResults(InventoryCycle $cycle): void
    {
        DB::table('inventory_cycle_results')
            ->where('inventory_cycle_id', $cycle->id)
            ->delete();

        DB::statement(<<<'SQL'
            INSERT INTO inventory_cycle_results
                (inventory_cycle_id, product_variant_id, expected_qty, counted_qty, value_difference)
            SELECT
                ?::bigint,
                v.id,
                coalesce(e.qty, 0),
                coalesce(s.qty, 0),
                (coalesce(s.qty, 0) - coalesce(e.qty, 0)) * coalesce(v.cost_price, 0)
            FROM product_variants v
            LEFT JOIN (
                SELECT product_variant_id, count(*) AS qty
                  FROM inventory_cycle_expected
                 WHERE inventory_cycle_id = ?
                 GROUP BY product_variant_id
            ) e ON e.product_variant_id = v.id
            LEFT JOIN (
                SELECT t.product_variant_id, count(*) AS qty
                  FROM inventory_cycle_scans sc
                  JOIN tags t ON t.id = sc.tag_id
                 WHERE sc.inventory_cycle_id = ?
                 GROUP BY t.product_variant_id
            ) s ON s.product_variant_id = v.id
            WHERE e.qty IS NOT NULL OR s.qty IS NOT NULL
        SQL, [$cycle->id, $cycle->id, $cycle->id]);
    }
}
