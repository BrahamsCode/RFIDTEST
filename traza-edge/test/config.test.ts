import { describe, expect, it } from 'vitest';
import { loadConfig } from '../src/config/index.js';

const base = {
  TRAZA_API_URL: 'http://api:8000',
  TRAZA_DEVICE_CODE: 'EDGE-DEV-01',
  TRAZA_DEVICE_TOKEN: 'dev-token',
};

describe('loadConfig', () => {
  it('aplica los valores por defecto documentados', () => {
    const env = loadConfig(base);
    expect(env.READER_MODE).toBe('simulator');
    expect(env.EPC_MASK).toBe('3035D9');
    expect(env.EDGE_FLUSH_BATCH).toBe(500);
  });

  it('rechaza una URL de API inválida', () => {
    expect(() => loadConfig({ ...base, TRAZA_API_URL: 'no-es-url' })).toThrow(/TRAZA_API_URL/);
  });

  it('rechaza un modo de lector desconocido', () => {
    expect(() => loadConfig({ ...base, READER_MODE: 'telepatia' })).toThrow(/READER_MODE/);
  });

  it('exige el token del dispositivo', () => {
    const { TRAZA_DEVICE_TOKEN: _omit, ...sinToken } = base;
    expect(() => loadConfig(sinToken)).toThrow(/TRAZA_DEVICE_TOKEN/);
  });
});
