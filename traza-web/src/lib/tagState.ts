import type { TagState } from './domain';

export interface StateUi {
  label: string;
  color: string;
  /** El estado se codifica con color Y forma: nunca solo color (daltonismo). */
  dot: string;
  bg: string;
}

export const TAG_STATE_UI = {
  en_stock: { label: 'En stock', color: 'text-emerald-600', dot: '●', bg: 'bg-emerald-50' },
  no_visto: { label: 'No visto', color: 'text-amber-600', dot: '◐', bg: 'bg-amber-50' },
  perdido: { label: 'Perdido', color: 'text-red-600', dot: '✕', bg: 'bg-red-50' },
  vendido: { label: 'Vendido', color: 'text-slate-400', dot: '✓', bg: 'bg-slate-50' },
  en_transito: { label: 'En tránsito', color: 'text-blue-600', dot: '→', bg: 'bg-blue-50' },
  danado: { label: 'Dañado', color: 'text-slate-600', dot: '■', bg: 'bg-slate-100' },
  baja: { label: 'Baja', color: 'text-slate-600', dot: '■', bg: 'bg-slate-100' },
  creado: { label: 'Creado', color: 'text-slate-400', dot: '○', bg: 'bg-white' },
  codificado: { label: 'Codificado', color: 'text-slate-500', dot: '○', bg: 'bg-white' },
  anulado: { label: 'Anulado', color: 'text-slate-400', dot: '○', bg: 'bg-slate-50' },
} as const satisfies Record<TagState, StateUi>;

export function stateUi(state: TagState): StateUi {
  return TAG_STATE_UI[state];
}
