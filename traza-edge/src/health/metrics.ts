import { createServer, type Server } from 'node:http';
import type { Pipeline } from '../pipeline/Pipeline.js';
import type { SqliteBuffer } from '../buffer/SqliteBuffer.js';

export interface MetricsSources {
  readerId: string;
  pipeline: Pipeline;
  buffer: SqliteBuffer;
  readerConnected: () => boolean;
  portal?: { snapshot(): Record<string, number> };
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

  lines.push('# HELP traza_edge_buffer_depth Lecturas pendientes de envío.');
  lines.push('# TYPE traza_edge_buffer_depth gauge');
  lines.push(`traza_edge_buffer_depth ${src.buffer.depth()}`);

  lines.push('# HELP traza_edge_reader_connected Estado de conexión del lector.');
  lines.push('# TYPE traza_edge_reader_connected gauge');
  lines.push(`traza_edge_reader_connected{reader=${reader}} ${src.readerConnected() ? 1 : 0}`);

  if (src.portal) {
    const portal = src.portal.snapshot();
    lines.push('# HELP traza_edge_portal_events_total Tránsitos de portal por resultado.');
    lines.push('# TYPE traza_edge_portal_events_total counter');
    lines.push(`traza_edge_portal_events_total{result="published"} ${portal['portal.published'] ?? 0}`);
    // Un `suppressed` alto frente a `published` significa que el portal ve
    // pasar gente pero no se atreve a clasificar: hay que revisar antenas.
    lines.push(`traza_edge_portal_events_total{result="suppressed"} ${portal['portal.suppressed'] ?? 0}`);
    lines.push(`traza_edge_portal_events_total{result="failed"} ${portal['portal.failed'] ?? 0}`);
  }

  return lines.join('\n') + '\n';
}

export function startMetricsServer(port: number, src: MetricsSources): Server {
  const server = createServer((req, res) => {
    if (req.url === '/health') {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ status: 'ok', buffer_depth: src.buffer.depth() }));
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
