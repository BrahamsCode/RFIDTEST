<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertKind;
use App\Enums\TagState;
use App\Events\PortalAlarmRaised;
use App\Models\Device;
use App\Models\PortalEvent;
use App\Models\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decide si un tránsito por el portal merece alarma. Ver `docs/07` §6 y P09.
 *
 * La regla de oro del proceso: **si la confianza es baja, el sistema no debe
 * ni sonar**. Un portal con muchos falsos positivos se acaba desconectando, y
 * entonces no sirve de nada.
 */
final class PortalEventService
{
    public function __construct(
        private readonly AlertService $alerts,
    ) {}

    /**
     * @param  array{epc: string, direction?: string, confidence?: float,
     *               occurredAt?: string, evidence?: array<string, mixed>}  $payload
     */
    public function record(Device $device, array $payload): PortalEvent
    {
        $epc = strtoupper((string) $payload['epc']);
        $direction = $payload['direction'] ?? 'indeterminado';
        $confidence = (float) ($payload['confidence'] ?? 0.0);
        $occurredAt = isset($payload['occurredAt'])
            ? Carbon::parse($payload['occurredAt'])
            : now();

        $tag = Tag::query()
            // Se carga aquí y no en la alarma: al sonar el zumbador ya no hay
            // tiempo para una consulta más.
            ->with('productVariant:id,sku,color,size')
            ->where('organization_id', $device->organization_id)
            ->where('epc', $epc)
            ->first();

        $wasSold = $tag !== null && $this->soldRecently($tag, $occurredAt);
        $shouldAlarm = $this->shouldRaiseAlarm($tag, $direction, $confidence, $wasSold);

        $event = PortalEvent::create([
            'device_id' => $device->id,
            'location_id' => $device->location_id,
            'tag_id' => $tag?->id,
            'epc' => $epc,
            'direction' => $direction,
            'confidence' => $confidence,
            'was_sold' => $tag === null ? null : $wasSold,
            'alarm_raised' => $shouldAlarm,
            'occurred_at' => $occurredAt,
            'evidence' => $payload['evidence'] ?? [],
        ]);

        if ($shouldAlarm) {
            $this->raiseAlarm($event, $tag, $device);
        }

        return $event;
    }

    /**
     * ¿Se vendió esta prenda hace poco?
     *
     * Una consulta indexada y nada más: está en el camino crítico, con un
     * presupuesto de 800 ms extremo a extremo.
     */
    public function soldRecently(Tag $tag, ?Carbon $at = null): bool
    {
        $at ??= now();
        $graceSeconds = (int) config('traza.portal.sale_grace_seconds', 120);

        return DB::table('sale_lines')
            ->join('sale_transactions as st', 'st.id', '=', 'sale_lines.sale_transaction_id')
            ->where('sale_lines.tag_id', $tag->id)
            ->where('st.is_return', false)
            ->where('st.sold_at', '>=', $at->copy()->subSeconds($graceSeconds))
            ->where('st.sold_at', '<=', $at)
            ->exists();
    }

    /**
     * Marca una alarma como falso positivo. Es el único dato que permite
     * calibrar el portal, así que tiene que costar un solo toque.
     *
     * `alarm_raised` se deja en `false`: el evento sigue existiendo con toda
     * su evidencia, pero deja de contar como alarma. La alerta asociada pasa
     * a `descartada`, que es lo que mide el indicador de `docs/13` §2.
     */
    public function markFalsePositive(PortalEvent $event, ?int $userId = null, ?string $note = null): PortalEvent
    {
        return DB::transaction(function () use ($event, $userId, $note): PortalEvent {
            $event->forceFill([
                'alarm_raised' => false,
                'evidence' => [
                    ...$event->evidence ?? [],
                    'false_positive' => [
                        'marked_by' => $userId,
                        'marked_at' => now()->toIso8601String(),
                        'note' => $note,
                    ],
                ],
            ])->save();

            $alert = $this->alerts->openForPortalEvent($event->id);

            if ($alert !== null) {
                $this->alerts->dismiss($alert, $userId, $note ?? 'Falso positivo del portal');
            }

            return $event;
        });
    }

