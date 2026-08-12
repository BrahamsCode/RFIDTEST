import type { PipelineContext, ProcessedTagRead, RawTagRead } from '../types/TagRead.js';

export interface Stage {
  readonly name: string;
  /** Devuelve la lectura (posiblemente enriquecida) o null para descartarla. */
  process(read: RawTagRead, ctx: PipelineContext): RawTagRead | ProcessedTagRead | null;
}

export class Pipeline {
  private readonly counters = new Map<string, number>();

  constructor(private readonly stages: Stage[]) {}

  run(read: RawTagRead, ctx: PipelineContext): ProcessedTagRead | null {
    let current: RawTagRead | ProcessedTagRead = read;

    for (const stage of this.stages) {
      const next = stage.process(current, ctx);
      if (next === null) {
        this.increment(`dropped.${stage.name}`);
        return null;
      }
      current = next;
    }

    this.increment('passed');
    return this.ensureProcessed(current);
  }

  /** Métricas expuestas en /metrics para Prometheus. */
  snapshot(): Record<string, number> {
    return Object.fromEntries(this.counters);
  }

  private ensureProcessed(read: RawTagRead | ProcessedTagRead): ProcessedTagRead {
    return 'sessionRef' in read ? read : { ...read, sessionRef: '' };
  }

  private increment(key: string): void {
    this.counters.set(key, (this.counters.get(key) ?? 0) + 1);
  }
}
