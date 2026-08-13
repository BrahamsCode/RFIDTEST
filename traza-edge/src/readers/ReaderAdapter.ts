import type { RawTagRead, ReadProfile } from '../types/TagRead.js';

export interface ReaderAdapter {
  readonly id: string;
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  applyProfile(profile: ReadProfile): Promise<void>;
  startInventory(): Promise<void>;
  stopInventory(): Promise<void>;
  on(event: 'read', handler: (read: RawTagRead) => void): this;
  on(event: 'error', handler: (err: Error) => void): this;
  on(event: 'disconnected', handler: () => void): this;
}

export const PROFILES: Record<string, ReadProfile> = {
  // Inventario con handheld: S2 evita releer el mismo tag en la misma pasada.
  INVENTARIO: {
    session: 2,
    target: 'A',
    initialQ: 7,
    txPowerDbm: 30,
    dedupWindowMs: 2000,
    minReadCount: 1,
    readTid: false,
  },
  // Portal: S0 para que el tag vuelva a responder de inmediato al cruzar.
  PORTAL: {
    session: 0,
    target: 'A',
    initialQ: 4,
    txPowerDbm: 28,
    dedupWindowMs: 200,
    minReadCount: 1,
    rssiThreshold: -68,
    readTid: false,
  },
  // Tarado: potencia baja deliberada para leer solo el tag que tienes delante.
  // S0 por la tabla de `docs/09` §4: la persistencia de S1 callaría al tag
  // tras la primera respuesta y nunca se llegaría a las 2 lecturas mínimas.
  TARADO: {
    session: 0,
    target: 'A',
    initialQ: 2,
    txPowerDbm: 15,
    dedupWindowMs: 500,
    minReadCount: 2,
    rssiThreshold: -50,
    readTid: true,
  },
  RECEPCION: {
    session: 2,
    target: 'A',
    initialQ: 8,
    txPowerDbm: 30,
    dedupWindowMs: 1000,
    minReadCount: 1,
    readTid: true,
  },
};
