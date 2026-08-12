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
     * @param array<string, mixed> $detail
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
}
