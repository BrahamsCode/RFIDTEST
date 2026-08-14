<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertKind;
use App\Enums\DeviceKind;
use App\Models\Device;
use App\Models\InventoryCycle;
use App\Services\AlertService;
use App\Services\InventoryCycleService;
use App\Services\TagResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Procesa las lecturas ya guardadas de un lote. Ver `docs/06` §4.4.
 *
 * Resuelve tags, registra los EPC desconocidos, detecta clonación por TID y
 * enruta según el tipo de dispositivo.
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

    public function handle(
        TagResolver $resolver,
        AlertService $alerts,
        InventoryCycleService $cycles,
    ): void {
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

        // Se resuelve una vez por lote, no por EPC: en un barrido son miles
        // de EPC y todos van al mismo ciclo.
        $cycle = $device->kind === DeviceKind::Handheld
            ? $this->activeCycleFor($device)
            : null;

        $scanned = [];

        foreach ($reads->groupBy('epc') as $epc => $epcReads) {
            $tag = $resolver->find($device->organization_id, (string) $epc);

            if ($tag === null) {
                $resolver->recordUnknown((string) $epc, $device);

                continue;
            }

            $this->detectCloning($tag, $epcReads, $device, $alerts);

            match ($device->kind) {
                DeviceKind::Handheld => $scanned[] = ['epc' => (string) $epc],

                /*
                 * Los lectores fijos **no** generan aquí eventos de portal.
                 * El portal va por el camino rápido MQTT de `docs/07` §6 y
                 * ya escribió su `portal_events` con dirección y confianza;
                 * volver a evaluarlo desde la ingesta duplicaría la alarma y,
                 * peor, lo haría sin dirección —`tag_reads` no la guarda—, así
                 * que todo saldría como `indeterminado`.
                 *
                 * Lo que sí aporta esta vía es la presencia: la lectura ya
                 * quedó en `tag_reads` y `TagResolver` refrescó
                 * `last_seen_at`.
                 */
                DeviceKind::LectorFijo, DeviceKind::Edge => null,

                default => null,
            };
        }

        if ($cycle !== null && $scanned !== []) {
            // En bloque y no uno a uno: `registerScans` deduplica y emite un
            // solo evento de avance por lote. Con un evento por EPC, un
            // barrido de 20 000 prendas ahogaría al navegador.
            $cycles->registerScans($cycle, $scanned, $device->id);
        }
    }

    /**
     * Ciclo en curso de la tienda del handheld.
     *
     * Solo `en_curso`: un ciclo pausado está pausado a propósito —el operario
     * paró para atender a un cliente— y seguir contándole lecturas haría que
     * la pausa no sirviera de nada.
     */
    private function activeCycleFor(Device $device): ?InventoryCycle
    {
        if ($device->location_id === null) {
            return null;
        }

        return InventoryCycle::query()
            ->where('location_id', $device->location_id)
            ->where('status', 'en_curso')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Mismo EPC leído con un TID distinto al grabado en fábrica: o alguien
     * clonó la etiqueta, o se re-etiquetó sin registrarlo.
     *
     * @param  Collection<int, object>  $epcReads
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
