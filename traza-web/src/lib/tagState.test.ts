import { describe, expect, it } from 'vitest';
import { TAG_STATES } from './domain';
import { TAG_STATE_UI, stateUi } from './tagState';
import { formatEpc, formatPercent } from './format';

describe('TAG_STATE_UI', () => {
  it('cubre los 10 estados del ENUM tag_state', () => {
    expect(Object.keys(TAG_STATE_UI).sort()).toEqual([...TAG_STATES].sort());
  });

  it('da a cada estado un indicador de forma además del color', () => {
    for (const state of TAG_STATES) {
      const ui = stateUi(state);
      expect(ui.dot).not.toBe('');
      expect(ui.label).not.toBe('');
    }
  });

  it('no repite el mismo indicador entre estados de significado opuesto', () => {
    expect(stateUi('en_stock').dot).not.toBe(stateUi('perdido').dot);
    expect(stateUi('en_stock').color).not.toBe(stateUi('perdido').color);
  });
});

describe('formatEpc', () => {
  it('agrupa el EPC de 4 en 4 para poder compararlo de un vistazo', () => {
    expect(formatEpc('3035D919080C0E403B9ACA2A')).toBe('3035 D919 080C 0E40 3B9A CA2A');
  });

  it('normaliza a mayúsculas', () => {
    expect(formatEpc('3035d9')).toBe('3035 D9');
  });
});

describe('formatPercent', () => {
  it('usa un decimal por defecto', () => {
    expect(formatPercent(81.234)).toBe('81.2 %');
  });
});
