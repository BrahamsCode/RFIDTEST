<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ingestion\IngestResult;
use App\Models\Device;
use App\Support\EpcMask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ReadIngestionService
{
    /**
     * 500 filas por sentencia: el punto dulce entre número de viajes a la
     * base y tamaño de la sentencia.
     */
    private const INSERT_CHUNK = 500;

    /** Ventana de idempotencia. El borde reintenta durante minutos, no horas. */
    private const BATCH_TTL_HOURS = 6;

    public function batchAlreadyProcessed(string $batchId): bool
    {
        return Cache::has($this->batchKey($batchId));
    }

    /**
     * @param  list<array<string, mixed>>  $reads
     */
    public function ingest(
        Device $device,
        string $batchId,
        array $reads,
        ?string $sessionRef = null,
        ?int $cycleId = null,
    ): IngestResult {
        $mask = EpcMask::forOrganization($device->organization_id);
        $zonesByPort = $this->zonesByPort($device);
        $now = now();

        $accepted = [];
        $rejected = 0;

        foreach ($reads as $read) {
            $epc = strtoupper((string) $read['epc']);

            // Filtro [1] del pipeline. El borde ya filtró, pero se repite:
            // el servidor nunca confía en la validación del cliente.
            if (! $mask->matches($epc)) {
                $rejected++;
                continue;
            }

            $antenna = isset($read['antenna']) ? (int) $read['antenna'] : null;

            $accepted[] = [
                'read_at' => $read['read_at'],
                'epc' => $epc,
                'tid' => isset($read['tid']) ? strtoupper((string) $read['tid']) : null,
                'device_id' => $device->id,
                'antenna_port' => $antenna,
                'location_id' => $device->location_id,
                'zone_id' => $antenna === null ? null : ($zonesByPort[$antenna] ?? null),
                'rssi' => $read['rssi'] ?? null,
                'phase_angle' => $read['phase_angle'] ?? null,
                'doppler_hz' => $read['doppler_hz'] ?? null,
                'read_count' => $read['read_count'] ?? 1,
                'session_ref' => $sessionRef,
                'inventory_cycle_id' => $cycleId,
                'ingested_at' => $now,
            ];
        }

        foreach (array_chunk($accepted, self::INSERT_CHUNK) as $chunk) {
            // DB::table y no Eloquent: crear 500 modelos para insertarlos es
            // un desperdicio de memoria y CPU de un orden de magnitud, y
            // tag_reads no tiene comportamiento de dominio. Es un registro.
            DB::table('tag_reads')->insert($chunk);
        }

        $this->markBatchProcessed($batchId);

        return new IngestResult($batchId, count($accepted), $rejected);
    }

    public function markBatchProcessed(string $batchId): void
    {
        Cache::put($this->batchKey($batchId), true, now()->addHours(self::BATCH_TTL_HOURS));
    }

    /**
     * Mapa puerto de antena → zona. Permite saber en qué zona de la tienda
     * se leyó cada tag sin una consulta por lectura.
     *
     * @return array<int, int|null>
     */
    private function zonesByPort(Device $device): array
    {
        return $device->antennas()
            ->pluck('zone_id', 'port_number')
            ->all();
    }

    private function batchKey(string $batchId): string
    {
        return "ingest:batch:{$batchId}";
    }
}
