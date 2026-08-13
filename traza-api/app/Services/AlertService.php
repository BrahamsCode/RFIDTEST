<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertKind;
use App\Models\Alert;
use App\Models\Device;
use App\Models\Tag;

final class AlertService
{
    /** @param array<string, mixed> $detail */
    public function raise(
        AlertKind $kind,
        ?Tag $tag = null,
        ?Device $device = null,
        array $detail = [],
        ?int $organizationId = null,
        ?int $severity = null,
    ): Alert {
        $organizationId ??= $tag?->organization_id ?? $device?->organization_id;

        return Alert::create([
            'organization_id' => $organizationId,
            'location_id' => $tag?->current_location_id ?? $device?->location_id,
            'kind' => $kind,
            'severity' => $severity ?? $kind->defaultSeverity(),
            'status' => 'abierta',
            'tag_id' => $tag?->id,
            'device_id' => $device?->id,
            'title' => $kind->title(),
            'detail' => $detail,
            'triggered_at' => now(),
        ]);
    }

    /**
     * Evita inundar la bandeja: si ya hay una alerta abierta del mismo tipo
     * para el mismo tag, no se crea otra.
     *
     * @param  array<string, mixed>  $detail
     */
    public function raiseOnce(
        AlertKind $kind,
        ?Tag $tag = null,
        ?Device $device = null,
        array $detail = [],
    ): ?Alert {
        $existing = Alert::query()
            ->where('kind', $kind)
            ->where('tag_id', $tag?->id)
            ->whereIn('status', ['abierta', 'en_revision'])
            ->exists();

        return $existing ? null : $this->raise($kind, $tag, $device, $detail);
    }

    /**
     * Descarta una alerta: no era real. Es un estado distinto de `resuelta`
     * a propósito, porque el indicador de falsos positivos de `docs/13` §2
     * se calcula sobre `descartada` y mezclarlos lo falsearía.
     */
    public function dismiss(Alert $alert, ?int $userId = null, ?string $note = null): Alert
    {
        $alert->forceFill([
            'status' => 'descartada',
            'acknowledged_by' => $alert->acknowledged_by ?? $userId,
            'acknowledged_at' => $alert->acknowledged_at ?? now(),
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();

        return $alert;
    }

    /** Cierra la alerta como incidencia real y atendida. */
    public function resolve(Alert $alert, ?int $userId = null, ?string $note = null): Alert
    {
        $alert->forceFill([
            'status' => 'resuelta',
            'acknowledged_by' => $alert->acknowledged_by ?? $userId,
            'acknowledged_at' => $alert->acknowledged_at ?? now(),
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();

        return $alert;
    }

    /**
     * Alerta abierta asociada a un evento de portal concreto. La referencia
     * vive en `detail->portal_event_id`; no hay columna propia porque
     * `alerts` es genérica para los nueve tipos de alerta.
     */
    public function openForPortalEvent(int $portalEventId): ?Alert
    {
        return Alert::query()
            ->where('kind', AlertKind::SalidaNoVendida)
            ->whereRaw("detail->>'portal_event_id' = ?", [(string) $portalEventId])
            ->whereIn('status', ['abierta', 'en_revision'])
            ->orderByDesc('id')
            ->first();
    }
}
