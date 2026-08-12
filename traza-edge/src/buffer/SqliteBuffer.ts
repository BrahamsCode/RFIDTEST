import Database from 'better-sqlite3';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import type { ProcessedTagRead } from '../types/TagRead.js';

export interface BufferedItem {
  id: number;
  payload: ProcessedTagRead;
}

/** Buffer local de lecturas pendientes de envío. Ver `docs/07` §7. */
export class SqliteBuffer {
  private readonly db: Database.Database;
  private readonly insert: Database.Statement;
  private readonly insertMany: Database.Transaction<(reads: ProcessedTagRead[]) => void>;
  private droppedTotal = 0;

  constructor(
    path: string,
    private readonly maxRows = 2_000_000,
  ) {
    if (path !== ':memory:') mkdirSync(dirname(path), { recursive: true });

    this.db = new Database(path);
    this.db.pragma('journal_mode = WAL');
    this.db.pragma('synchronous = NORMAL'); // durabilidad suficiente, mucho más rápido

    this.db.exec(`
      CREATE TABLE IF NOT EXISTS pending_reads (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        payload     TEXT    NOT NULL,
        created_at  INTEGER NOT NULL,
        attempts    INTEGER NOT NULL DEFAULT 0
      );
      CREATE INDEX IF NOT EXISTS pending_reads_created ON pending_reads (created_at);
    `);

    this.insert = this.db.prepare(
      'INSERT INTO pending_reads (payload, created_at) VALUES (?, ?)',
    );

    // Una transacción para todo el lote: 100× más rápido que fila a fila.
    this.insertMany = this.db.transaction((reads: ProcessedTagRead[]) => {
      const now = Date.now();
      for (const r of reads) this.insert.run(JSON.stringify(r), now);
    });
  }

  enqueueMany(reads: ProcessedTagRead[]): void {
    if (reads.length === 0) return;
    this.insertMany(reads);
    this.enforceLimit();
  }

  take(limit: number): BufferedItem[] {
    const rows = this.db
      .prepare('SELECT id, payload FROM pending_reads ORDER BY id LIMIT ?')
      .all(limit) as { id: number; payload: string }[];

    return rows.map((r) => ({ id: r.id, payload: JSON.parse(r.payload) as ProcessedTagRead }));
  }

  ack(ids: number[]): void {
    if (ids.length === 0) return;
    const placeholders = ids.map(() => '?').join(',');
    this.db.prepare(`DELETE FROM pending_reads WHERE id IN (${placeholders})`).run(...ids);
  }

  depth(): number {
    const row = this.db.prepare('SELECT COUNT(*) AS n FROM pending_reads').get() as { n: number };
    return row.n;
  }

  dropped(): number {
    return this.droppedTotal;
  }

  close(): void {
    this.db.close();
  }

  /**
   * Al superar el límite se descartan las lecturas MÁS ANTIGUAS: en un corte
   * prolongado las recientes reflejan mejor el estado actual del inventario.
   */
  private enforceLimit(): void {
    const n = this.depth();
    if (n <= this.maxRows) return;

    const excess = n - this.maxRows;
    this.db
      .prepare(
        'DELETE FROM pending_reads WHERE id IN (SELECT id FROM pending_reads ORDER BY id LIMIT ?)',
      )
      .run(excess);

    this.droppedTotal += excess;
    console.warn(`[buffer] Límite superado: descartadas ${excess} lecturas antiguas.`);
  }
}
