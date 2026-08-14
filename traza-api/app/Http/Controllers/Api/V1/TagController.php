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
use Illuminate\Support\Facades\DB;
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
    /**
     * Detecciones recientes con su RSSI. Tarea 4.4.
     *
     * Es lo primero que mira soporte cuando alguien dice «el sistema dice que
     * está y no está». Un RSSI consistentemente bajo —en torno a −75 dBm—
     * significa que se está leyendo desde la tienda de al lado o desde otra
     * zona, no que la prenda esté donde el sistema cree.
     */
    public function detections(Request $request, string $epc): JsonResponse
    {
        $tag = Tag::query()->where('epc', strtoupper($epc))->first();

        if ($tag === null) {
            return Problem::make(404, 'Prenda no encontrada', "No existe el EPC {$epc}.");
        }

        $hours = min(max($request->integer('hours', 72), 1), 720);

        /*
         * Se agrupa por hora y antena en SQL. Traer las lecturas crudas sería
         * devolver decenas de miles de puntos que el navegador no puede
         * dibujar y que además no dicen nada: lo que interesa es la tendencia
         * y la dispersión, no cada lectura.
         *
         * El filtro por `read_at` recorta particiones: sin él la consulta
         * barrería los tres meses de retención.
         */
        $rows = DB::select(<<<'SQL'
            SELECT date_trunc('hour', r.read_at)        AS hora,
                   r.device_id,
                   d.code                               AS dispositivo,
                   r.antenna_port                       AS antena,
                   count(*)                             AS lecturas,
                   round(avg(r.rssi), 1)                AS rssi_medio,
                   min(r.rssi)                          AS rssi_min,
                   max(r.rssi)                          AS rssi_max
            FROM tag_reads r
            LEFT JOIN devices d ON d.id = r.device_id
            WHERE r.epc = ?
              AND r.read_at >= now() - (? * interval '1 hour')
            GROUP BY 1, 2, 3, 4
            ORDER BY 1
        SQL, [$tag->epc, $hours]);

        $rssi = array_map(fn (object $r) => (float) $r->rssi_medio, $rows);

        return response()->json([
            'epc' => $tag->epc,
            'hours' => $hours,
            'data' => array_map(fn (object $r) => [
                'hour' => $r->hora,
                'device_id' => $r->device_id,
                'device_code' => $r->dispositivo,
                'antenna' => $r->antena,
                'reads' => (int) $r->lecturas,
                'rssi_avg' => (float) $r->rssi_medio,
                'rssi_min' => (float) $r->rssi_min,
                'rssi_max' => (float) $r->rssi_max,
            ], $rows),
            'summary' => [
                'total_reads' => array_sum(array_map(fn (object $r) => (int) $r->lecturas, $rows)),
                'rssi_avg' => $rssi === [] ? null : round(array_sum($rssi) / count($rssi), 1),
                'rssi_min' => $rssi === [] ? null : min($rssi),
                // Por debajo de −70 dBm la lectura es de lejos: probablemente
                // del local vecino, no de donde el sistema cree que está.
                'weak_signal' => $rssi !== [] && (array_sum($rssi) / count($rssi)) < -70,
            ],
        ]);
    }

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
