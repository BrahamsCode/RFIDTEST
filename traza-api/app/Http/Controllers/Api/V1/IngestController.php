<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\HeartbeatRequest;
use App\Http\Requests\IngestReadsRequest;
use App\Jobs\ProcessReadBatch;
use App\Models\DeviceHealthBeat;
use App\Services\ReadIngestionService;
use Illuminate\Http\JsonResponse;

final class IngestController extends Controller
{
    public function __construct(
        private readonly ReadIngestionService $ingestion,
    ) {}

    public function store(IngestReadsRequest $request): JsonResponse
    {
        $device = $request->device();
        $batchId = (string) $request->string('batch_id');

        // Idempotencia: si el borde reintenta por un timeout de red, la
        // respuesta original pudo perderse aunque el lote sí se guardara.
        // Reprocesarlo duplicaría lecturas.
        if ($this->ingestion->batchAlreadyProcessed($batchId)) {
            return response()->json([
                'status' => 'duplicate',
                'accepted' => 0,
                'rejected' => 0,
                'batch_id' => $batchId,
            ], 200);
        }

        $result = $this->ingestion->ingest(
            device: $device,
            batchId: $batchId,
            reads: $request->validated('reads'),
            sessionRef: $request->input('session_ref'),
            cycleId: $request->integer('inventory_cycle_id') ?: null,
        );

        ProcessReadBatch::dispatch($result->batchId, $device->id)->onQueue('reads');

        return response()->json([
            'accepted' => $result->accepted,
            'rejected' => $result->rejected,
            'batch_id' => $result->batchId,
            'queued_job' => 'ProcessReadBatch',
        ], 202);
    }

    /**
     * Latido del borde. Un latido perdido no es crítico: el servidor detecta
     * la ausencia por `last_seen_at`, así que se acepta lo que llegue y no se
     * penaliza al dispositivo por un campo de más o de menos.
     */
    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $device = $request->device();

        DeviceHealthBeat::create([
            'device_id' => $device->id,
            'beat_at' => now(),
            'cpu_percent' => $request->input('cpu_percent'),
            'temperature_c' => $request->input('temperature_c'),
            'battery_pct' => $request->input('battery_pct'),
            'reads_last_min' => $request->input('reads_last_min'),
            'buffer_depth' => $request->input('buffer_depth'),
            'payload' => $request->input('readers', []),
        ]);

        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['status' => 'ok'], 202);
    }
}
