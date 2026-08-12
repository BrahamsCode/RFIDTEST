<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\StockMovement;
use App\Models\Tag;
use App\Services\SaleService;
use App\Services\TagReplacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class TagController extends Controller
{
    use Paginates;

    public function index(Request $request): JsonResponse
    {
        $query = Tag::query()
            ->when($request->filled('epc'), fn ($q) => $q->where('epc', 'ilike', strtoupper($request->string('epc')->toString()).'%'))
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->string('state')))
            ->when($request->filled('location'), fn ($q) => $q->where('current_location_id', $request->integer('location')))
            ->orderByDesc('id');

        return response()->json($this->paginated($query, $request, $this->summary(...)));
    }

    public function show(string $epc): JsonResponse
    {
        $tag = $this->findOrFail($epc);

        return response()->json($this->summary($tag) + [
            'tid' => $tag->tid,
            'epc_scheme' => $tag->epc_scheme,
            'commissioned_at' => $tag->commissioned_at,
            'first_seen_at' => $tag->first_seen_at,
            'sold_at' => $tag->sold_at,
            'replaces_tag_id' => $tag->replaces_tag_id,
            'sale' => app(SaleService::class)->saleInfoFor($tag),
        ]);
    }

    /** Trazabilidad completa: el histórico es `stock_movements`, sin excepción. */
    public function history(Request $request, string $epc): JsonResponse
    {
        $tag = $this->findOrFail($epc);

        $query = StockMovement::query()
            ->where('tag_id', $tag->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        return response()->json($this->paginated($query, $request, fn (StockMovement $m) => [
            'id' => $m->id,
            'type' => $m->movement_type,
            'state_before' => $m->state_before,
            'state_after' => $m->state_after,
            'from_location_id' => $m->from_location_id,
            'to_location_id' => $m->to_location_id,
            'from_zone_id' => $m->from_zone_id,
            'to_zone_id' => $m->to_zone_id,
            'reason' => $m->reason,
            'reference_type' => $m->reference_type,
            'reference_id' => $m->reference_id,
            'occurred_at' => $m->occurred_at,
        ]));
    }

    /** Sustitución por hangtag arrancado o ilegible. Ver P11 de `docs/10`. */
    public function replace(Request $request, string $epc): JsonResponse
    {
        $data = $request->validate([
            'new_epc' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
            'reason' => ['required', 'string', 'max:64'],
        ]);

        $old = $this->findOrFail($epc);
        $new = Tag::query()
            ->where('organization_id', $old->organization_id)
            ->where('epc', strtoupper($data['new_epc']))
            ->first();

        if ($new === null) {
            return Problem::unprocessable(
                "El EPC sustituto {$data['new_epc']} no existe. Tárelo primero."
            );
        }

        try {
            $replacement = app(TagReplacementService::class)
                ->replace($old, $new, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return Problem::unprocessable($e->getMessage());
        }

        return response()->json([
            'id' => $replacement->id,
            'old_epc' => $old->epc,
            'new_epc' => $new->epc,
            'reason' => $replacement->reason,
        ], 201);
    }

    /** @return array<string, mixed> */
    private function summary(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'epc' => $tag->epc,
            'state' => $tag->state,
            'product_variant_id' => $tag->product_variant_id,
            'current_location_id' => $tag->current_location_id,
            'current_zone_id' => $tag->current_zone_id,
            'last_seen_at' => $tag->last_seen_at,
            'missed_cycles' => $tag->missed_cycles,
        ];
    }

    private function findOrFail(string $epc): Tag
    {
        return Tag::query()->where('epc', strtoupper($epc))->firstOrFail();
    }
}
