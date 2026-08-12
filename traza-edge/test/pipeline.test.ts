import { describe, expect, it } from 'vitest';
import { Pipeline } from '../src/pipeline/Pipeline.js';
import { EpcMaskStage } from '../src/pipeline/stages/EpcMaskStage.js';
import { RssiThresholdStage } from '../src/pipeline/stages/RssiThresholdStage.js';
import { DedupeStage } from '../src/pipeline/stages/DedupeStage.js';
import { MinCountStage } from '../src/pipeline/stages/MinCountStage.js';
import { DirectionStage } from '../src/pipeline/stages/DirectionStage.js';
import { SimulatorAdapter } from '../src/readers/SimulatorAdapter.js';
import { PROFILES } from '../src/readers/ReaderAdapter.js';
import type { AntennaConfig, PipelineContext, RawTagRead } from '../src/types/TagRead.js';

const MASK = '3035D9';

function antennas(): Map<number, AntennaConfig> {
  return new Map<number, AntennaConfig>([
    [1, { port: 1, side: 'interior' }],
    [2, { port: 2, side: 'exterior' }],
    [3, { port: 3, side: 'interior' }],
    [4, { port: 4, side: 'exterior' }],
  ]);
}

function ctx(overrides: Partial<PipelineContext> = {}): PipelineContext {
  return {
    readerId: 'SIM-01',
    deviceKind: 'handheld',
    profile: PROFILES['INVENTARIO']!,
    antennaConfig: antennas(),
    ...overrides,
  };
}

function read(overrides: Partial<RawTagRead> = {}): RawTagRead {
  const now = Date.now();
  return {
    epc: `${MASK}000000000000000001`,
    antennaPort: 1,
    rssi: -50,
    firstSeen: now,
    lastSeen: now,
    readCount: 3,
    readerId: 'SIM-01',
    ...overrides,
  };
}

describe('EpcMaskStage', () => {
  it('deja pasar los EPC con la máscara de la casa', () => {
    const stage = new EpcMaskStage([MASK], 'FFFF', false);
    expect(stage.process(read())).not.toBeNull();
  });

  it('descarta los EPC ajenos', () => {
    const stage = new EpcMaskStage([MASK], 'FFFF', false);
    expect(stage.process(read({ epc: 'E28011AABBCCDDEEFF0011' }))).toBeNull();
  });

  it('descarta los EPC de laboratorio solo en producción', () => {
    const test = read({ epc: 'FFFF00000000000000000001' });
    expect(new EpcMaskStage([MASK, 'FFFF'], 'FFFF', false).process(test)).not.toBeNull();
    expect(new EpcMaskStage([MASK, 'FFFF'], 'FFFF', true).process(test)).toBeNull();
  });
});

describe('RssiThresholdStage', () => {
  it('aplica el umbral de la antena por encima del perfil', () => {
    const stage = new RssiThresholdStage();
    const config = antennas();
    config.set(1, { port: 1, side: 'interior', rssiThreshold: -55 });

    const strict = ctx({ antennaConfig: config, profile: { ...PROFILES['INVENTARIO']!, rssiThreshold: -80 } });
    expect(stage.process(read({ rssi: -60 }), strict)).toBeNull();
    expect(stage.process(read({ rssi: -50 }), strict)).not.toBeNull();
  });
});

describe('DedupeStage', () => {
  it('descarta relecturas dentro de la ventana', () => {
    const stage = new DedupeStage();
    const t0 = 1_000_000;

    expect(stage.process(read({ lastSeen: t0 }), ctx())).not.toBeNull();
    expect(stage.process(read({ lastSeen: t0 + 500 }), ctx())).toBeNull();
    expect(stage.process(read({ lastSeen: t0 + 2500 }), ctx())).not.toBeNull();
  });

  it('no crece indefinidamente: barre las entradas caducadas', () => {
    const stage = new DedupeStage();
    let t = 1_000_000;

    for (let i = 0; i < 5000; i++) {
      t += 100;
      stage.process(read({ epc: `${MASK}${String(i).padStart(18, '0')}`, lastSeen: t }), ctx());
    }

    // Tras avanzar muy por encima de la ventana, el barrido debe haber
    // liberado la mayor parte del mapa.
    t += 10 * PROFILES['INVENTARIO']!.dedupWindowMs + 120_000;
    stage.process(read({ epc: `${MASK}${'F'.repeat(18)}`, lastSeen: t }), ctx());

    expect(stage.size()).toBeLessThan(5000);
  });
});

