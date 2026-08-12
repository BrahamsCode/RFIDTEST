<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertKind;
use App\Enums\DeviceKind;
use App\Models\Device;
use App\Services\AlertService;
use App\Services\TagResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Procesa las lecturas ya guardadas de un lote. Ver `docs/06` §4.4.
 *
 * Alcance actual (tarea 2.2 parcial): resolución de tags, registro de EPC
 * desconocidos y detección de clonación por TID. El enrutado por tipo de
 * dispositivo queda pendiente de que existan `InventoryCycleService`
 * (tarea 3.1) y `PortalEventService` (tarea 6.x).
 */
final class ProcessReadBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $batchId,
        public readonly int $deviceId,
    ) {}

    public function handle(TagResolver $resolver, AlertService $alerts): void
    {
        $device = Device::find($this->deviceId);

        if ($device === null) {
            return;
        }

        /*
         * ⚠️ Esta consulta es la de `docs/06` §4.4 tal cual, y tiene un
         * problema de diseño: recibe `batchId` pero no filtra por él, así que
         * cada lote reprocesa TODA la ventana de 10 minutos del dispositivo.
         * Con el borde vaciando cada segundo el trabajo crece de forma
         * cuadrática y `unknown_epcs.seen_count` se infla (medido: 2240
         * avistamientos para 1386 lecturas reales).
         *
         * Arreglarlo bien exige una columna `batch_id` en `tag_reads`, que es
         * un cambio del modelo de datos y por tanto una decisión que no
         * corresponde tomar aquí. Ver la nota de la tarea 2.2 en TASKS.md.
         */
        $reads = DB::table('tag_reads')
            ->where('device_id', $this->deviceId)
            ->where('ingested_at', '>=', now()->subMinutes(10))
            ->orderBy('read_at')
            ->get();

        foreach ($reads->groupBy('epc') as $epc => $epcReads) {
            $tag = $resolver->find($device->organization_id, (string) $epc);

            if ($tag === null) {
                $resolver->recordUnknown((string) $epc, $device);

                continue;
            }

            $this->detectCloning($tag, $epcReads, $device, $alerts);

            match ($device->kind) {
                // Pendiente: InventoryCycleService::registerScan() (tarea 3.1)
                DeviceKind::Handheld => null,
                // Pendiente: PortalEventService::evaluate() (tarea 6.x)
                DeviceKind::LectorFijo => null,
                default => null,
            };
        }
    }

    /**
     * Mismo EPC leído con un TID distinto al grabado en fábrica: o alguien
     * clonó la etiqueta, o se re-etiquetó sin registrarlo.
     *
     * @param \Illuminate\Support\Collection<int, object> $epcReads
     */
    private function detectCloning(
        $tag,
        $epcReads,
        Device $device,
        AlertService $alerts,
    ): void {
        if ($tag->tid === null) {
            return;
        }

        $tids = $epcReads->pluck('tid')->filter()->unique();

        if ($tids->isEmpty() || $tids->contains($tag->tid)) {
            return;
        }

        $alerts->raiseOnce(AlertKind::TidDiscrepante, $tag, $device, [
            'tid_registrado' => $tag->tid,
            'tid_leidos' => $tids->values()->all(),
            'batch_id' => $this->batchId,
        ]);
    }
}
