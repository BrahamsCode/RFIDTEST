import { describe, expect, it, vi } from 'vitest';
import { SqliteBuffer } from '../src/buffer/SqliteBuffer.js';
import { Flusher } from '../src/buffer/Flusher.js';
import { ApiError, type ApiClient } from '../src/transport/ApiClient.js';
import type { ProcessedTagRead } from '../src/types/TagRead.js';

function makeRead(n: number): ProcessedTagRead {
  const now = 1_700_000_000_000 + n;
  return {
    epc: `3035D9${String(n).padStart(18, '0')}`,
    antennaPort: 1,
    rssi: -55,
    firstSeen: now,
    lastSeen: now,
    readCount: 2,
    readerId: 'SIM-01',
    sessionRef: 'test',
  };
}

describe('SqliteBuffer', () => {
  it('encola, entrega y confirma sin perder lecturas', () => {
    const buffer = new SqliteBuffer(':memory:');
    buffer.enqueueMany([makeRead(1), makeRead(2), makeRead(3)]);
    expect(buffer.depth()).toBe(3);

    const batch = buffer.take(2);
    expect(batch).toHaveLength(2);
    expect(batch[0]!.payload.epc).toBe(makeRead(1).epc);

    buffer.ack(batch.map((b) => b.id));
    expect(buffer.depth()).toBe(1);
    buffer.close();
  });

  it('descarta las lecturas más antiguas al superar el límite', () => {
    const buffer = new SqliteBuffer(':memory:', 10);
    buffer.enqueueMany(Array.from({ length: 25 }, (_, i) => makeRead(i)));

    expect(buffer.depth()).toBe(10);
    expect(buffer.dropped()).toBe(15);
    // Sobreviven las más recientes.
    expect(buffer.take(1)[0]!.payload.epc).toBe(makeRead(15).epc);
    buffer.close();
  });
});

describe('Flusher', () => {
  it('confirma el lote cuando la API responde bien', async () => {
    const buffer = new SqliteBuffer(':memory:');
    buffer.enqueueMany([makeRead(1), makeRead(2)]);

    const api = { postReads: vi.fn().mockResolvedValue(undefined) } as unknown as ApiClient;
    const sent = await new Flusher(buffer, api, 500).flushOnce();

    expect(sent).toBe(2);
    expect(buffer.depth()).toBe(0);
    buffer.close();
  });

  it('escenario red_caida: no pierde lecturas mientras la API falla', async () => {
    const buffer = new SqliteBuffer(':memory:');
    buffer.enqueueMany(Array.from({ length: 300 }, (_, i) => makeRead(i)));

    const api = {
      postReads: vi.fn().mockRejectedValue(new ApiError('503', 503)),
    } as unknown as ApiClient;
    const flusher = new Flusher(buffer, api, 100);

    for (let i = 0; i < 5; i++) {
      await expect(flusher.flushOnce()).rejects.toThrow();
    }

    // Sin ack: las 300 siguen ahí, listas para reintentarse.
    expect(buffer.depth()).toBe(300);
    buffer.close();
  });
});
