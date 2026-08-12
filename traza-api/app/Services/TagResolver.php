<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

final class TagResolver
{
    public function find(int $organizationId, string $epc): ?Tag
    {
        return Tag::query()
            ->where('organization_id', $organizationId)
            ->where('epc', strtoupper($epc))
            ->first();
    }

    /**
     * Registra un EPC que no está en el sistema. No se crea un tag: puede ser
     * del local de al lado, y darlo de alta contaminaría el inventario.
     */
    public function recordUnknown(string $epc, Device $device): void
    {
        $now = now();

        // El UNIQUE es (epc, location_id); un upsert evita la carrera entre
        // dos lotes que traigan el mismo EPC desconocido.
        DB::table('unknown_epcs')->upsert(
            [[
                'epc' => strtoupper($epc),
                'location_id' => $device->location_id,
                'device_id' => $device->id,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'seen_count' => 1,
            ]],
            ['epc', 'location_id'],
            [
                'last_seen_at' => $now,
                'seen_count' => DB::raw('unknown_epcs.seen_count + 1'),
            ],
        );
    }
}
