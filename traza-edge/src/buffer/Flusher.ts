import { randomUUID } from 'node:crypto';
import type { ApiClient } from '../transport/ApiClient.js';
import type { SqliteBuffer } from './SqliteBuffer.js';

export interface FlushObserver {
  /** Duración del intento, en segundos. Se registra también si falla. */
  observe(seconds: number): void;
}

export class Flusher {
  private delay = 1000;
  private timer?: NodeJS.Timeout;
  private running = false;

  constructor(
    private readonly buffer: SqliteBuffer,
    private readonly api: ApiClient,
    private readonly batchSize = 500,
    private readonly duration?: FlushObserver,
  ) {}

  start(): void {
    if (this.running) return;
    this.running = true;
    this.schedule(0);
  }

  stop(): void {
    this.running = false;
    if (this.timer) clearTimeout(this.timer);
    this.timer = undefined;
  }

  /** Un único intento de vaciado. Devuelve cuántas lecturas se enviaron. */
  async flushOnce(): Promise<number> {
    const items = this.buffer.take(this.batchSize);
    if (items.length === 0) return 0;

    // Se cronometra también el intento fallido: un vaciado que tarda 30 s en
    // dar timeout es justo el síntoma que interesa ver en la gráfica, y
    // medir solo los éxitos lo escondería.
    const started = process.hrtime.bigint();

    try {
      await this.api.postReads(
        randomUUID(),
        items.map((i) => i.payload),
      );
    } finally {
      this.duration?.observe(Number(process.hrtime.bigint() - started) / 1e9);
    }

    this.buffer.ack(items.map((i) => i.id));
    return items.length;
  }

  currentDelay(): number {
    return this.delay;
  }

  private schedule(ms: number): void {
    if (!this.running) return;
    this.timer = setTimeout(() => void this.tick(), ms);
  }

  private async tick(): Promise<void> {
    try {
      const sent = await this.flushOnce();
      this.delay = sent > 0 ? 1000 : 2000;
    } catch (err) {
      // Retroceso exponencial hasta 1 minuto. No se hace ack: se reintenta.
      this.delay = Math.min(this.delay * 2, 60_000);
      console.error(`[flusher] Fallo de envío, reintento en ${this.delay} ms`, err);
    }
    this.schedule(this.delay);
  }
}
