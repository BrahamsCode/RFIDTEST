<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\ProductVariant;
use App\Models\TagBatch;
use App\Services\LabelBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/** Lotes de etiquetas y su ZPL. Tarea 4.6. */
final class LabelBatchController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly LabelBatchService $batches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = TagBatch::query()
            ->with('productVariant')
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->filled('variant'), fn ($q) => $q->where('product_variant_id', $request->integer('variant')))
            ->orderByDesc('id');

        return response()->json($this->paginated($query, $request, fn (TagBatch $b) => [
            'id' => $b->id,
            'code' => $b->code(),
            'sku' => $b->productVariant?->sku,
            'quantity' => $b->quantity,
            'serial_from' => $b->serial_from,
            'serial_to' => $b->serial_to,
            'printed_ok' => $b->printed_ok,
            'printed_void' => $b->printed_void,
            'void_rate' => $b->voidRate(),
            // Por encima del 1 % se reclama al proveedor de inlays.
            'void_rate_exceeded' => $b->voidRate() > LabelBatchService::VOID_RATE_THRESHOLD,
            'created_at' => $b->created_at,
            'completed_at' => $b->completed_at,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('label.print')) {
            return Problem::forbidden('No tienes permiso para emitir etiquetas.');
        }

        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.LabelBatchService::MAX_LABELS],
            'printer_device_id' => ['nullable', 'integer', 'exists:devices,id'],
        ]);

        $variant = ProductVariant::with('product')->findOrFail($data['product_variant_id']);

        if ($variant->product?->organization_id !== $request->user()->organization_id) {
            return Problem::forbidden('Esa variante no es de tu organización.');
        }

        try {
            $batch = $this->batches->create(
                variant: $variant,
                count: $data['quantity'],
                userId: $request->user()->id,
                printerId: $data['printer_device_id'] ?? null,
            );
        } catch (RuntimeException $e) {
            return Problem::unprocessable($e->getMessage());
        }

        return response()->json([
            'id' => $batch->id,
            'code' => $batch->code(),
            'sku' => $variant->sku,
            'quantity' => $batch->quantity,
            'serial_from' => $batch->serial_from,
            'serial_to' => $batch->serial_to,
            // El ZPL no viaja aquí: son cientos de KB para un lote grande y
            // la pantalla solo necesita confirmar y ofrecer la descarga.
            'zpl_url' => route('api.v1.labels.zpl', $batch),
        ], 201);
    }

    /**
     * Descarga del ZPL. Se sirve como fichero y no como JSON: va directo al
     * puerto 9100 de la impresora o al programa de la Zebra, y envolverlo en
     * JSON obligaría a desescaparlo a mano.
     */
    public function zpl(Request $request, TagBatch $tagBatch): Response
    {
        if ($tagBatch->organization_id !== $request->user()->organization_id) {
            abort(403, 'Ese lote no es de tu organización.');
        }

        return response($this->batches->zpl($tagBatch), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$tagBatch->code().'.zpl"',
        ]);
    }

    public function reprint(Request $request): Response
    {
        if (! $request->user()->hasPermission('label.print')) {
            abort(403, 'No tienes permiso para emitir etiquetas.');
        }

        $data = $request->validate([
            'epcs' => ['required', 'array', 'min:1', 'max:'.LabelBatchService::MAX_LABELS],
            'epcs.*' => ['string', 'regex:/^[0-9A-Fa-f]{16,48}$/'],
        ]);

        try {
            $zpl = $this->batches->reprint($data['epcs']);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response($zpl, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="reimpresion.zpl"',
        ]);
    }

    /** Cierre del lote con el recuento real de la impresora. */
    public function complete(Request $request, TagBatch $tagBatch): JsonResponse
    {
        if (! $request->user()->hasPermission('label.print')) {
            return Problem::forbidden('No tienes permiso para emitir etiquetas.');
        }

        $data = $request->validate([
            'printed_ok' => ['required', 'integer', 'min:0'],
            'printed_void' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $batch = $this->batches->complete($tagBatch, $data['printed_ok'], $data['printed_void']);
        } catch (RuntimeException $e) {
            return Problem::unprocessable($e->getMessage());
        }

        return response()->json([
            'id' => $batch->id,
            'code' => $batch->code(),
            'printed_ok' => $batch->printed_ok,
            'printed_void' => $batch->printed_void,
            'void_rate' => $batch->voidRate(),
            'void_rate_exceeded' => $batch->voidRate() > LabelBatchService::VOID_RATE_THRESHOLD,
        ]);
    }
}
