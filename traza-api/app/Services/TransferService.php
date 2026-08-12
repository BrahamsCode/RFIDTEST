<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Movements\MovementIntent;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Location;
use App\Models\Tag;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TransferService
{
    /** Días sin recibirse tras los que se alerta. Ver P08 de `docs/10`. */
    public const STALE_DAYS = 7;

    public function __construct(
        private readonly StockMovementService $movements,
    ) {}

    public function create(Location $from, Location $to, string $code, ?int $userId = null): Transfer
    {
        if ($from->id === $to->id) {
            throw new RuntimeException('El origen y el destino de una transferencia no pueden coincidir.');
        }

        return Transfer::create([
            'organization_id' => $from->organization_id,
            'code' => $code,
            'from_location_id' => $from->id,
            'to_location_id' => $to->id,
            'status' => 'preparando',
            'dispatched_by' => $userId,
        ]);
    }

    /**
     * Despacha: los tags barridos pasan a `en_transito`.
     *
     * @param  list<string>  $epcs
     * @return int  prendas despachadas
     */
    public function dispatch(Transfer $transfer, array $epcs, ?int $userId = null): int
    {
        if ($transfer->status !== 'preparando') {
            throw new RuntimeException("La transferencia {$transfer->code} ya fue despachada.");
        }

        $tags = $this->resolve($transfer, $epcs)
            ->filter(fn (Tag $t) => $t->state === TagState::EnStock);

        return DB::transaction(function () use ($transfer, $tags, $userId): int {
            $this->movements->applyBulk($tags->pluck('id')->all(), new MovementIntent(
                type: MovementType::TransferenciaOut,
                toLocationId: $transfer->to_location_id,
                userId: $userId,
                referenceType: 'transfer',
                referenceId: $transfer->id,
                reason: "Despacho de la transferencia {$transfer->code}",
            ));

            $rows = $tags->map(fn (Tag $t) => [
                'transfer_id' => $transfer->id,
                'tag_id' => $t->id,
                'dispatched' => true,
                'received' => false,
            ])->all();

            if ($rows !== []) {
                DB::table('transfer_tags')->upsert($rows, ['transfer_id', 'tag_id'], ['dispatched']);
            }

            $transfer->update([
                'status' => 'en_transito',
                'dispatched_at' => now(),
                'dispatched_by' => $userId ?? $transfer->dispatched_by,
            ]);

            return $tags->count();
        });
    }

    /**
     * Recibe en destino. Compara enviado contra recibido: lo que salió y no
     * llegó se queda en `en_transito`, que es como se detectan las pérdidas
     * en transporte.
     *
     * @param  list<string>  $epcs
     * @return array{received: int, missing: int, unexpected: int}
     */
    public function receive(Transfer $transfer, array $epcs, ?int $userId = null): array
    {
        if ($transfer->status !== 'en_transito') {
            throw new RuntimeException("La transferencia {$transfer->code} no está en tránsito.");
        }

        $scanned = $this->resolve($transfer, $epcs);
        $dispatchedIds = DB::table('transfer_tags')
            ->where('transfer_id', $transfer->id)
            ->where('dispatched', true)
            ->pluck('tag_id');

        $receivedIds = $scanned->pluck('id')->intersect($dispatchedIds);
        $unexpected = $scanned->pluck('id')->diff($dispatchedIds);

        return DB::transaction(function () use ($transfer, $receivedIds, $dispatchedIds, $unexpected, $userId): array {
            $this->movements->applyBulk($receivedIds->all(), new MovementIntent(
                type: MovementType::TransferenciaIn,
                toLocationId: $transfer->to_location_id,
                userId: $userId,
                referenceType: 'transfer',
                referenceId: $transfer->id,
                reason: "Recepción de la transferencia {$transfer->code}",
            ));

            if ($receivedIds->isNotEmpty()) {
                DB::table('transfer_tags')
                    ->where('transfer_id', $transfer->id)
                    ->whereIn('tag_id', $receivedIds->all())
                    ->update(['received' => true]);
            }

            $missing = $dispatchedIds->diff($receivedIds)->count();

            $transfer->update([
                'status' => $missing === 0 ? 'recibida' : 'recibida_con_diferencia',
                'received_at' => now(),
                'received_by' => $userId,
            ]);

            return [
                'received' => $receivedIds->count(),
                'missing' => $missing,
                'unexpected' => $unexpected->count(),
            ];
        });
    }

    /**
     * Transferencias despachadas hace más de una semana y aún sin recibir.
     * Las pérdidas en transporte son invisibles si nadie las busca.
     *
     * @return \Illuminate\Support\Collection<int, Transfer>
     */
    public function stale(): \Illuminate\Support\Collection
    {
        return Transfer::query()
            ->where('status', 'en_transito')
            ->where('dispatched_at', '<=', now()->subDays(self::STALE_DAYS))
            ->get();
    }

    /**
     * @param  list<string>  $epcs
     * @return \Illuminate\Support\Collection<int, Tag>
     */
    private function resolve(Transfer $transfer, array $epcs): \Illuminate\Support\Collection
    {
        return Tag::query()
            ->where('organization_id', $transfer->organization_id)
            ->whereIn('epc', array_map('strtoupper', $epcs))
            ->get();
    }
}
