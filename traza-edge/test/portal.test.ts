import { describe, expect, it, vi } from 'vitest';
import { Pipeline } from '../src/pipeline/Pipeline.js';
import { EpcMaskStage } from '../src/pipeline/stages/EpcMaskStage.js';
import { RssiThresholdStage } from '../src/pipeline/stages/RssiThresholdStage.js';
import { DedupeStage } from '../src/pipeline/stages/DedupeStage.js';
import { MinCountStage } from '../src/pipeline/stages/MinCountStage.js';
import { DirectionStage } from '../src/pipeline/stages/DirectionStage.js';
import { SimulatorAdapter } from '../src/readers/SimulatorAdapter.js';
import { PROFILES } from '../src/readers/ReaderAdapter.js';
import {
  PortalPublisher,
  type MqttLike,
  type PortalMessage,
} from '../src/transport/PortalPublisher.js';
import type {
  AntennaConfig,
  PipelineContext,
  ProcessedTagRead,
} from '../src/types/TagRead.js';

const MASK = '3035D9';

function portalCtx(): PipelineContext {
  return {
    readerId: 'SIM-01',
    deviceKind: 'lector_fijo',
    profile: PROFILES['PORTAL']!,
    antennaConfig: new Map<number, AntennaConfig>([
      [1, { port: 1, side: 'interior' }],
      [2, { port: 2, side: 'exterior' }],
      [3, { port: 3, side: 'interior' }],
      [4, { port: 4, side: 'exterior' }],
    ]),
  };
}

function portalPipeline(): Pipeline {
  return new Pipeline([
    new EpcMaskStage([MASK], 'FFFF', false),
    new RssiThresholdStage(),
    new DedupeStage(),
    new MinCountStage(),
    new DirectionStage(),
  ]);
}

/**
 * El simulador emite a 5 ms por lectura, muy por debajo de la ventana de
 * permanencia mínima. Se estira el reloj para que un cruce parezca lo que es
 * en la realidad: cerca de un segundo entre la primera y la última antena.
 */
function crossings(scenario: string, count: number, seed = 11): ProcessedTagRead[] {
  const sim = new SimulatorAdapter('SIM-01', { epcPrefix: MASK, scenario, seed });
  const pipeline = portalPipeline();
  const ctx = portalCtx();
  const t0 = 1_700_000_000_000;

  return sim
    .generate(count, t0)
    .map((r, i) => {
      // 250 ms: por encima de la ventana antirrebote del perfil PORTAL, que
      // si no descartaría la mitad de las muestras del cruce.
      const t = t0 + i * 250;
      return pipeline.run({ ...r, firstSeen: t, lastSeen: t }, ctx);
    })
    .filter((r): r is ProcessedTagRead => r !== null);
}

// ------------------------------------------------------ tarea 6.1

describe('escenario portal_salida', () => {
  it('clasifica los cruces como salida con confianza ≥ 0.7', () => {
    const out = crossings('portal_salida', 240);
    const salidas = out.filter((r) => r.direction === 'salida');

    expect(salidas.length).toBeGreaterThan(0);
    expect(salidas.every((r) => (r.confidence ?? 0) >= 0.7)).toBe(true);
  });

  it('conserva la secuencia de antenas como evidencia', () => {
    // Sin evidencia no hay forma de revisar una alarma discutida, y la
    // clasificación es heurística: se equivoca.
    const salida = crossings('portal_salida', 240).find((r) => r.direction === 'salida');

    expect(salida?.evidence).toBeDefined();
    expect(salida!.evidence!.samples.length).toBeGreaterThanOrEqual(3);
    expect(salida!.evidence!.samples.some((s) => s.side === 'interior')).toBe(true);
    expect(salida!.evidence!.samples.some((s) => s.side === 'exterior')).toBe(true);
  });
});

describe('escenario portal_dudoso', () => {
  it('no emite ninguna salida cuando el tag se acerca y retrocede', () => {
    const out = crossings('portal_dudoso', 240);

    expect(out.filter((r) => r.direction === 'salida')).toHaveLength(0);
  });

  it('nada de lo que emite supera el umbral de alarma', () => {
    const publisher = new PortalPublisher({
      locationCode: 'LIM-01',
      deviceCode: 'PORTAL-01',
      minConfidence: 0.7,
    });

    const publicables = crossings('portal_dudoso', 240).filter((r) =>
      publisher.shouldPublish(r),
    );

    expect(publicables).toHaveLength(0);
  });
});

