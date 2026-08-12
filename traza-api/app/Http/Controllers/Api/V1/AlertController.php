<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AlertController extends Controller
{
    use Paginates;

    public function index(Request $request): JsonResponse
    {
        $query = Alert::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
            // Lo más grave primero: una posible clonación importa más que una
            // reposición de sala.
            ->orderBy('severity')
            ->orderByDesc('triggered_at');

        return response()->json($this->paginated($query, $request, fn (Alert $a) => [
            'id' => $a->id,
            'kind' => $a->kind,
            'severity' => $a->severity,
            'status' => $a->status,
            'title' => $a->title,
            'detail' => $a->detail,
            'tag_id' => $a->tag_id,
            'device_id' => $a->device_id,
            'triggered_at' => $a->triggered_at,
        ]));
    }

    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        if ($alert->status !== 'abierta') {
            return Problem::conflict("La alerta ya está {$alert->status}.");
        }

        $alert->update([
            'status' => 'en_revision',
            'acknowledged_by' => $request->user()?->id,
            'acknowledged_at' => now(),
        ]);

        return response()->json(['id' => $alert->id, 'status' => $alert->status]);
    }

    public function resolve(Request $request, Alert $alert): JsonResponse
    {
        $data = $request->validate([
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'false_positive' => ['sometimes', 'boolean'],
        ]);

        if ($alert->status === 'resuelta' || $alert->status === 'descartada') {
            return Problem::conflict("La alerta ya está {$alert->status}.");
        }

        // Marcar un falso positivo de un toque es lo que alimenta el
        // indicador de `docs/13` §2; si cuesta, nadie lo registra.
        $alert->update([
            'status' => $request->boolean('false_positive') ? 'descartada' : 'resuelta',
            'resolved_at' => now(),
            'resolution_note' => $data['resolution_note'] ?? null,
        ]);

        return response()->json(['id' => $alert->id, 'status' => $alert->status]);
    }
}
