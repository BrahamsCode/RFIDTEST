<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Movements\MovementIntent;
use App\Enums\MovementType;
use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Tag;
use App\Models\Transfer;
use App\Services\StockMovementService;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class MovementController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly StockMovementService $movements,
        private readonly TransferService $transfers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockMovement::query()
            ->when($request->filled('tag'), fn ($q) => $q->where('tag_id', $request->integer('tag')))
            ->when($request->filled('type'), fn ($q) => $q->where('movement_type', $request->string('type')))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('occurred_at', '<=', $request->date('to')))
            ->orderByDesc('occurred_at');

        return response()->json($this->paginated($query, $request, fn (StockMovement $m) => [
            'id' => $m->id,
            'tag_id' => $m->tag_id,
            'type' => $m->movement_type,
            'state_before' => $m->state_before,
            'state_after' => $m->state_after,
            'occurred_at' => $m->occurred_at,
            'reason' => $m->reason,
        ]));
    }

    /** Despacho o recepción de una transferencia entre tiendas. Ver P08. */
    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transfer_id' => ['nullable', 'integer', 'exists:transfers,id'],
            'from_location_id' => ['required_without:transfer_id', 'integer', 'exists:locations,id'],
            'to_location_id' => ['required_without:transfer_id', 'integer', 'exists:locations,id'],
            'code' => ['required_without:transfer_id', 'string', 'max:32'],
            'action' => ['required', 'in:dispatch,receive'],
            'epcs' => ['required', 'array', 'min:1', 'max:5000'],
            'epcs.*' => ['string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
        ]);

        try {
            $transfer = isset($data['transfer_id'])
                ? Transfer::findOrFail($data['transfer_id'])
                : $this->transfers->create(
                    Location::findOrFail($data['from_location_id']),
                    Location::findOrFail($data['to_location_id']),
                    $data['code'],
                    $request->user()?->id,
                );

            if ($data['action'] === 'dispatch') {
                $dispatched = $this->transfers->dispatch($transfer, $data['epcs'], $request->user()?->id);

                return response()->json([
                    'transfer_id' => $transfer->id,
                    'code' => $transfer->code,
                    'dispatched' => $dispatched,
                    'status' => $transfer->refresh()->status,
                ], 202);
            }

            $result = $this->transfers->receive($transfer, $data['epcs'], $request->user()?->id);

            return response()->json([
                'transfer_id' => $transfer->id,
                'code' => $transfer->code,
                'status' => $transfer->refresh()->status,
            ] + $result, 202);
        } catch (RuntimeException $e) {
            return Problem::conflict($e->getMessage());
        }
    }

    /** Movimiento interno entre zonas de la misma tienda. */
    public function zoneChange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'epcs' => ['required', 'array', 'min:1', 'max:5000'],
            'epcs.*' => ['string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
            'to_zone_id' => ['required', 'integer', 'exists:zones,id'],
        ]);

        $tagIds = Tag::query()
            ->whereIn('epc', array_map('strtoupper', $data['epcs']))
            ->where('state', 'en_stock')
            ->pluck('id')
            ->all();

        $moved = $this->movements->applyBulk($tagIds, new MovementIntent(
            type: MovementType::CambioZona,
            toZoneId: $data['to_zone_id'],
            userId: $request->user()?->id,
            reason: 'Cambio de zona',
        ));

        return response()->json(['moved' => $moved], 202);
    }
}
