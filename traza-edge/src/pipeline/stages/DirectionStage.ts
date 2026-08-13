import type { Stage } from '../Pipeline.js';
import type {
  AntennaSide,
  Direction,
  PipelineContext,
  ProcessedTagRead,
  RawTagRead,
  TransitEvidence,
} from '../../types/TagRead.js';

interface Sample {
  t: number;
  side: AntennaSide;
  rssi: number;
  port: number;
}

interface TransitTrace {
  samples: Sample[];
  started: number;
}

interface Classification {
  direction: Direction;
  confidence: number;
  deltaMs: number;
}

export class DirectionStage implements Stage {
  readonly name = 'direction';

  private readonly transits = new Map<string, TransitTrace>();

  private static readonly MIN_SAMPLES = 3;
  private static readonly MIN_DWELL_MS = 300;
  /** Por debajo de esto no se afirma una dirección: se dice que no se sabe. */
  private static readonly MIN_CONFIDENCE = 0.5;
  /**
   * Cuota de potencia que el lado de destino debe reunir para que el cruce
   * sea creíble. Un tag que solo roza la antena exterior se queda muy por
   * debajo, que es justo lo que distingue un cruce de un amago.
   */
  private static readonly TARGET_POWER_SHARE = 0.25;
  /** Un tag que lleva 15 s en el umbral no está cruzando: está colgado ahí. */
  private static readonly TRACE_TTL_MS = 15_000;
  private static readonly MAX_SAMPLES = 64;

  process(read: RawTagRead, ctx: PipelineContext): ProcessedTagRead | null {
    if (ctx.deviceKind !== 'lector_fijo') {
      return { ...read, sessionRef: '' };
    }

    const side = ctx.antennaConfig.get(read.antennaPort)?.side ?? 'desconocido';

    this.expire(read.lastSeen);

    const trace = this.transits.get(read.epc) ?? { samples: [], started: read.firstSeen };
    trace.samples.push({ t: read.lastSeen, side, rssi: read.rssi, port: read.antennaPort });

    // Un tag que se queda en el umbral acumularía muestras sin fin. Se
    // conservan las más recientes, que son las que describen el cruce.
    if (trace.samples.length > DirectionStage.MAX_SAMPLES) {
      trace.samples.splice(0, trace.samples.length - DirectionStage.MAX_SAMPLES);
    }

    this.transits.set(read.epc, trace);

    // Aún no hay evidencia suficiente: se retiene sin emitir.
    if (trace.samples.length < DirectionStage.MIN_SAMPLES) return null;
    if (read.lastSeen - trace.started < DirectionStage.MIN_DWELL_MS) return null;

    const { direction, confidence, deltaMs } = this.classify(trace);

    // El rastro solo se descarta cuando ha servido para algo. Borrarlo
    // siempre —como hacía el borrador de `docs/07` §4— parte un cruce real
    // en trozos de tres muestras y ninguno llega a tener los dos lados.
    if (direction === 'indeterminado' && confidence < DirectionStage.MIN_CONFIDENCE) {
      return null;
    }

    this.transits.delete(read.epc);

    const evidence: TransitEvidence = { samples: trace.samples, deltaMs };

    return { ...read, direction, confidence, evidence, sessionRef: '' };
  }

  /** Tránsitos en curso. Lo usan las pruebas y la métrica de memoria. */
  size(): number {
    return this.transits.size;
  }

  /**
   * Centroide temporal ponderado por RSSI de cada lado. Si el centroide
   * interior es anterior al exterior, el tag se movió de dentro hacia
   * fuera → salida. Si el lector expone phaseAngle o dopplerHz, esos dan
   * la dirección sin heurística y son preferibles.
   *
   * La confianza combina los dos factores que nombra `docs/07` §4: la
   * separación temporal entre centroides **y el desequilibrio de potencia
   * entre lados**. El segundo es el que distingue un cruce de un amago: el
   * tag que se asoma y retrocede deja una única lectura débil en la antena
   * exterior, y esa lectura no basta para acusar a nadie.
   */
  private classify(trace: TransitTrace): Classification {
    const inner = trace.samples.filter((s) => s.side === 'interior');
    const outer = trace.samples.filter((s) => s.side === 'exterior');

    if (inner.length === 0 || outer.length === 0) {
      return { direction: 'indeterminado', confidence: 0.3, deltaMs: 0 };
    }

    const mw = (s: Sample): number => Math.pow(10, s.rssi / 10);
    const power = (arr: Sample[]): number => arr.reduce((acc, s) => acc + mw(s), 0);
    const centroid = (arr: Sample[]): number =>
      arr.reduce((acc, s) => acc + s.t * mw(s), 0) / power(arr);

    const deltaMs = centroid(outer) - centroid(inner);

    /*
     * La separación se mide contra la duración del propio rastro, no contra
     * los 800 ms fijos del borrador de `docs/07` §4. Esa constante daba por
     * supuesto un paso lento: con un lector a 200 lecturas/s el cruce entero
     * dura 300 ms y ninguna salida real habría llegado nunca al 0.7 que pide
     * la alarma. Medido contra el propio rastro, un cruce limpio —centroides
     * al 25 % y al 75 %— da 1 vaya la persona deprisa o despacio.
     */
    const span = Math.max(...trace.samples.map((s) => s.t)) - Math.min(...trace.samples.map((s) => s.t));
    const separation = span <= 0 ? 0 : Math.min(1, Math.abs(deltaMs) / (span / 2));

    // Suelo absoluto: dos centroides a 100 ms no distinguen una dirección
    // por mucho que el rastro sea corto.
    if (Math.abs(deltaMs) < 120) {
      return { direction: 'indeterminado', confidence: separation * 0.5, deltaMs };
    }

    const pInner = power(inner);
    const pOuter = power(outer);
    const destination = deltaMs > 0 ? pOuter : pInner;
    const share = destination / (pInner + pOuter);
    const balance = Math.min(1, share / DirectionStage.TARGET_POWER_SHARE);

    const confidence = separation * balance;

    if (confidence < DirectionStage.MIN_CONFIDENCE) {
      return { direction: 'indeterminado', confidence, deltaMs };
    }

    return { direction: deltaMs > 0 ? 'salida' : 'entrada', confidence, deltaMs };
  }

  /**
   * Los tránsitos que nunca llegan a las 3 muestras se quedarían en el mapa
   * para siempre. En un portal con mucho paso eso es una fuga lenta pero
   * segura, y el proceso corre semanas sin reiniciarse.
   */
  private expire(now: number): void {
    for (const [epc, trace] of this.transits) {
      const last = trace.samples[trace.samples.length - 1]?.t ?? trace.started;
      if (now - last > DirectionStage.TRACE_TTL_MS) this.transits.delete(epc);
    }
  }
}
