import { config } from './config/index.js';
import { Pipeline } from './pipeline/Pipeline.js';
import { EpcMaskStage } from './pipeline/stages/EpcMaskStage.js';
import { RssiThresholdStage } from './pipeline/stages/RssiThresholdStage.js';
import { DedupeStage } from './pipeline/stages/DedupeStage.js';
import { MinCountStage } from './pipeline/stages/MinCountStage.js';
import { DirectionStage } from './pipeline/stages/DirectionStage.js';
import { SimulatorAdapter } from './readers/SimulatorAdapter.js';
import { PROFILES, type ReaderAdapter } from './readers/ReaderAdapter.js';
import { SqliteBuffer } from './buffer/SqliteBuffer.js';
import { Flusher } from './buffer/Flusher.js';
import { ApiClient } from './transport/ApiClient.js';
import { Heartbeat } from './health/Heartbeat.js';
import { startMetricsServer } from './health/metrics.js';
import type { AntennaConfig, PipelineContext, ProcessedTagRead } from './types/TagRead.js';

const VERSION = '0.1.0';

async function main(): Promise<void> {
  const env = config();
  const isPortal = env.PORTAL_ENABLED;

  console.log(`[edge] TRAZA edge ${VERSION} — modo ${env.READER_MODE}, tienda ${env.TRAZA_LOCATION_CODE}`);

  if (env.READER_MODE !== 'simulator') {
    // LlrpAdapter y HttpWebhookAdapter se implementan en la tarea 2.7,
    // cuando esté decidido el modelo de lector (tarea 0.1).
    throw new Error(
      `READER_MODE="${env.READER_MODE}" aún no implementado. Use "simulator" hasta cerrar la tarea 0.1.`,
    );
  }

  const reader: ReaderAdapter = new SimulatorAdapter('SIM-01', {
    epcPrefix: env.EPC_MASK,
    scenario: env.SIMULATOR_SCENARIO,
    seed: env.SIMULATOR_SEED,
    overrides: {
      populationSize: env.SIMULATOR_POPULATION,
      missRate: env.SIMULATOR_MISS_RATE,
      strayRate: env.SIMULATOR_STRAY_RATE,
    },
  });

  const profile = isPortal ? PROFILES['PORTAL']! : PROFILES['INVENTARIO']!;

  const antennaConfig = new Map<number, AntennaConfig>([
    [1, { port: 1, side: 'interior' }],
    [2, { port: 2, side: 'exterior' }],
    [3, { port: 3, side: 'interior' }],
    [4, { port: 4, side: 'exterior' }],
  ]);

  const ctx: PipelineContext = {
    readerId: reader.id,
    deviceKind: isPortal ? 'lector_fijo' : 'handheld',
    profile,
    antennaConfig,
  };

  const pipeline = new Pipeline([
    new EpcMaskStage([env.EPC_MASK], env.EPC_TEST_PREFIX, env.NODE_ENV === 'production'),
    new RssiThresholdStage(),
    new DedupeStage(),
    new MinCountStage(),
    new DirectionStage(),
  ]);

  const buffer = new SqliteBuffer(env.EDGE_BUFFER_PATH, env.EDGE_BUFFER_MAX_ROWS);
  const api = new ApiClient(env.TRAZA_API_URL, env.TRAZA_DEVICE_TOKEN);
  const flusher = new Flusher(buffer, api, env.EDGE_FLUSH_BATCH);

  let connected = false;
  const pending: ProcessedTagRead[] = [];

  reader.on('read', (raw) => {
    const processed = pipeline.run(raw, ctx);
    if (processed) pending.push(processed);
  });
  reader.on('error', (err) => console.error('[reader]', err.message));
  reader.on('disconnected', () => {
    connected = false;
  });

  // Se agrupan las lecturas antes de tocar SQLite: una transacción por
  // segundo en vez de una por lectura.
  const drainToBuffer = (): void => {
    if (pending.length === 0) return;
    buffer.enqueueMany(pending.splice(0, pending.length));
  };
  const drainTimer = setInterval(drainToBuffer, 1000);

  const heartbeat = new Heartbeat(api, pipeline, buffer, env.TRAZA_DEVICE_CODE, () => ({ [reader.id]: connected }), VERSION);
  const metrics = startMetricsServer(env.METRICS_PORT, {
    readerId: reader.id,
    pipeline,
    buffer,
    readerConnected: () => connected,
  });

  await reader.connect();
  await reader.applyProfile(profile);
  await reader.startInventory();
  connected = true;

  flusher.start();
  heartbeat.start();
  console.log(`[edge] Escuchando. Métricas en :${env.METRICS_PORT}/metrics`);

  let shuttingDown = false;
  const shutdown = async (signal: string): Promise<void> => {
    if (shuttingDown) return;
    shuttingDown = true;
    console.log(`[edge] Recibida señal ${signal}, cerrando ordenadamente…`);

    // 1. Dejar de aceptar lecturas nuevas.
    await reader.stopInventory();
    await reader.disconnect();
    connected = false;

    // 2. Vaciar lo que quede en memoria hacia SQLite: no se pierde nada.
    clearInterval(drainTimer);
    drainToBuffer();

    // 3. Un último envío, con límite de tiempo.
    flusher.stop();
    heartbeat.stop();
    await Promise.race([
      flusher.flushOnce().catch(() => 0),
      new Promise((r) => setTimeout(r, 5000)),
    ]);

    // 4. Cerrar recursos.
    const remaining = buffer.depth();
    metrics.close();
    buffer.close();
    console.log(`[edge] Cerrado. Pendientes en buffer: ${remaining}`);
    process.exit(0);
  };

  process.on('SIGTERM', () => void shutdown('SIGTERM'));
  process.on('SIGINT', () => void shutdown('SIGINT'));
}

main().catch((err) => {
  console.error('[edge] Fallo en el arranque:', err instanceof Error ? err.message : err);
  process.exit(1);
});
