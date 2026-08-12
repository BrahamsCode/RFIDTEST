<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\Location;
use App\Models\ReceivingOrder;
use App\Services\ReceivingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ReceivingOrderController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly ReceivingService $receiving,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ReceivingOrder::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
            ->orderByDesc('id');

        return response()->json($this->paginated($query, $request, fn (ReceivingOrder $o) => [
            'id' => $o->id,
            'code' => $o->code,
            'status' => $o->status,
            'external_ref' => $o->external_ref,
            'expected_at' => $o->expected_at,
            'received_at' => $o->received_at,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'code' => ['required', 'string', 'max:32'],
            'external_ref' => ['nullable', 'string', 'max:64'],
            'expected_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.expected_qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $location = Location::findOrFail($data['location_id']);

        $order = ReceivingOrder::create([
            'organization_id' => $location->organization_id,
            'supplier_id' => $data['supplier_id'] ?? null,
            'location_id' => $location->id,
            'code' => $data['code'],
            'external_ref' => $data['external_ref'] ?? null,
            'expected_at' => $data['expected_at'] ?? null,
            'status' => 'pendiente',
        ]);

        foreach ($data['lines'] as $line) {
            $order->lines()->create($line);
        }

        return response()->json($this->present($order), 201);
    }

    public function show(ReceivingOrder $receivingOrder): JsonResponse
    {
        return response()->json($this->present($receivingOrder));
    }

    /**
     * Una pasada del bulto. Con `accept = false` (por defecto) solo registra
     * lo leído y devuelve las diferencias, para que el operario pueda repetir
     * la lectura antes de reclamar al proveedor. Ver P02 de `docs/10`.
     */
    public function receive(Request $request, ReceivingOrder $receivingOrder): JsonResponse
    {
        $data = $request->validate([
            'epcs' => ['required', 'array', 'min:1', 'max:5000'],
            'epcs.*' => ['string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
            'accept' => ['sometimes', 'boolean'],
            'supervisor_approval' => ['sometimes', 'boolean'],
        ]);

        if ($receivingOrder->received_at !== null) {
            return Problem::conflict("La orden {$receivingOrder->code} ya está cerrada.");
        }

        $pass = $this->receiving->recordPass($receivingOrder, $data['epcs']);

        if ($request->boolean('accept')) {
            try {
                $this->receiving->accept(
                    $receivingOrder,
                    (int) $request->user()?->id,
                    $request->boolean('supervisor_approval'),
                );
            } catch (RuntimeException $e) {
                // La lectura sí quedó registrada; lo que falta es el visto
                // bueno. Se devuelven las diferencias para que la pantalla
                // pueda mostrarlas al pedir confirmación.
                return Problem::make(
                    409,
                    'Se requiere confirmación de supervisor',
                    $e->getMessage(),
                    'https://traza.pe/problems/supervisor-required',
                    [
                        'pass' => $pass,
                        'differences' => $this->receiving->differences($receivingOrder),
                    ],
                );
            }
        }

        return response()->json([
            'pass' => $pass,
            'differences' => $this->receiving->differences($receivingOrder->refresh()),
            'status' => $receivingOrder->status,
        ], 202);
    }

    /** @return array<string, mixed> */
    private function present(ReceivingOrder $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'status' => $order->status,
            'external_ref' => $order->external_ref,
            'location_id' => $order->location_id,
            'expected_at' => $order->expected_at,
            'received_at' => $order->received_at,
            'differences' => $this->receiving->differences($order),
        ];
    }
}
