import type { ProcessedTagRead } from '../types/TagRead.js';

export interface HeartbeatPayload {
  device_code: string;
  reads_last_min: number;
  buffer_depth: number;
  readers: Record<string, boolean>;
  uptime_s: number;
  version: string;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export class ApiClient {
  constructor(
    private readonly baseUrl: string,
    private readonly deviceToken: string,
    private readonly timeoutMs = 15_000,
  ) {}

  async postReads(batchId: string, reads: ProcessedTagRead[]): Promise<void> {
    await this.post('/api/v1/ingest/reads', { batch_id: batchId, reads });
  }

  async postHeartbeat(payload: HeartbeatPayload): Promise<void> {
    await this.post('/api/v1/ingest/heartbeat', payload);
  }

  private async post(path: string, body: unknown): Promise<void> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);

    try {
      const res = await fetch(new URL(path, this.baseUrl), {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          accept: 'application/json',
          'x-device-token': this.deviceToken,
        },
        body: JSON.stringify(body),
        signal: controller.signal,
      });

      if (!res.ok) {
        throw new ApiError(`${path} respondió ${res.status}`, res.status);
      }
    } finally {
      clearTimeout(timer);
    }
  }
}
