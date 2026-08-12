import type { Stage } from '../Pipeline.js';
import type {
  AntennaSide,
  Direction,
  PipelineContext,
  ProcessedTagRead,
  RawTagRead,
} from '../../types/TagRead.js';

interface Sample {
  t: number;
  side: AntennaSide;
  rssi: number;
}

interface TransitTrace {
  samples: Sample[];
  started: number;
}

export class DirectionStage implements Stage {
  readonly name = 'direction';

  private readonly transits = new Map<string, TransitTrace>();

  private static readonly MIN_SAMPLES = 3;
  private static readonly MIN_DWELL_MS = 300;

  process(read: RawTagRead, ctx: PipelineContext): ProcessedTagRead | null {
    if (ctx.deviceKind !== 'lector_fijo') {
      return { ...read, sessionRef: '' };
    }

    const side = ctx.antennaConfig.get(read.antennaPort)?.side ?? 'desconocido';

    const trace = this.transits.get(read.epc) ?? { samples: [], started: read.firstSeen };
    trace.samples.push({ t: read.lastSeen, side, rssi: read.rssi });
    this.transits.set(read.epc, trace);

    // Aún no hay evidencia suficiente: se retiene sin emitir.
    if (trace.samples.length < DirectionStage.MIN_SAMPLES) return null;
    if (read.lastSeen - trace.started < DirectionStage.MIN_DWELL_MS) return null;

    const { direction, confidence } = this.classify(trace);
    this.transits.delete(read.epc);

    if (direction === 'indeterminado' && confidence < 0.5) return null;

    return { ...read, direction, confidence, sessionRef: '' };
  }

  /**
   * Centroide temporal ponderado por RSSI de cada lado. Si el centroide
   * interior es anterior al exterior, el tag se movió de dentro hacia
   * fuera → salida. Si el lector expone phaseAngle o dopplerHz, esos dan
   * la dirección sin heurística y son preferibles.
   */
  private classify(trace: TransitTrace): { direction: Direction; confidence: number } {
    const inner = trace.samples.filter((s) => s.side === 'interior');
    const outer = trace.samples.filter((s) => s.side === 'exterior');

    if (inner.length === 0 || outer.length === 0) {
      return { direction: 'indeterminado', confidence: 0.3 };
    }

    const centroid = (arr: Sample[]): number => {
      const w = arr.reduce((acc, s) => acc + Math.pow(10, s.rssi / 10), 0);
      return arr.reduce((acc, s) => acc + s.t * Math.pow(10, s.rssi / 10), 0) / w;
    };

    const deltaMs = centroid(outer) - centroid(inner);
    const confidence = Math.min(1, Math.abs(deltaMs) / 800);

    if (Math.abs(deltaMs) < 120) {
      return { direction: 'indeterminado', confidence: confidence * 0.5 };
    }

    return { direction: deltaMs > 0 ? 'salida' : 'entrada', confidence };
  }
}