describe('MinCountStage', () => {
  it('exige el mínimo de lecturas del perfil', () => {
    const stage = new MinCountStage();
    const tarado = ctx({ profile: PROFILES['TARADO']! });

    expect(stage.process(read({ readCount: 1 }), tarado)).toBeNull();
    expect(stage.process(read({ readCount: 2 }), tarado)).not.toBeNull();
  });
});

describe('DirectionStage', () => {
  const portalCtx = ctx({ deviceKind: 'lector_fijo', profile: PROFILES['PORTAL']! });

  it('clasifica interior→exterior como salida con confianza alta', () => {
    const stage = new DirectionStage();
    const t0 = 2_000_000;
    const epc = `${MASK}000000000000000042`;

    stage.process(read({ epc, antennaPort: 1, rssi: -45, firstSeen: t0, lastSeen: t0 }), portalCtx);
    stage.process(read({ epc, antennaPort: 1, rssi: -45, firstSeen: t0, lastSeen: t0 + 200 }), portalCtx);
    const out = stage.process(
      read({ epc, antennaPort: 2, rssi: -45, firstSeen: t0, lastSeen: t0 + 900 }),
      portalCtx,
    );

    expect(out).not.toBeNull();
    expect(out!.direction).toBe('salida');
    expect(out!.confidence).toBeGreaterThanOrEqual(0.7);
  });

  it('no emite evento cuando el tag se acerca y retrocede', () => {
    const stage = new DirectionStage();
    const t0 = 3_000_000;
    const epc = `${MASK}000000000000000043`;

    // Solo antenas interiores: nunca completa el cruce.
    stage.process(read({ epc, antennaPort: 1, firstSeen: t0, lastSeen: t0 }), portalCtx);
    stage.process(read({ epc, antennaPort: 3, firstSeen: t0, lastSeen: t0 + 200 }), portalCtx);
    const out = stage.process(
      read({ epc, antennaPort: 1, firstSeen: t0, lastSeen: t0 + 800 }),
      portalCtx,
    );

    expect(out).toBeNull();
  });

  it('deja pasar las lecturas de handheld sin clasificar', () => {
    const stage = new DirectionStage();
    const out = stage.process(read(), ctx());
    expect(out).not.toBeNull();
    expect(out!.direction).toBeUndefined();
  });
});

describe('Pipeline completo', () => {
  function build(): Pipeline {
    return new Pipeline([
      new EpcMaskStage([MASK], 'FFFF', false),
      new RssiThresholdStage(),
      new DedupeStage(),
      new MinCountStage(),
      new DirectionStage(),
    ]);
  }

  it('escenario vecino_ruidoso: ninguna lectura ajena llega al final', () => {
    const sim = new SimulatorAdapter('SIM-01', {
      epcPrefix: MASK,
      scenario: 'vecino_ruidoso',
      seed: 42,
    });
    const pipeline = build();

    const passed = sim
      .generate(4000)
      .map((r) => pipeline.run(r, ctx()))
      .filter((r) => r !== null);

    expect(passed.length).toBeGreaterThan(0);
    expect(passed.every((r) => r!.epc.startsWith(MASK))).toBe(true);
    expect(pipeline.snapshot()['dropped.epc_mask']).toBeGreaterThan(0);
  });

  it('escenario inventario_limpio: la mayoría de lecturas pasa', () => {
    const sim = new SimulatorAdapter('SIM-01', {
      epcPrefix: MASK,
      scenario: 'inventario_limpio',
      seed: 7,
    });
    const pipeline = build();

    const reads = sim.generate(2000);
    const passed = reads.map((r) => pipeline.run(r, ctx())).filter((r) => r !== null);

    expect(pipeline.snapshot()['dropped.epc_mask'] ?? 0).toBe(0);
    expect(passed.length).toBeGreaterThan(reads.length * 0.5);
  });
});

describe('SimulatorAdapter', () => {
  it('es reproducible con semilla fija', () => {
    const make = () =>
      new SimulatorAdapter('SIM-01', { epcPrefix: MASK, scenario: 'inventario_limpio', seed: 99 })
        .generate(200)
        .map((r) => `${r.epc}:${r.rssi.toFixed(4)}:${r.antennaPort}`);

    expect(make()).toEqual(make());
  });

  it('produce secuencias distintas con semillas distintas', () => {
    const gen = (seed: number) =>
      new SimulatorAdapter('SIM-01', { epcPrefix: MASK, scenario: 'inventario_limpio', seed })
        .generate(200)
        .map((r) => r.epc);

    expect(gen(1)).not.toEqual(gen(2));
  });

  it('conoce los 7 escenarios documentados', async () => {
    const { SCENARIOS } = await import('../src/readers/scenarios.js');
    expect(Object.keys(SCENARIOS)).toHaveLength(7);
  });
});
