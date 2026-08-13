<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de stock sobre las vistas analíticas de la tarea 8.1.
 *
 * Todo el trabajo lo hace PostgreSQL: agregar en PHP lo que la base ya sabe
 * agregar sería más lento y más frágil.
 */
final class StockController extends Controller
{
    use Paginates;

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('v_current_stock as s')
            ->join('product_variants as v', 'v.id', '=', 's.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('zones as z', 'z.id', '=', 's.zone_id')
            ->when($request->filled('location'), fn ($q) => $q->where('s.location_id', $request->integer('location')))
            ->when($request->filled('zone'), fn ($q) => $q->where('s.zone_id', $request->integer('zone')))
            ->when($request->filled('variant'), fn ($q) => $q->where('s.product_variant_id', $request->integer('variant')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $q->where(fn ($w) => $w->where('v.sku', 'ilike', $term)->orWhere('p.name', 'ilike', $term));
            })
            ->orderByDesc('s.quantity')
            ->select([
                's.location_id', 's.zone_id', 's.product_variant_id',
                'v.sku', 'v.size', 'v.color', 'p.name as product_name',
                'z.name as zone_name',
                's.quantity', 's.sellable_quantity', 's.last_seen_at',
            ]);

        return response()->json($this->paginated($query, $request));
    }

    public function valuation(Request $request): JsonResponse
    {
        $rows = DB::table('v_stock_valuation')
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
            ->orderByDesc('cost_value')
            ->get();

        return response()->json([
            'data' => $rows,
            'totals' => [
                'units' => (int) $rows->sum('units'),
                'cost_value' => round((float) $rows->sum('cost_value'), 2),
                'retail_value' => round((float) $rows->sum('retail_value'), 2),
            ],
        ]);
    }

    /** Antigüedad por tramos: qué lleva demasiado tiempo sin venderse. */
    public function aging(Request $request): JsonResponse
    {
        $buckets = DB::table('v_stock_aging')
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
            ->groupBy('age_bucket')
            ->select(['age_bucket', DB::raw('count(*) as units')])
            ->get()
            ->keyBy('age_bucket');

        // Se devuelven siempre los cinco tramos, aunque estén vacíos: una
        // gráfica con tramos que aparecen y desaparecen es ilegible.
        $order = ['0-30', '31-60', '61-90', '91-180', '180+'];

        return response()->json([
            'data' => array_map(fn (string $bucket) => [
                'age_bucket' => $bucket,
                'units' => (int) ($buckets[$bucket]->units ?? 0),
            ], $order),
        ]);
    }

    /** Hay stock en trastienda y la sala está desabastecida. */
    public function replenishment(Request $request): JsonResponse
    {
        $query = DB::table('v_replenishment_needed')
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
            ->orderBy('on_floor')
            ->orderByDesc('in_back');

        return response()->json($this->paginated($query, $request));
    }

    /** Los cuatro números del panel de tienda. Ver `docs/13` §3.1. */
    public function summary(Request $request): JsonResponse
    {
        $locationId = $request->integer('location') ?: null;

        $stock = DB::table('v_current_stock')
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('coalesce(sum(quantity), 0) as units, coalesce(sum(sellable_quantity), 0) as sellable')
            ->first();

        $lastCycle = DB::table('v_inventory_accuracy')
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->orderByDesc('closed_at')
            ->first();

        return response()->json([
            'units' => (int) $stock->units,
            'sellable_units' => (int) $stock->sellable,
            'last_accuracy_pct' => $lastCycle?->read_accuracy_pct === null
                ? null
                : (float) $lastCycle->read_accuracy_pct,
            'last_cycle_closed_at' => $lastCycle?->closed_at,
            'open_alerts' => DB::table('alerts')
                ->whereIn('status', ['abierta', 'en_revision'])
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->count(),
            'replenishment_needed' => DB::table('v_replenishment_needed')
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->count(),
        ]);
    }
}
