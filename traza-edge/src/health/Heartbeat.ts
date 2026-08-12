import type { ApiClient } from '../transport/ApiClient.js';
import type { Pipeline } from '../pipeline/Pipeline.js';
import type { SqliteBuffer } from '../buffer/SqliteBuffer.js';

export class Heartbeat {
  private timer?: NodeJS.Timeout;

  constructor(
    private readonly api: ApiClient,
    private readonly pipeline: Pipeline,
    private readonly buffer: SqliteBuffer,
    private readonly deviceCode: string,
    private readonly readerStatus: () => Record<string, boolean>,
    private readonly version: string,
    private readonly intervalMs = 30_000,
  ) {}

  start(): void {
    this.timer = setInterval(() => void this.beat(), this.intervalMs);
  }

  stop(): void {
    if (this.timer) clearInterval(this.timer);
    this.timer = undefined;
  }

  private async beat(): Promise<void> {
    try {
      await this.api.postHeartbeat({
        device_code: this.deviceCode,
        reads_last_min: this.pipeline.snapshot()['passed'] ?? 0,
        buffer_depth: this.buffer.depth(),
        readers: this.readerStatus(),
        uptime_s: Math.round(process.uptime()),
        version: this.version,
      });
    } catch {
      // Un latido perdido no es crítico; el servidor detecta la ausencia.
    }
  }
}
