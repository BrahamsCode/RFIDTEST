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

/**
 * Rastro que justifica la clasificación de dirección. Se conserva aunque no
 * haya alarma: la heurística falla en trazas ambiguas y sin la secuencia de
 * antenas no hay forma de revisar una alarma discutida.
 */
export interface TransitEvidence {
  samples: ReadonlyArray<{ t: number; side: AntennaSide; rssi: number; port: number }>;
  /** Separación entre centroides temporales, en ms. Positivo = interior primero. */
  deltaMs: number;
}

/** Lectura tras superar el pipeline. Lista para enviar. */
export interface ProcessedTagRead extends RawTagRead {
  sessionRef: string;
  inventoryCycleId?: number;
  zoneId?: number;
  direction?: Direction;
  confidence?: number;
  evidence?: TransitEvidence;
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
