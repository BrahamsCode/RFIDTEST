<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Requests\PortalEventRequest;
use App\Models\PortalEvent;
use App\Services\PortalEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class PortalEventController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly PortalEventService $portal,
    ) {}

    /**
     * Camino HTTP de respaldo. El camino rápido es MQTT (`docs/07` §6) y es
     * el que cumple el presupuesto de 800 ms; esto sirve donde no hay broker
     * y para probar un portal recién instalado desde `curl`.
     */
    public function store(PortalEventRequest $request): JsonResponse
    {
        $device = $request->device();

        if ($device->kind->value !== 'lector_fijo') {
            return Problem::unprocessable(
                "El dispositivo {$device->code} no es un lector fijo: no puede generar tránsitos de portal.",
            );
        }

        if ($device->location_id === null) {
            return Problem::unprocessable(
                "El dispositivo {$device->code} no tiene tienda asignada.",
            );
        }

        $event = $this->portal->record($device, $request->toPayload());

        return response()->json([
            'id' => $event->id,
            'epc' => $event->epc,
            'direction' => $event->direction,
            'confidence' => $event->confidence,
            'was_sold' => $event->was_sold,
            'alarm_raised' => $event->alarm_raised,
            'occurred_at' => $event->occurred_at,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = PortalEvent::query()
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
            ->when($request->filled('epc'), fn ($q) => $q->where('epc', strtoupper((string) $request->string('epc'))))
            ->when($request->boolean('only_alarms'), fn ($q) => $q->where('alarm_raised', true))
            ->when($request->filled('since'), fn ($q) => $q->where('occurred_at', '>=', Carbon::parse((string) $request->string('since'))))
            // Un usuario de tienda solo ve la suya; el filtro va en la
            // consulta y no en la respuesta, para no paginar sobre filas que
            // luego se descartan.
            ->unless($user?->hasPermission('location.all'), fn ($q) => $q->where('location_id', $user?->default_location_id))
            ->orderByDesc('occurred_at');

        return response()->json($this->paginated($query, $request, fn (PortalEvent $e) => [
            'id' => $e->id,
            'location_id' => $e->location_id,
            'device_id' => $e->device_id,
            'tag_id' => $e->tag_id,
            'epc' => $e->epc,
            'direction' => $e->direction,
            'confidence' => $e->confidence,
            'was_sold' => $e->was_sold,
            'alarm_raised' => $e->alarm_raised,
            'occurred_at' => $e->occurred_at,
        ]));
    }

    /** Acción de un toque de P09. No pide nada obligatorio salvo el propio gesto. */
    public function falsePositive(Request $request, PortalEvent $portalEvent): JsonResponse
    {
        $response = $request->user()->can('markFalsePositive', $portalEvent);

        if (! $response) {
            return Problem::forbidden('No puedes marcar este tránsito como falso positivo.');
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $event = $this->portal->markFalsePositive(
            $portalEvent,
            $request->user()->id,
            $data['note'] ?? null,
        );

        return response()->json([
            'id' => $event->id,
            'alarm_raised' => $event->alarm_raised,
            'status' => 'descartada',
        ]);
    }

    /**
     * Indicador de falsos positivos de `docs/13` §2. Por encima del 20 % el
     * portal se acaba desconectando.
     */
    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location' => ['required', 'integer'],
            'days' => ['sometimes', 'integer', 'between:1,180'],
        ]);

        // `validate` devuelve lo que llegó por la cadena de consulta, que es
        // una cadena aunque la regla sea `integer`.
        $locationId = (int) $data['location'];

        if (! $request->user()->canAccessLocation($locationId)) {
            return Problem::forbidden('No tienes acceso a esta tienda.');
        }

        $days = (int) ($data['days'] ?? 30);
        $stats = $this->portal->falsePositiveRate($locationId, now()->subDays($days));

        return response()->json([
            'location_id' => $locationId,
            'days' => $days,
            ...$stats,
            // El umbral viaja con el dato para que la pantalla no tenga que
            // conocerlo ni duplicarlo.
            'threshold' => 20.0,
            'needs_recalibration' => $stats['rate'] > 20.0,
        ]);
    }
}
