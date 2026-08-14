<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Labels\ZplRenderer;
use App\Enums\TagState;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\TagBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lotes de etiquetas. Tarea 4.6, con `docs/04` §5 para el ZPL.
 *
 * Los tags se crean en estado `creado` **antes** de imprimir, y el orden
 * importa: al revés, un corte de luz a mitad de rollo dejaría etiquetas
 * físicas con EPC que el sistema no conoce, y esas prendas serían invisibles
 * en el primer inventario. Con este orden el fallo es benigno — sobran filas
 * en `creado` que nunca llegan a stock, y se ven en el lote.
 */
final class LabelBatchService
{
    /** Un rollo de colgantes ronda las 1 000 etiquetas; más de 5 000 no es un lote. */
    public const MAX_LABELS = 5000;

    /** Por encima de esto se reclama al proveedor de inlays (`docs/04` §5). */
    public const VOID_RATE_THRESHOLD = 1.0;

    public function __construct(
        private readonly SerialReservationService $serials,
        private readonly ZplRenderer $zpl,
    ) {}

    public function create(
        ProductVariant $variant,
        int $count,
        ?int $userId = null,
        ?int $printerId = null,
    ): TagBatch {
        if ($count <= 0) {
            throw new RuntimeException('El lote debe tener al menos una etiqueta.');
        }

        if ($count > self::MAX_LABELS) {
            throw new RuntimeException(
                'Un lote no puede pasar de '.self::MAX_LABELS." etiquetas; se pidieron {$count}."
            );
        }

        $organizationId = DB::table('products')
            ->where('id', $variant->product_id)
            ->value('organization_id');

        if ($organizationId === null) {
            throw new RuntimeException("La variante {$variant->sku} no tiene producto asociado.");
        }

        return DB::transaction(function () use ($variant, $count, $organizationId, $userId, $printerId): TagBatch {
            $range = $this->serials->reserve($variant, $count);
            $epcs = $this->serials->encodeRange($variant, $range);

            $batch = TagBatch::create([
                'organization_id' => $organizationId,
                'product_variant_id' => $variant->id,
                'device_id' => $printerId,
                'created_by' => $userId,
                'quantity' => $count,
                'serial_from' => $range->from,
                'serial_to' => $range->to,
            ]);

            $now = now();
            $rows = array_map(fn (string $epc) => [
                'organization_id' => $organizationId,
                'epc' => $epc,
                'epc_scheme' => (string) config('traza.epc.scheme', 'sgtin-96'),
                'product_variant_id' => $variant->id,
                'tag_batch_id' => $batch->id,
                'state' => TagState::Creado->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $epcs);

            // En trozos: 5 000 filas en un solo INSERT desbordan el límite de
            // parámetros del driver.
            foreach (array_chunk($rows, 500) as $chunk) {
                Tag::insert($chunk);
            }

            return $batch;
        });
    }

    /** ZPL del lote entero, listo para el puerto 9100 de la impresora. */
    public function zpl(TagBatch $batch): string
    {
        $variant = $batch->productVariant;
        $item = $this->itemFor($variant);

        $epcs = Tag::query()
            ->where('tag_batch_id', $batch->id)
            ->orderBy('id')
            ->pluck('epc');

        return $this->zpl->batch(
            $epcs->map(fn (string $epc) => ['epc' => $epc, 'item' => $item])->all(),
        ).$this->zpl->queryResult();
    }

    /**
     * Reimpresión de EPC concretos, sin reservar nada.
     *
     * Es lo que hace falta cuando la impresora se atasca a mitad de rollo:
     * volver a emitir el lote crearía tags nuevos y duplicaría el inventario
     * de esa variante.
     *
     * @param  list<string>  $epcs
     */
    public function reprint(array $epcs): string
    {
        $wanted = array_map(strtoupper(...), $epcs);

        $tags = Tag::query()
            ->with('productVariant.product')
            ->whereIn('epc', $wanted)
            ->get()
            ->keyBy('epc');

        $missing = array_values(array_diff($wanted, $tags->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException(
                'Estos EPC no están emitidos y no se pueden reimprimir: '
                .implode(', ', array_slice($missing, 0, 5))
                .(count($missing) > 5 ? ' …' : '')
            );
        }

        return $this->zpl->batch(
            array_map(fn (string $epc) => [
                'epc' => $epc,
                'item' => $this->itemFor($tags[$epc]->productVariant),
            ], $wanted),
        );
    }

    /**
     * Cierra el lote con el recuento real de la impresora.
     *
     * Los VOID **no se descartan del sistema**: sus tags quedan en `creado` y
     * nunca llegan a stock, que es exactamente lo que representan — etiquetas
     * impresas que hay que tirar a la basura.
     */
    public function complete(TagBatch $batch, int $printedOk, int $printedVoid): TagBatch
    {
        if ($printedOk + $printedVoid > $batch->quantity) {
            throw new RuntimeException(
                "El lote {$batch->code()} tiene {$batch->quantity} etiquetas y se reportan "
                .($printedOk + $printedVoid).'.'
            );
        }

        $batch->forceFill([
            'printed_ok' => $printedOk,
            'printed_void' => $printedVoid,
            'completed_at' => now(),
        ])->save();

        return $batch;
    }

    /** @return array<string, mixed> */
    private function itemFor(?ProductVariant $variant): array
    {
        if ($variant === null) {
            return ['name' => 'SIN PRODUCTO', 'sku' => '-'];
        }

        return [
            'name' => (string) ($variant->product?->name ?? $variant->sku),
            'sku' => (string) $variant->sku,
            'size' => $variant->size,
            'color' => $variant->color,
            'price' => $variant->sale_price === null ? null : (float) $variant->sale_price,
            'currency' => $variant->currency === 'PEN' ? 'S/' : (string) $variant->currency,
            'barcode' => $variant->barcode,
        ];
    }
}
