import { createServer, type Server } from 'node:http';
import type { Pipeline } from '../pipeline/Pipeline.js';
import type { SqliteBuffer } from '../buffer/SqliteBuffer.js';

/**
 * Histograma acumulativo al estilo de Prometheus: cada bucket cuenta las
 * observaciones **menores o iguales** a su cota, y el último es `+Inf`.
 *
 * Se implementa a mano en vez de traer `prom-client`: son treinta líneas, y
 * en el borde cada dependencia es una más que mantener actualizada en un
 * mini-PC al que se llega por VPN.
 */
export class Histogram {
  private readonly counts: number[];
  private sum = 0;
  private total = 0;

  constructor(private readonly buckets: readonly number[]) {
    this.counts = new Array(buckets.length).fill(0);
  }

  observe(value: number): void {
    this.sum += value;
    this.total++;

    for (let i = 0; i < this.buckets.length; i++) {
      if (value <= this.buckets[i]!) this.counts[i]!++;
    }
  }

  /** @returns líneas `_bucket`, `_sum` y `_count` ya formateadas. */
  render(name: string): string[] {
    const lines: string[] = [];
    let cumulative = 0;

    for (let i = 0; i < this.buckets.length; i++) {
      cumulative = Math.max(cumulative, this.counts[i]!);
      lines.push(`${name}_bucket{le="${this.buckets[i]}"} ${cumulative}`);
    }

    lines.push(`${name}_bucket{le="+Inf"} ${this.total}`);
    lines.push(`${name}_sum ${this.sum.toFixed(6)}`);
    lines.push(`${name}_count ${this.total}`);

    return lines;
  }
}

/**
 * Contador de lecturas por ventana móvil de un minuto.
 *
 * El latido de `docs/07` §9 pide `reads_last_min`, y enviar el acumulado
 * desde el arranque —que es lo que hacía— convierte el campo en una cifra
 * que solo sube: un lector muerto seguiría reportando 4 millones de lecturas
 * y nadie notaría que dejó de leer.
 */
export class RollingCounter {
  private readonly slots: number[];
  private readonly stamps: number[];

  constructor(
    private readonly windowMs = 60_000,
    private readonly resolution = 12,
    private readonly clock: () => number = Date.now,
  ) {
    this.slots = new Array(resolution).fill(0);
    this.stamps = new Array(resolution).fill(0);
  }

  add(n = 1): void {
    const now = this.clock();
    const slot = this.slotFor(now);

    if (now - this.stamps[slot]! >= this.windowMs) {
      this.slots[slot] = 0;
    }

    this.slots[slot]! += n;
    this.stamps[slot] = now;
  }

  value(): number {
    const now = this.clock();
    let total = 0;

    for (let i = 0; i < this.resolution; i++) {
      if (now - this.stamps[i]! < this.windowMs) total += this.slots[i]!;
    }

    return total;
  }

  private slotFor(now: number): number {
    return Math.floor(now / (this.windowMs / this.resolution)) % this.resolution;
  }
}

export interface MetricsSources {
  readerId: string;
  pipeline: Pipeline;
  buffer: SqliteBuffer;
  readerConnected: () => boolean;
  portal?: { snapshot(): Record<string, number> };
  flushDuration?: Histogram;
  readsLastMinute?: () => number;
}

export function renderPrometheus(src: MetricsSources): string {
  const snap = src.pipeline.snapshot();
  const reader = JSON.stringify(src.readerId);
  const lines: string[] = [];

  lines.push('# HELP traza_edge_reads_total Lecturas que superaron el pipeline.');
  lines.push('# TYPE traza_edge_reads_total counter');
  lines.push(`traza_edge_reads_total{reader=${reader},stage="passed"} ${snap['passed'] ?? 0}`);

  lines.push('# HELP traza_edge_reads_dropped_total Lecturas descartadas por etapa.');
  lines.push('# TYPE traza_edge_reads_dropped_total counter');
  for (const [key, value] of Object.entries(snap)) {
    if (!key.startsWith('dropped.')) continue;
    const stage = JSON.stringify(key.slice('dropped.'.length));
    lines.push(`traza_edge_reads_dropped_total{reader=${reader},stage=${stage}} ${value}`);
  }

  if (src.readsLastMinute) {
    lines.push('# HELP traza_edge_reads_last_minute Lecturas aceptadas en los últimos 60 s.');
    lines.push('# TYPE traza_edge_reads_last_minute gauge');
    lines.push(`traza_edge_reads_last_minute{reader=${reader}} ${src.readsLastMinute()}`);
  }

  lines.push('# HELP traza_edge_buffer_depth Lecturas pendientes de envío.');
  lines.push('# TYPE traza_edge_buffer_depth gauge');
  lines.push(`traza_edge_buffer_depth ${src.buffer.depth()}`);

  if (src.flushDuration) {
    lines.push('# HELP traza_edge_flush_duration_seconds Duración de cada vaciado del buffer.');
    lines.push('# TYPE traza_edge_flush_duration_seconds histogram');
    lines.push(...src.flushDuration.render('traza_edge_flush_duration_seconds'));
  }

  lines.push('# HELP traza_edge_reader_connected Estado de conexión del lector.');
  lines.push('# TYPE traza_edge_reader_connected gauge');
  lines.push(`traza_edge_reader_connected{reader=${reader}} ${src.readerConnected() ? 1 : 0}`);

  if (src.portal) {
    const portal = src.portal.snapshot();

    lines.push('# HELP traza_edge_portal_events_total Tránsitos de portal por dirección.');
    lines.push('# TYPE traza_edge_portal_events_total counter');
    for (const direction of ['salida', 'entrada', 'indeterminado']) {
      lines.push(
        `traza_edge_portal_events_total{direction="${direction}"} ` +
          `${portal[`portal.direction.${direction}`] ?? 0}`,
      );
    }

    lines.push('# HELP traza_edge_portal_publish_total Publicaciones del camino rápido por resultado.');
    lines.push('# TYPE traza_edge_portal_publish_total counter');
    // `suppressed` alto frente a `published` significa que el arco ve pasar
    // gente pero no se atreve a clasificar: hay que revisar antenas.
    for (const result of ['published', 'suppressed', 'failed']) {
      lines.push(
        `traza_edge_portal_publish_total{result="${result}"} ${portal[`portal.${result}`] ?? 0}`,
      );
    }
  }

  return lines.join('\n') + '\n';
}

export function startMetricsServer(port: number, src: MetricsSources): Server {
  const server = createServer((req, res) => {
    if (req.url === '/health') {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(
        JSON.stringify({
          status: src.readerConnected() ? 'ok' : 'degraded',
          buffer_depth: src.buffer.depth(),
          reads_last_minute: src.readsLastMinute?.() ?? null,
          uptime_s: Math.round(process.uptime()),
        }),
      );
      return;
    }

    if (req.url === '/metrics') {
      res.writeHead(200, { 'content-type': 'text/plain; version=0.0.4' });
      res.end(renderPrometheus(src));
      return;
    }

    res.writeHead(404).end();
  });

  server.listen(port);
  return server;
}
