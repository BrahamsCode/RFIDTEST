<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Movements\MovementIntent;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Location;
use App\Models\SaleLine;
use App\Models\SaleTransaction;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SaleService
{
    public function __construct(
        private readonly StockMovementService $movements,
    ) {}

    /**
     * Registra una venta por EPC.
     *
     * Los EPC desconocidos no bloquean la venta: pueden ser prendas que el
     * cliente traía de otra tienda. Se ignoran y se informa de cuántos hubo.
     * El RFID nunca impide cobrar. Ver P06 de `docs/10`.
     *
     * @param  list<string>  $epcs
     * @return array{sale: SaleTransaction, sold: int, ignored: int}
     */
    public function sell(
        Location $location,
        string $code,
        array $epcs,
        ?int $userId = null,
        ?string $externalRef = null,
    ): array {
        $tags = $this->resolve($location->organization_id, $epcs);

        // Solo se puede vender lo que está en la tienda. Una prenda ya
        // vendida o en tránsito no vuelve a cobrarse.
        $sellable = $tags->filter(
            fn (Tag $t) => in_array($t->state, [TagState::EnStock, TagState::NoVisto], strict: true)
        );

        return DB::transaction(function () use ($location, $code, $epcs, $userId, $externalRef, $tags, $sellable): array {
            $sale = SaleTransaction::create([
                'organization_id' => $location->organization_id,
                'location_id' => $location->id,
                'code' => $code,
                'external_ref' => $externalRef,
                'currency' => 'PEN',
                'sold_at' => now(),
                'user_id' => $userId,
                'total_amount' => $sellable->sum(
                    fn (Tag $t) => (float) ($t->productVariant?->sale_price ?? 0)
                ),
            ]);

            foreach ($sellable as $tag) {
                SaleLine::create([
                    'sale_transaction_id' => $sale->id,
                    'tag_id' => $tag->id,
                    'product_variant_id' => $tag->product_variant_id,
                    'quantity' => 1,
                    'unit_price' => $tag->productVariant?->sale_price,
                ]);
            }

            $this->movements->applyBulk($sellable->pluck('id')->all(), new MovementIntent(
                type: MovementType::Venta,
                userId: $userId,
                referenceType: 'sale',
                referenceId: $sale->id,
                reason: "Venta {$code}",
            ));

            return [
                'sale' => $sale->refresh(),
                'sold' => $sellable->count(),
                'ignored' => count(array_unique($epcs)) - $sellable->count(),
            ];
        });
    }

    /**
     * Devolución de cliente, verificando que la prenda es exactamente la que
     * se vendió.
     *
     * Ese es el valor real del RFID en caja: es la defensa contra la
     * devolución de prenda usada o de otra procedencia. Si el tag no
     * corresponde a ninguna venta, se rechaza con un mensaje claro.
     */
    public function acceptReturn(
        Location $location,
        string $epc,
        string $code,
        ?int $userId = null,
    ): SaleTransaction {
        $epc = strtoupper($epc);

        $tag = Tag::query()
            ->where('organization_id', $location->organization_id)
            ->where('epc', $epc)
            ->first();

        if ($tag === null) {
            throw new RuntimeException(
                "El EPC {$epc} no pertenece a esta organización. No se puede aceptar la devolución."
            );
        }

        $originalLine = SaleLine::query()
            ->where('tag_id', $tag->id)
            ->whereHas('saleTransaction', fn ($q) => $q->where('is_return', false))
            ->latest('id')
            ->first();

        if ($originalLine === null) {
            throw new RuntimeException(
                "La prenda {$epc} no consta como vendida. No se puede aceptar la devolución."
            );
        }

        if ($tag->state !== TagState::Vendido) {
            throw new RuntimeException(sprintf(
                'La prenda %s está en estado "%s", no "vendido". No se puede devolver.',
                $epc,
                $tag->state->value,
            ));
        }

        return DB::transaction(function () use ($location, $code, $userId, $tag, $originalLine): SaleTransaction {
            $return = SaleTransaction::create([
                'organization_id' => $location->organization_id,
                'location_id' => $location->id,
                'code' => $code,
                'currency' => 'PEN',
                'sold_at' => now(),
                'user_id' => $userId,
                'is_return' => true,
                'original_sale_id' => $originalLine->sale_transaction_id,
                'total_amount' => $originalLine->unit_price === null
                    ? null
                    : -1 * (float) $originalLine->unit_price,
            ]);

            SaleLine::create([
                'sale_transaction_id' => $return->id,
                'tag_id' => $tag->id,
                'product_variant_id' => $tag->product_variant_id,
                'quantity' => -1,
                'unit_price' => $originalLine->unit_price,
            ]);

            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::DevolucionCliente,
                toLocationId: $location->id,
                userId: $userId,
                referenceType: 'sale',
                referenceId: $return->id,
                reason: "Devolución {$code}",
            ));

            return $return->refresh();
        });
    }

    /** Detalle de la venta original de una prenda, para mostrarlo en caja. */
    public function saleInfoFor(Tag $tag): ?array
    {
        $line = SaleLine::query()
            ->with('saleTransaction')
            ->where('tag_id', $tag->id)
            ->whereHas('saleTransaction', fn ($q) => $q->where('is_return', false))
            ->latest('id')
            ->first();

        if ($line === null) {
            return null;
        }

        return [
            'sale_code' => $line->saleTransaction->code,
            'external_ref' => $line->saleTransaction->external_ref,
            'sold_at' => $line->saleTransaction->sold_at,
            'unit_price' => $line->unit_price,
        ];
    }

    /**
     * @param  list<string>  $epcs
     * @return \Illuminate\Support\Collection<int, Tag>
     */
    private function resolve(int $organizationId, array $epcs): \Illuminate\Support\Collection
    {
        return Tag::query()
            ->with('productVariant')
            ->where('organization_id', $organizationId)
            ->whereIn('epc', array_values(array_unique(array_map('strtoupper', $epcs))))
            ->get();
    }
}