    /**
     * Indicador de `docs/13` §2: alarmas descartadas sobre alarmas totales.
     * Por encima del 20 % el personal deja de hacer caso al portal y hay que
     * recalibrarlo, así que es la cifra que decide si el portal sirve.
     *
     * @return array{alarms: int, dismissed: int, rate: float}
     */
    public function falsePositiveRate(int $locationId, Carbon $from, ?Carbon $to = null): array
    {
        $to ??= now();

        $row = DB::table('portal_events as pe')
            ->leftJoin('alerts as a', function ($join): void {
                $join->on(DB::raw("a.detail->>'portal_event_id'"), '=', DB::raw('pe.id::text'))
                    ->where('a.kind', '=', 'salida_no_vendida');
            })
            ->where('pe.location_id', $locationId)
            ->whereBetween('pe.occurred_at', [$from, $to])
            // El universo son los tránsitos que sonaron alguna vez, no todos
            // los tránsitos: un cruce silencioso no es un acierto del portal.
            ->whereRaw("(pe.alarm_raised OR a.id IS NOT NULL)")
            ->selectRaw('count(*) as alarms')
            ->selectRaw("count(*) FILTER (WHERE a.status = 'descartada' OR NOT pe.alarm_raised) as dismissed")
            ->first();

        $alarms = (int) ($row->alarms ?? 0);
        $dismissed = (int) ($row->dismissed ?? 0);

        return [
            'alarms' => $alarms,
            'dismissed' => $dismissed,
            'rate' => $alarms === 0 ? 0.0 : round($dismissed / $alarms * 100, 2),
        ];
    }

    private function shouldRaiseAlarm(
        ?Tag $tag,
        string $direction,
        float $confidence,
        bool $wasSold,
    ): bool {
        if (! config('traza.portal.alarm_enabled', true)) {
            return false;
        }

        // Solo las salidas. Entrar con una prenda es lo que hace un cliente
        // que viene a cambiarla.
        if ($direction !== 'salida') {
            return false;
        }

        // Por debajo del umbral no suena. Regla 4 de P09.
        if ($confidence < (float) config('traza.portal.min_confidence', 0.7)) {
            return false;
        }

        // Un EPC ajeno es del local de al lado o de una prenda que el cliente
        // ya traía puesta: alarmar por eso quema la credibilidad del sistema.
        if ($tag === null) {
            return ! config('traza.portal.ignore_unknown', true);
        }

        if ($wasSold) {
            return false;
        }

        // Una prenda ya vendida hace tiempo que vuelve a salir es una
        // devolución que se llevan de nuevo, no un hurto.
        return $tag->state !== TagState::Vendido;
    }

    private function raiseAlarm(PortalEvent $event, ?Tag $tag, Device $device): void
    {
        $this->alerts->raise(
            AlertKind::SalidaNoVendida,
            $tag,
            $device,
            [
                'portal_event_id' => $event->id,
                'epc' => $event->epc,
                'confidence' => $event->confidence,
                'evidence' => $event->evidence,
            ],
            organizationId: $device->organization_id,
        );

        /*
         * La difusión es lo último y va aislada. `PortalAlarmRaised` es
         * `ShouldBroadcastNow`, así que se envía en el mismo proceso: si
         * Reverb no responde, la excepción reventaría el registro del
         * tránsito entero. Y eso es exactamente al revés de lo que hace
         * falta — la alerta ya está guardada y el zumbador del arco suena
         * por su cuenta; que la pantalla no se entere es lo de menos.
         */
        try {
            PortalAlarmRaised::dispatch(
                $device->location_id,
                $event->epc,
                $event->confidence,
                $tag?->id,
                $this->describe($tag),
            );
        } catch (\Throwable $e) {
            Log::warning('Portal: la alarma no se pudo difundir', [
                'portal_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lo que lee el vendedor en la pantalla. Sin esto solo vería un EPC de 24
     * caracteres, que no le dice qué prenda mirar.
     */
    private function describe(?Tag $tag): ?string
    {
        $variant = $tag?->productVariant;

        if ($variant === null) {
            return null;
        }

        return trim(implode(' ', array_filter([
            $variant->sku,
            $variant->color,
            $variant->size,
        ])));
    }
}
