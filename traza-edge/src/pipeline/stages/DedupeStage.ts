import type { Stage } from '../Pipeline.js';
import type { PipelineContext, RawTagRead } from '../../types/TagRead.js';

export class DedupeStage implements Stage {
  readonly name = 'dedupe';

  /** clave: `${readerId}:${epc}` → epoch ms de la última emisión */
  private readonly lastEmitted = new Map<string, number>();
  // Se ancla a la marca de la primera lectura, no al reloj de pared: las
  // dos escalas no tienen por qué coincidir y el barrido no se dispararía.
  private lastSweep = 0;

  process(read: RawTagRead, ctx: PipelineContext): RawTagRead | null {
    const key = `${read.readerId}:${read.epc}`;
    const now = read.lastSeen;
    const window = ctx.profile.dedupWindowMs;

    const previous = this.lastEmitted.get(key);
    if (previous !== undefined && now - previous < window) return null;

    this.lastEmitted.set(key, now);
    this.sweepIfNeeded(now, window);
    return read;
  }

  size(): number {
    return this.lastEmitted.size;
  }

  /**
   * Sin esta limpieza el mapa crece sin límite durante un inventario de
   * 20 000 tags y termina agotando la memoria del mini-PC.
   */
  private sweepIfNeeded(now: number, window: number): void {
    if (this.lastSweep === 0) {
      this.lastSweep = now;
      return;
    }
    if (now - this.lastSweep < 60_000) return;

    const cutoff = now - window * 10;
    for (const [key, ts] of this.lastEmitted) {
      if (ts < cutoff) this.lastEmitted.delete(key);
    }
    this.lastSweep = now;
  }
}
