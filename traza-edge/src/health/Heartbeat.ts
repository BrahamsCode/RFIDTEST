import type { ApiClient } from '../transport/ApiClient.js';
import type { Pipeline } from '../pipeline/Pipeline.js';
import type { SqliteBuffer } from '../buffer/SqliteBuffer.js';
import { CpuSampler, temperatureC } from './system.js';

export interface HeartbeatOptions {
  api: ApiClient;
  pipeline: Pipeline;
  buffer: SqliteBuffer;
  deviceCode: string;
  readerStatus: () => Record<string, boolean>;
  version: string;
  /**
   * Lecturas del último minuto. Es un parámetro y no `pipeline.snapshot()`
   * porque el snapshot es acumulado desde el arranque: enviarlo como
   * «último minuto» haría que un lector muerto siguiera reportando millones
   * de lecturas y nadie notara que dejó de leer.
   */
  readsLastMinute: () => number;
  intervalMs?: number;
}

export class Heartbeat {
  private timer?: NodeJS.Timeout;
  private readonly cpu = new CpuSampler();
  private readonly intervalMs: number;

  constructor(private readonly options: HeartbeatOptions) {
    this.intervalMs = options.intervalMs ?? 30_000;
  }

  start(): void {
    this.timer = setInterval(() => void this.beat(), this.intervalMs);
  }

  stop(): void {
    if (this.timer) clearInterval(this.timer);
    this.timer = undefined;
  }

  /** Un latido. Público para que las pruebas no dependan del temporizador. */
  async beat(): Promise<void> {
    try {
      await this.options.api.postHeartbeat({
        device_code: this.options.deviceCode,
        cpu_percent: this.cpu.percent(),
        temperature_c: await temperatureC(),
        reads_last_min: this.options.readsLastMinute(),
        buffer_depth: this.options.buffer.depth(),
        readers: this.options.readerStatus(),
        uptime_s: Math.round(process.uptime()),
        version: this.options.version,
      });
    } catch {
      // Un latido perdido no es crítico: el servidor detecta la ausencia por
      // `last_seen_at`. Reintentar aquí solo añadiría tráfico cuando la red
      // ya está mal.
    }
  }
}
