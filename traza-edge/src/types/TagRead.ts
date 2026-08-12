/** Lectura tal como sale del lector, sin procesar. */
export interface RawTagRead {
  epc: string;
  tid?: string;
  antennaPort: number;
  rssi: number;
  phaseAngle?: number;
  dopplerHz?: number;
  firstSeen: number;
  lastSeen: number;
  readCount: number;
  readerId: string;
}

export type Direction = 'entrada' | 'salida' | 'indeterminado';

/** Lectura tras superar el pipeline. Lista para enviar. */
export interface ProcessedTagRead extends RawTagRead {
  sessionRef: string;
  inventoryCycleId?: number;
  zoneId?: number;
  direction?: Direction;
  confidence?: number;
}

export interface ReadProfile {
  session: 0 | 1 | 2 | 3;
  target: 'A' | 'B';
  initialQ: number;
  txPowerDbm: number;
  dedupWindowMs: number;
  minReadCount: number;
  rssiThreshold?: number;
  readTid: boolean;
}

export type AntennaSide = 'interior' | 'exterior' | 'desconocido';

export interface AntennaConfig {
  port: number;
  side: AntennaSide;
  rssiThreshold?: number;
  zoneId?: number;
}

export interface PipelineContext {
  readerId: string;
  deviceKind: 'handheld' | 'lector_fijo';
  profile: ReadProfile;
  antennaConfig: Map<number, AntennaConfig>;
}
