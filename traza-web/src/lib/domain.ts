/** Espejo de los ENUM de `sql/schema.sql` §1. Deben mantenerse sincronizados. */

export const TAG_STATES = [
  'creado',
  'codificado',
  'en_stock',
  'en_transito',
  'no_visto',
  'perdido',
  'vendido',
  'danado',
  'baja',
  'anulado',
] as const;

export type TagState = (typeof TAG_STATES)[number];

export const MOVEMENT_TYPES = [
  'tarado',
  'recepcion',
  'venta',
  'devolucion_cliente',
  'devolucion_prov',
  'transferencia_out',
  'transferencia_in',
  'ajuste_positivo',
  'ajuste_negativo',
  'merma',
  'dano',
  'cambio_zona',
  'reetiquetado',
  'anulacion',
] as const;

export type MovementType = (typeof MOVEMENT_TYPES)[number];

export const ZONE_KINDS = [
  'sala',
  'trastienda',
  'probador',
  'escaparate',
  'caja',
  'recepcion',
  'salida',
  'otro',
] as const;

export type ZoneKind = (typeof ZONE_KINDS)[number];

export type DeviceKind = 'handheld' | 'lector_fijo' | 'impresora' | 'edge';

export interface Tag {
  id: number;
  epc: string;
  tid: string | null;
  state: TagState;
  product_variant_id: number | null;
  current_location_id: number | null;
  current_zone_id: number | null;
  last_seen_at: string | null;
  missed_cycles: number;
}
