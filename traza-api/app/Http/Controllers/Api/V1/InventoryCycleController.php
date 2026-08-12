<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\CycleReconciler;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterScansRequest;
use App\Models\InventoryCycle;
use App\Services\InventoryCycleService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class InventoryCycleController extends Controller
{
    public function __construct(
        private readonly InventoryCycleService $cycles,
    ) {}

    public function show(InventoryCycle $inventoryCycle): JsonResponse
    {
        return response()->json([
            'id' => $inventoryCycle->id,
            'code' => $inventoryCycle->code,
            'status' => $inventoryCycle->status,
            'expected_count' => $inventoryCycle->expected_count,
            'scanned_count' => $this->cycles->scannedCount($inventoryCycle),
            'started_at' => $inventoryCycle->started_at,
            'closed_at' => $inventoryCycle->closed_at,
            'accuracy_pct' => $inventoryCycle->accuracy_pct,
        ]);
    }

    public function storeScans(
        RegisterScansRequest $request,
        InventoryCycle $inventoryCycle,
    ): JsonResponse {
        $device = $request->device();

        // Un dispositivo no puede escribir en el ciclo de otra organización.
        if ($device->organization_id !== $inventoryCycle->organization_id) {
            return response()->json(['message' => 'El ciclo no pertenece a esta organización.'], 403);
        }

        try {
            $registered = $this->cycles->registerScans(
                $inventoryCycle,
                $request->validated('scans'),
                $device->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'registered' => $registered,
            'scanned_count' => $this->cycles->scannedCount($inventoryCycle),
        ], 202);
    }

    public function reconcile(InventoryCycle $inventoryCycle, CycleReconciler $reconciler): JsonResponse
    {
        if ($inventoryCycle->status->isFinished()) {
            return response()->json(['message' => 'El ciclo ya está cerrado.'], 409);
        }

        $result = $reconciler->reconcile($inventoryCycle);

        return response()->json([
            'found' => $result->found,
            'missing' => $result->missing,
            'unexpected' => $result->unexpected,
            'declared_lost' => $result->declaredLost,
            'counted' => $result->counted(),
            'accuracy_pct' => $inventoryCycle->fresh()->accuracy_pct,
        ]);
    }
}
