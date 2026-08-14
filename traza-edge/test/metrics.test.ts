import { describe, expect, it, vi } from 'vitest';
import { Histogram, RollingCounter, renderPrometheus } from '../src/health/metrics.js';
import { Pipeline } from '../src/pipeline/Pipeline.js';
import { EpcMaskStage } from '../src/pipeline/stages/EpcMaskStage.js';
import { PortalPublisher } from '../src/transport/PortalPublisher.js';
import { PROFILES } from '../src/readers/ReaderAdapter.js';
import type {
  AntennaConfig,
  PipelineContext,
  ProcessedTagRead,
  RawTagRead,
} from '../src/types/TagRead.js';

/** Tarea 2.8: las métricas de `docs/07` §9. */

const MASK = '3035D9';

function ctx(): PipelineContext {
  return {
    readerId: 'FX9600-01',
    deviceKind: 'handheld',
    profile: PROFILES['INVENTARIO']!,
    antennaConfig: new Map<number, AntennaConfig>(),
  };
}

function read(epc: string): RawTagRead {
  const now = Date.now();
  return { epc, antennaPort: 1, rssi: -50, firstSeen: now, lastSeen: now, readCount: 1, readerId: 'FX9600-01' };
}

function sources(overrides: Partial<Parameters<typeof renderPrometheus>[0]> = {}) {
  const pipeline = new Pipeline([new EpcMaskStage([MASK], 'FFFF', false)]);
  pipeline.run(read(`${MASK}000000000000000001`), ctx());
  pipeline.run(read('E28011000000000000ABCD'), ctx());

  return {
    readerId: 'FX9600-01',
    pipeline,
    buffer: { depth: () => 1234 } as never,
    readerConnected: () => true,
    ...overrides,
  };
}

describe('renderPrometheus', () => {
  it('expone las métricas que enumera docs/07 §9', () => {
    const portal = new PortalPublisher({
      locationCode: 'LIM-01',
      deviceCode: 'PORTAL-01',
      minConfidence: 0.7,
    });

    const output = renderPrometheus(
      sources({
        portal,
        flushDuration: new Histogram([0.1, 1, 10]),
        readsLastMinute: () => 42,
      }),
    );

    for (const metric of [
      'traza_edge_reads_total{reader="FX9600-01",stage="passed"}',
      'traza_edge_reads_dropped_total{reader="FX9600-01",stage="epc_mask"}',
      'traza_edge_buffer_depth',
      'traza_edge_flush_duration_seconds_bucket',
      'traza_edge_reader_connected{reader="FX9600-01"}',
      'traza_edge_portal_events_total{direction="salida"}',
    ]) {
      expect(output, `falta ${metric}`).toContain(metric);
    }
  });

  it('cada métrica lleva su HELP y su TYPE', () => {
    // Sin TYPE, Prometheus la ingiere igual pero Grafana no sabe si es
    // contador o medidor y `rate()` sobre un gauge da números sin sentido.
    const output = renderPrometheus(sources({ readsLastMinute: () => 0 }));
    const names = new Set(
      output
        .split('\n')
        .filter((l) => l && !l.startsWith('#'))
        .map((l) => l.split(/[{ ]/)[0]!.replace(/_(bucket|sum|count)$/, '')),
    );

    for (const name of names) {
      expect(output, `${name} sin HELP`).toContain(`# HELP ${name} `);
      expect(output, `${name} sin TYPE`).toContain(`# TYPE ${name} `);
    }
  });

  it('el formato es el de exposición de Prometheus', () => {
    const output = renderPrometheus(sources({ readsLastMinute: () => 7 }));

    for (const line of output.split('\n').filter((l) => l && !l.startsWith('#'))) {
      expect(line, `línea inválida: ${line}`).toMatch(
        /^[a-zA-Z_:][a-zA-Z0-9_:]*(\{[^}]*\})? -?[0-9.]+(e[+-]?\d+)?$/,
      );
    }

    expect(output.endsWith('\n')).toBe(true);
  });

  it('sin portal no inventa métricas de portal', () => {
    // Un handheld no tiene arco: exponer la serie a cero haría creer que hay
    // un portal instalado y en silencio.
    expect(renderPrometheus(sources())).not.toContain('portal');
  });
});

describe('Histogram', () => {
  it('los buckets son acumulativos y el último es +Inf', () => {
    const h = new Histogram([0.1, 1, 10]);
    [0.05, 0.5, 5, 50].forEach((v) => h.observe(v));

    const lines = h.render('m');

    expect(lines).toContain('m_bucket{le="0.1"} 1');
    expect(lines).toContain('m_bucket{le="1"} 2');
    expect(lines).toContain('m_bucket{le="10"} 3');
    expect(lines).toContain('m_bucket{le="+Inf"} 4');
    expect(lines).toContain('m_count 4');
    expect(lines.find((l) => l.startsWith('m_sum'))).toBe('m_sum 55.550000');
  });

  it('un histograma vacío se expone en cero, no ausente', () => {
    // Una serie que aparece y desaparece rompe las gráficas y las alertas.
    const lines = new Histogram([1]).render('m');

    expect(lines).toContain('m_count 0');
    expect(lines).toContain('m_bucket{le="+Inf"} 0');
  });
});

describe('RollingCounter', () => {
  it('cuenta solo lo del último minuto', () => {
    /*
     * El latido de `docs/07` §9 pide `reads_last_min`. Antes se enviaba el
     * acumulado del pipeline desde el arranque: un lector muerto seguiría
     * reportando millones de lecturas y nadie vería que dejó de leer.
     */
    let now = 1_700_000_000_000;
    const counter = new RollingCounter(60_000, 12, () => now);

    counter.add(10);
    expect(counter.value()).toBe(10);

    now += 30_000;
    counter.add(5);
    expect(counter.value()).toBe(15);

    now += 40_000; // el primer lote ya salió de la ventana
    expect(counter.value()).toBe(5);

    now += 40_000;
    expect(counter.value()).toBe(0);
  });

  it('no acumula sin fin al reutilizar una ranura', () => {
    let now = 1_700_000_000_000;
    const counter = new RollingCounter(60_000, 12, () => now);

    for (let i = 0; i < 100; i++) {
      counter.add(1);
      now += 60_000; // una vuelta entera por iteración
    }

    expect(counter.value()).toBeLessThanOrEqual(1);
  });
});

describe('Flusher: duración', () => {
  it('cronometra también el intento que falla', async () => {
    // Un vaciado que tarda 30 s en dar timeout es el síntoma que interesa
    // ver; medir solo los éxitos lo escondería justo cuando importa.
    const { Flusher } = await import('../src/buffer/Flusher.js');
    const observed: number[] = [];

    const buffer = {
      take: vi.fn(() => [{ id: 1, payload: {} as ProcessedTagRead }]),
      ack: vi.fn(),
    };
    const api = {
      postReads: vi.fn(async () => {
        throw new Error('sin red');
      }),
    };

    const flusher = new Flusher(buffer as never, api as never, 500, {
      observe: (s) => observed.push(s),
    });

    await expect(flusher.flushOnce()).rejects.toThrow('sin red');
    expect(observed).toHaveLength(1);
    expect(buffer.ack).not.toHaveBeenCalled();
  });
});