describe('DirectionStage: higiene de memoria', () => {
  it('olvida los tránsitos que nunca completan el cruce', () => {
    const stage = new DirectionStage();
    const ctx = portalCtx();
    const t0 = 5_000_000;

    // 50 tags que asoman una vez y desaparecen: nunca llegan a 3 muestras.
    for (let i = 0; i < 50; i++) {
      const t = t0 + i;
      stage.process(
        {
          epc: `${MASK}${String(i).padStart(18, '0')}`,
          antennaPort: 1,
          rssi: -50,
          firstSeen: t,
          lastSeen: t,
          readCount: 3,
          readerId: 'SIM-01',
        },
        ctx,
      );
    }

    expect(stage.size()).toBe(50);

    // Un tag más, 20 s después: la limpieza barre los anteriores.
    stage.process(
      {
        epc: `${MASK}0000000000000000FF`,
        antennaPort: 1,
        rssi: -50,
        firstSeen: t0 + 20_000,
        lastSeen: t0 + 20_000,
        readCount: 3,
        readerId: 'SIM-01',
      },
      ctx,
    );

    expect(stage.size()).toBe(1);
  });
});

// ------------------------------------------------------ tarea 6.2

describe('PortalPublisher', () => {
  function fakeMqtt(connected = true): MqttLike & { sent: Array<[string, string]> } {
    const sent: Array<[string, string]> = [];
    return {
      connected,
      sent,
      async publishAsync(topic: string, message: string) {
        sent.push([topic, message]);
      },
    };
  }

  const salida: ProcessedTagRead = {
    epc: `${MASK}000000000000000042`,
    antennaPort: 2,
    rssi: -48,
    firstSeen: 1_700_000_000_000,
    lastSeen: 1_700_000_000_900,
    readCount: 5,
    readerId: 'SIM-01',
    sessionRef: '',
    direction: 'salida',
    confidence: 0.91,
    evidence: { samples: [], deltaMs: 730 },
  };

  it('publica en traza/{tienda}/portal con QoS 1', async () => {
    const mqtt = fakeMqtt();
    const spy = vi.spyOn(mqtt, 'publishAsync');
    const publisher = new PortalPublisher({
      locationCode: 'LIM-01',
      deviceCode: 'PORTAL-01',
      minConfidence: 0.7,
      mqtt,
    });

    expect(await publisher.publish(salida)).toBe(true);
    expect(spy).toHaveBeenCalledWith('traza/LIM-01/portal', expect.any(String), { qos: 1 });

    const message = JSON.parse(mqtt.sent[0]![1]) as PortalMessage;
    expect(message.epc).toBe(salida.epc);
    expect(message.deviceCode).toBe('PORTAL-01');
    expect(message.confidence).toBe(0.91);
    expect(message.occurredAt).toBe(new Date(salida.lastSeen).toISOString());
  });

  it('no publica entradas ni cruces por debajo del umbral', async () => {
    const mqtt = fakeMqtt();
    const publisher = new PortalPublisher({
      locationCode: 'LIM-01',
      deviceCode: 'PORTAL-01',
      minConfidence: 0.7,
      mqtt,
    });

    await publisher.publish({ ...salida, direction: 'entrada' });
    await publisher.publish({ ...salida, confidence: 0.55 });
    await publisher.publish({ ...salida, direction: 'indeterminado', confidence: 0.99 });

    expect(mqtt.sent).toHaveLength(0);
    expect(publisher.snapshot()['portal.suppressed']).toBe(3);
  });

  it('cae al respaldo HTTP cuando el broker no está conectado', async () => {
    const fallback = { postPortalEvent: vi.fn(async () => {}) };
    const publisher = new PortalPublisher({
      locationCode: 'LIM-01',
      deviceCode: 'PORTAL-01',
      minConfidence: 0.7,
      mqtt: fakeMqtt(false),
      fallback,
    });

    expect(await publisher.publish(salida)).toBe(true);
    expect(fallback.postPortalEvent).toHaveBeenCalledOnce();
  });

  it('un fallo del broker no propaga la excepción al bucle de lectura', async () => {
    const errors: Error[] = [];
    const publisher = new PortalPublisher({
      locationCode: 'LIM-01',
      deviceCode: 'PORTAL-01',
      minConfidence: 0.7,
      mqtt: {
        connected: true,
        async publishAsync() {
          throw new Error('broker caído');
        },
      },
      onError: (e) => errors.push(e),
    });

    await expect(publisher.publish(salida)).resolves.toBe(false);
    expect(errors.map((e) => e.message)).toEqual(['broker caído']);
    expect(publisher.snapshot()['portal.failed']).toBe(1);
  });
});
