<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\CycleReconciler;
use App\Enums\CycleScope;
use App\Enums\CycleStatus;
use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Requests\RegisterScansRequest;
use App\Models\InventoryCycle;
use App\Models\Location;
use App\Policies\InventoryCyclePolicy;
use App\Services\InventoryCycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InventoryCycleController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly InventoryCycleService $cycles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = InventoryCycle::query()
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id');

        return response()->json($this->paginated($query, $request, fn (InventoryCycle $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'status' => $c->status,
            'expected_count' => $c->expected_count,
            'accuracy_pct' => $c->accuracy_pct,
            'started_at' => $c->started_at,
            'closed_at' => $c->closed_at,
        ]));
    }

    /** Crear un ciclo congela la lista de esperados. Ver `docs/05` §2.4. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'code' => ['required', 'string', 'max:32'],
            'scope' => ['sometimes', 'string', 'in:total,zona,categoria,muestreo'],
            'zone_ids' => ['sometimes', 'array'],
            'zone_ids.*' => ['integer', 'exists:zones,id'],
        ]);

        $denial = app(InventoryCyclePolicy::class)
            ->create($request->user(), (int) $data['location_id']);

        if ($denial->denied()) {
            return Problem::forbidden($denial->message());
        }

        $cycle = $this->cycles->create(
            location: Location::findOrFail($data['location_id']),
            code: $data['code'],
            scope: CycleScope::from($data['scope'] ?? 'total'),
            zoneIds: $data['zone_ids'] ?? [],
            startedBy: $request->user()?->id,
        );

        return response()->json($this->present($cycle), 201);
    }

    public function show(InventoryCycle $inventoryCycle): JsonResponse
    {
        return response()->json($this->present($inventoryCycle));
    }

    public function start(InventoryCycle $inventoryCycle): JsonResponse
    {
        if ($inventoryCycle->status->isFinished()) {
            return Problem::conflict('El ciclo ya está cerrado.');
        }

        $inventoryCycle->update(['status' => CycleStatus::EnCurso]);

        return response()->json($this->present($inventoryCycle->refresh()));
    }

    public function pause(InventoryCycle $inventoryCycle): JsonResponse
    {
        if ($inventoryCycle->status !== CycleStatus::EnCurso) {
            return Problem::conflict('Solo se puede pausar un ciclo en curso.');
        }

        $inventoryCycle->update(['status' => CycleStatus::Pausado]);

        return response()->json($this->present($inventoryCycle->refresh()));
    }

    public function storeScans(
        RegisterScansRequest $request,
        InventoryCycle $inventoryCycle,
    ): JsonResponse {
        $device = $request->device();

        // Un dispositivo no puede escribir en el ciclo de otra organización.
        if ($device->organization_id !== $inventoryCycle->organization_id) {
            return Problem::forbidden('El ciclo no pertenece a esta organización.');
        }

        try {
            $registered = $this->cycles->registerScans(
                $inventoryCycle,
                $request->validated('scans'),
                $device->id,
            );
        } catch (RuntimeException $e) {
            return Problem::conflict($e->getMessage());
        }

        return response()->json([
            'registered' => $registered,
            'scanned_count' => $this->cycles->scannedCount($inventoryCycle),
        ], 202);
    }

    /** Cerrar dispara la conciliación. */
    public function close(
        Request $request,
        InventoryCycle $inventoryCycle,
        CycleReconciler $reconciler,
        InventoryCyclePolicy $policy,
    ): JsonResponse {
        if ($inventoryCycle->status->isFinished()) {
            return Problem::conflict('El ciclo ya está cerrado.');
        }

        // Incluye la exigencia de justificación si la exactitud provisional
        // baja del 90 %: cerrar así genera merma falsa. Ver `docs/12` §2.
        $denial = $policy->close($request->user(), $inventoryCycle);

        if ($denial->denied()) {
            return Problem::forbidden($denial->message());
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

    /** Informe de conciliación por variante. */
    public function report(InventoryCycle $inventoryCycle): JsonResponse
    {
        $rows = DB::table('inventory_cycle_results as r')
            ->join('product_variants as v', 'v.id', '=', 'r.product_variant_id')
            ->where('r.inventory_cycle_id', $inventoryCycle->id)
            ->orderBy('r.difference_qty')
            ->get([
                'v.sku',
                'r.expected_qty',
                'r.counted_qty',
                'r.difference_qty',
                'r.value_difference',
            ]);

        return response()->json([
            'cycle' => $this->present($inventoryCycle),
            'lines' => $rows,
        ]);
    }

    /**
     * Avance por zona. Sirve para detectar la zona que nadie barrió, que es
     * la causa más habitual de un ciclo con mala exactitud.
     */
    public function zonePerformance(InventoryCycle $inventoryCycle): JsonResponse
    {
        // La función ordena por exactitud ascendente: la zona peor barrida
        // sale primera, que es la que hay que mirar.
        return response()->json([
            'zones' => DB::select('SELECT * FROM cycle_zone_performance(?)', [$inventoryCycle->id]),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(InventoryCycle $cycle): array
    {
        return [
            'id' => $cycle->id,
            'code' => $cycle->code,
            'status' => $cycle->status,
            'scope' => $cycle->scope,
            'location_id' => $cycle->location_id,
            'expected_count' => $cycle->expected_count,
            'scanned_count' => $this->cycles->scannedCount($cycle),
            'found_count' => $cycle->found_count,
            'missing_count' => $cycle->missing_count,
            'unexpected_count' => $cycle->unexpected_count,
            'accuracy_pct' => $cycle->accuracy_pct,
            'started_at' => $cycle->started_at,
            'closed_at' => $cycle->closed_at,
        ];
    }
}
