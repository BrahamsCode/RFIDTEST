<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\Exceptions\InvalidTransition;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\TagState;
use App\Models\StockMovement;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Único punto autorizado para mutar stock.
 *
 * REGLA ARQUITECTÓNICA INVIOLABLE: ninguna otra clase escribe en
 * `stock_movements` ni modifica `tags.state`, `tags.current_location_id` o
 * `tags.current_zone_id`. Si necesitas mover stock, pasas por aquí. Sin
 * excepciones. Ver `docs/06` §2.
 */
final class StockMovementService
{
    /** Tamaño de lote de `applyBulk()`: evita miles de locks abiertos a la vez. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly TagStateMachine $stateMachine,
    ) {}

    /**
     * Aplica un movimiento sobre un tag concreto. Escribe el movimiento y
     * actualiza la proyección en una sola transacción.
     */
    public function apply(MovementIntent $intent): StockMovement
    {
        if ($intent->tagId === null) {
            throw new RuntimeException('La intención de movimiento no indica ningún tag.');
        }

        return DB::transaction(function () use ($intent): StockMovement {
            // Bloqueo pesimista: dos operarios pueden escanear la misma prenda
            // a la vez (uno en caja, otro haciendo inventario).
            $tag = Tag::query()
                ->whereKey($intent->tagId)
                ->lockForUpdate()
                ->firstOrFail();

            $stateBefore = $tag->state;
            $stateAfter = $intent->targetState
                ?? $this->stateMachine->resolve($stateBefore, $intent->type);

            if (! $this->stateMachine->canTransition($stateBefore, $stateAfter)) {
                throw InvalidTransition::between(
                    $stateBefore,
                    $stateAfter,
                    "movimiento: {$intent->type->value}, EPC: {$tag->epc}"
                );
            }

            $variantId = $tag->product_variant_id ?? $intent->productVariantId;

            if ($variantId === null) {
                // `stock_movements.product_variant_id` es NOT NULL: sin variante
                // el movimiento no se puede registrar y el tag quedaría mutado
                // sin rastro, que es justo lo que el append-only impide.
                throw new RuntimeException(
                    "El tag {$tag->epc} no tiene variante asociada y la intención tampoco la indica."
                );
            }

            $occurredAt = $intent->occurredAt ?? now();

            $movement = StockMovement::create([
                'organization_id' => $tag->organization_id,
                'tag_id' => $tag->id,
                'product_variant_id' => $variantId,
                'movement_type' => $intent->type,
                'quantity' => $intent->quantity,
                'from_location_id' => $tag->current_location_id,
                'from_zone_id' => $tag->current_zone_id,
                'to_location_id' => $intent->toLocationId,
                'to_zone_id' => $intent->toZoneId,
                'state_before' => $stateBefore,
                'state_after' => $stateAfter,
                'user_id' => $intent->userId,
                'device_id' => $intent->deviceId,
                'reference_type' => $intent->referenceType,
                'reference_id' => $intent->referenceId,
                'reason' => $intent->reason,
                'unit_cost' => $intent->unitCost,
                'metadata' => $intent->metadata,
                'occurred_at' => $occurredAt,
            ]);

            // Proyección síncrona. Ver `docs/05` §2.1.
            $tag->fill([
                'state' => $stateAfter,
                'current_location_id' => $intent->toLocationId ?? $tag->current_location_id,
                'current_zone_id' => $intent->toZoneId,
                'last_seen_at' => $occurredAt,
            ]);

            if ($stateAfter === TagState::Vendido) {
                $tag->sold_at = $occurredAt;
            }

            if ($stateAfter === TagState::EnStock) {
                // Volver a verla resetea la cuenta de ciclos sin detectar.
                $tag->missed_cycles = 0;
            }

            if ($tag->first_seen_at === null) {
                $tag->first_seen_at = $occurredAt;
            }

            $tag->save();

            return $movement;
        }, attempts: 3);
    }

    /**
     * Aplica el mismo movimiento a muchos tags. Se usa en recepción,
     * transferencias y cierre de ciclo, donde N puede ser de miles.
     *
     * @param  list<int>  $tagIds
     * @return int  número de movimientos aplicados
     */
    public function applyBulk(array $tagIds, MovementIntent $template): int
    {
        $applied = 0;

        // Por trozos para no mantener miles de locks de fila abiertos durante
        // toda la operación.
        foreach (array_chunk($tagIds, self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use ($chunk, $template, &$applied): void {
                foreach ($chunk as $tagId) {
                    $this->apply($template->forTag($tagId));
                    $applied++;
                }
            });
        }

        return $applied;
    }
}
