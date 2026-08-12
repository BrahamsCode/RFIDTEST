<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Movements\MovementIntent;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\ReceivingOrder;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReceivingService
{
    /**
     * Por encima de esta diferencia el sistema exige confirmación de un
     * supervisor antes de aceptar. Ver P02 de `docs/10`.
     */
    private const SUPERVISOR_THRESHOLD_PCT = 3.0;

    public function __construct(
        private readonly StockMovementService $movements,
    ) {}

    /**
     * Registra una pasada del bulto por el túnel. No cierra la orden: el
     * operario debe poder repetir la lectura, porque una diferencia de 1 o 2
     * unidades es más probable que sea un fallo de lectura que un faltante.
     *
     * @param  list<string>  $epcs
     * @return array{matched: int, unknown: int, foreign: int}
     */
    public function recordPass(ReceivingOrder $order, array $epcs, ?int $deviceId = null): array
    {
        $epcs = array_values(array_unique(array_map('strtoupper', $epcs)));

        $tags = Tag::query()
            ->where('organization_id', $order->organization_id)
            ->whereIn('epc', $epcs)
            ->get();

        $expectedVariants = $order->lines()->pluck('product_variant_id')->all();

        // Una prenda de un SKU que no está en la orden no cuenta como
        // recibida: o llegó mercadería equivocada, o el bulto es otro.
        $matched = $tags->filter(
            fn (Tag $t) => in_array($t->product_variant_id, $expectedVariants, strict: true)
        );

        DB::transaction(function () use ($order, $matched, $deviceId): void {
            $byVariant = $matched->groupBy('product_variant_id');

            foreach ($byVariant as $variantId => $variantTags) {
                $order->lines()
                    ->where('product_variant_id', $variantId)
                    ->update(['received_qty' => $variantTags->count()]);
            }

            // Solo se dan de alta las que aún no están en stock: repetir la
            // pasada no debe duplicar movimientos.
            $toReceive = $matched
                ->filter(fn (Tag $t) => $t->state !== TagState::EnStock)
                ->pluck('id')
                ->all();

            $this->movements->applyBulk($toReceive, new MovementIntent(
                type: MovementType::Recepcion,
                toLocationId: $order->location_id,
                deviceId: $deviceId,
                referenceType: 'receiving_order',
                referenceId: $order->id,
                reason: "Recepción de la orden {$order->code}",
            ));
        });

        return [
            'matched' => $matched->count(),
            'unknown' => count($epcs) - $tags->count(),
            'foreign' => $tags->count() - $matched->count(),
        ];
    }

    /**
     * Diferencias por variante entre lo pedido y lo leído.
     *
     * @return array{
     *     lines: list<array{sku: string, expected: int, received: int, difference: int}>,
     *     expected_total: int,
     *     received_total: int,
     *     difference_pct: float,
     *     requires_supervisor: bool
     * }
     */
    public function differences(ReceivingOrder $order): array
    {
        $lines = $order->lines()->with('productVariant')->get();

        $rows = $lines->map(fn ($line) => [
            'sku' => $line->productVariant?->sku ?? '(sin SKU)',
            'expected' => $line->expected_qty,
            'received' => $line->received_qty,
            'difference' => $line->received_qty - $line->expected_qty,
        ])->all();

        $expectedTotal = (int) $lines->sum('expected_qty');
        $receivedTotal = (int) $lines->sum('received_qty');

        $differencePct = $expectedTotal > 0
            ? round(100 * abs($receivedTotal - $expectedTotal) / $expectedTotal, 3)
            : 0.0;

        return [
            'lines' => $rows,
            'expected_total' => $expectedTotal,
            'received_total' => $receivedTotal,
            'difference_pct' => $differencePct,
            'requires_supervisor' => $differencePct > self::SUPERVISOR_THRESHOLD_PCT,
        ];
    }

    /**
     * Cierra la orden. Si la diferencia supera el umbral, exige que un
     * supervisor lo confirme explícitamente.
     */
    public function accept(ReceivingOrder $order, int $userId, bool $supervisorApproval = false): ReceivingOrder
    {
        $differences = $this->differences($order);

        if ($differences['requires_supervisor'] && ! $supervisorApproval) {
            throw new RuntimeException(sprintf(
                'La diferencia es del %.1f %%, por encima del %.1f %% permitido. '
                .'Repita la lectura o pida confirmación de un supervisor.',
                $differences['difference_pct'],
                self::SUPERVISOR_THRESHOLD_PCT,
            ));
        }

        $order->update([
            'status' => $differences['received_total'] === $differences['expected_total']
                ? 'recibida'
                : 'recibida_con_diferencia',
            'received_at' => now(),
            'received_by' => $userId,
        ]);

        return $order->refresh();
    }
}
