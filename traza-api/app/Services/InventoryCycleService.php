<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CycleScope;
use App\Enums\CycleStatus;
use App\Events\InventoryCycleProgressed;
use App\Models\InventoryCycle;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InventoryCycleService
{
    /** Los escaneos se registran en trozos para no armar sentencias enormes. */
    private const SCAN_CHUNK = 500;

    /**
     * Arranca un ciclo y CONGELA la lista de prendas esperadas.
     *
     * Esa foto es lo que hace reproducible la conciliación: sin ella, vender
     * una prenda a mitad del conteo cambiaría el resultado según cuándo se
     * calcule. Ver `docs/05` §2.4.
     *
     * @param  list<int>  $zoneIds  vacío = toda la ubicación
     */
    public function create(
        Location $location,
        string $code,
        CycleScope $scope = CycleScope::Total,
        array $zoneIds = [],
        ?int $startedBy = null,
    ): InventoryCycle {
        return DB::transaction(function () use ($location, $code, $scope, $zoneIds, $startedBy) {
            $cycle = InventoryCycle::create([
                'organization_id' => $location->organization_id,
                'location_id' => $location->id,
                'code' => $code,
                'scope' => $scope,
                'scope_filter' => $zoneIds === [] ? [] : ['zone_ids' => $zoneIds],
                'status' => CycleStatus::EnCurso,
                'started_by' => $startedBy,
                'started_at' => now(),
            ]);

            $expected = $this->freezeExpected($cycle, $location->id, $zoneIds);

            $cycle->update(['expected_count' => $expected]);

            return $cycle->refresh();
        });
    }

    /**
     * Registra escaneos deduplicando por EPC dentro del ciclo.
     *
     * El handheld reenvía el mismo EPC muchas veces durante un barrido; la
     * clave primaria (inventory_cycle_id, epc) más el upsert dejan una sola
     * fila por prenda, acumulando el conteo y quedándose con el RSSI máximo.
     *
     * @param  list<array<string, mixed>>  $scans
     * @return int filas afectadas
     */
    public function registerScans(InventoryCycle $cycle, array $scans, ?int $deviceId = null): int
    {
        if (! $cycle->status->acceptsScans()) {
            throw new RuntimeException(
                "El ciclo {$cycle->code} está {$cycle->status->value} y no admite escaneos."
            );
        }

        $rows = $this->buildScanRows($cycle, $scans, $deviceId);

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, self::SCAN_CHUNK) as $chunk) {
            DB::table('inventory_cycle_scans')->upsert(
                $chunk,
                ['inventory_cycle_id', 'epc'],
                [
                    'last_seen_at' => DB::raw('excluded.last_seen_at'),
                    'read_count' => DB::raw('inventory_cycle_scans.read_count + excluded.read_count'),
                    'max_rssi' => DB::raw('greatest(inventory_cycle_scans.max_rssi, excluded.max_rssi)'),
                    // Una prenda puede aparecer primero sin resolver y luego
                    // resolverse: no se pierde el tag_id ya conocido.
                    'tag_id' => DB::raw('coalesce(inventory_cycle_scans.tag_id, excluded.tag_id)'),
                    'zone_id' => DB::raw('coalesce(excluded.zone_id, inventory_cycle_scans.zone_id)'),
                ],
            );
        }

        /*
         * Un evento por lote, no por EPC: en un barrido de 20 000 prendas,
         * difundir cada lectura serían 20 000 eventos y el navegador no daría
         * abasto. El handheld ya envía en lotes de 500.
         */
        InventoryCycleProgressed::dispatch(
            $cycle->id,
            $this->scannedCount($cycle),
            (int) ($cycle->expected_count ?? 0),
        );

        return count($rows);
    }

    /** Prendas distintas detectadas hasta ahora. */
    public function scannedCount(InventoryCycle $cycle): int
    {
        return DB::table('inventory_cycle_scans')
            ->where('inventory_cycle_id', $cycle->id)
            ->count();
    }

    /**
     * Copia el stock teórico de la ubicación a `inventory_cycle_expected`.
     *
     * Se hace con un INSERT ... SELECT y no trayendo los tags a PHP: con
     * 20 000 prendas, instanciar modelos para reinsertarlos cuesta órdenes de
     * magnitud más en tiempo y memoria.
     *
     * @param  list<int>  $zoneIds
     */
    private function freezeExpected(InventoryCycle $cycle, int $locationId, array $zoneIds): int
    {
        $query = DB::table('tags')
            ->select([
                DB::raw((string) $cycle->id),
                'id',
                'product_variant_id',
                'current_zone_id',
            ])
            ->where('current_location_id', $locationId)
            ->whereNotNull('product_variant_id')
            // Solo lo que debería estar físicamente presente. Lo vendido, en
            // tránsito o dado de baja no se espera encontrar.
            ->whereIn('state', ['en_stock', 'no_visto']);

        if ($zoneIds !== []) {
            $query->whereIn('current_zone_id', $zoneIds);
        }

        return DB::table('inventory_cycle_expected')->insertUsing(
            ['inventory_cycle_id', 'tag_id', 'product_variant_id', 'zone_id'],
            $query,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $scans
     * @return list<array<string, mixed>>
     */
    private function buildScanRows(InventoryCycle $cycle, array $scans, ?int $deviceId): array
    {
        $epcs = [];
        foreach ($scans as $scan) {
            $epc = strtoupper((string) $scan['epc']);
            $epcs[$epc] = true;
        }

        // Una sola consulta resuelve todos los EPC del lote a su tag.
        $tagIds = DB::table('tags')
            ->where('organization_id', $cycle->organization_id)
            ->whereIn('epc', array_keys($epcs))
            ->pluck('id', 'epc');

        $now = now();
        $rows = [];
        $seen = [];

        foreach ($scans as $scan) {
            $epc = strtoupper((string) $scan['epc']);

            // Deduplicación dentro del propio lote: PostgreSQL rechaza un
            // upsert que toque la misma fila dos veces en la misma sentencia.
            if (isset($seen[$epc])) {
                $index = $seen[$epc];
                $rows[$index]['read_count'] += (int) ($scan['read_count'] ?? 1);
                $rows[$index]['max_rssi'] = max(
                    $rows[$index]['max_rssi'],
                    $this->rssiOf($scan),
                );

                continue;
            }

            $seen[$epc] = count($rows);
            $rows[] = [
                'inventory_cycle_id' => $cycle->id,
                'epc' => $epc,
                'tag_id' => $tagIds[$epc] ?? null,
                'zone_id' => $scan['zone_id'] ?? null,
                'device_id' => $deviceId,
                'first_seen_at' => $scan['read_at'] ?? $now,
                'last_seen_at' => $scan['read_at'] ?? $now,
                'read_count' => (int) ($scan['read_count'] ?? 1),
                'max_rssi' => $this->rssiOf($scan),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $scan */
    private function rssiOf(array $scan): float
    {
        // -120 dBm es el suelo práctico: cualquier lectura real lo supera.
        return isset($scan['rssi']) ? (float) $scan['rssi'] : -120.0;
    }
}
