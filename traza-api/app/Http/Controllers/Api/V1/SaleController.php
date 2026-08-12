<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\Location;
use App\Models\SaleTransaction;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $sales,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'code' => ['required', 'string', 'max:48'],
            'external_ref' => ['nullable', 'string', 'max:64'],
            'epcs' => ['required', 'array', 'min:1', 'max:500'],
            'epcs.*' => ['string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
        ]);

        $result = $this->sales->sell(
            location: Location::findOrFail($data['location_id']),
            code: $data['code'],
            epcs: $data['epcs'],
            userId: $request->user()?->id,
            externalRef: $data['external_ref'] ?? null,
        );

        return response()->json([
            'id' => $result['sale']->id,
            'code' => $result['sale']->code,
            'sold' => $result['sold'],
            // EPC que no se pudieron vender: desconocidos o ya no en stock.
            // No impiden cobrar; se informan para que la caja los revise.
            'ignored' => $result['ignored'],
            'total_amount' => $result['sale']->total_amount,
        ], 201);
    }

    public function returnItem(Request $request, SaleTransaction $sale): JsonResponse
    {
        $data = $request->validate([
            'epc' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
            'code' => ['required', 'string', 'max:48'],
        ]);

        try {
            $return = $this->sales->acceptReturn(
                location: $sale->location,
                epc: $data['epc'],
                code: $data['code'],
                userId: $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            // La verificación contra la venta original es el valor del RFID
            // en caja: es lo que frena la devolución de prenda ajena o usada.
            return Problem::unprocessable($e->getMessage());
        }

        return response()->json([
            'id' => $return->id,
            'code' => $return->code,
            'original_sale_id' => $return->original_sale_id,
            'total_amount' => $return->total_amount,
        ], 201);
    }
}
