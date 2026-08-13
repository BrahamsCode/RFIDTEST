import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { CycleLive } from './CycleLive';
import type { Cycle, ZonePerformance } from '../../lib/api';

/**
 * Lo que se prueba aquí es el aviso de zona lenta: la función con más valor
 * operativo de la pantalla, porque detecta una zona sin barrer mientras el
 * operario todavía puede volver.
 */

const cycle: Cycle = {
  id: 1,
  code: 'INV-2026-08-032',
  status: 'en_curso',
  scope: 'total',
  location_id: 1,
  expected_count: 100,
  scanned_count: 81,
  found_count: 81,
  missing_count: null,
  unexpected_count: 0,
  accuracy_pct: null,
  started_at: '2026-08-12T14:02:00Z',
  closed_at: null,
};

function zone(name: string, pct: number, expected = 100): ZonePerformance {
  return {
    zone_id: name.length,
    zone_name: name,
    expected,
    found: Math.round((pct / 100) * expected),
    missing: expected - Math.round((pct / 100) * expected),
    accuracy_pct: pct.toFixed(2),
  };
}

function renderWith(zones: ZonePerformance[]): void {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });

  // Se sirve la respuesta desde la caché en vez de simular la red: lo que
  // se prueba es el renderizado, no el cliente HTTP.
  queryClient.setQueryData(['cycle', 1], cycle);
  queryClient.setQueryData(['cycle', 1, 'zones'], zones);

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/inventario/1']}>
        <Routes>
          <Route path="/inventario/:id" element={children} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  );

  render(<CycleLive />, { wrapper });
}

describe('CycleLive', () => {
  it('muestra el avance del ciclo', () => {
    renderWith([zone('Sala principal', 98.4)]);

    expect(screen.getByText(/INV-2026-08-032/)).toBeTruthy();
    expect(screen.getByText(/81\.0 %/)).toBeTruthy();
  });

  it('avisa de la zona muy por debajo del resto', () => {
    renderWith([
      zone('Sala principal', 98.4),
      zone('Probadores', 42.0),
    ]);

    const aviso = screen.getByRole('status');
    expect(aviso.textContent).toContain('Probadores');
    expect(aviso.textContent).toContain('no se llegó a barrer');
  });

  it('no avisa cuando todas las zonas van bien', () => {
    renderWith([
      zone('Sala principal', 98.4),
      zone('Trastienda', 91.0),
    ]);

    expect(screen.queryByRole('status')).toBeNull();
  });

  it('nombra todas las zonas rezagadas, no solo la primera', () => {
    renderWith([
      zone('Sala principal', 98.4),
      zone('Probadores', 42.0),
      zone('Trastienda B', 0),
    ]);

    const aviso = screen.getByRole('status');
    expect(aviso.textContent).toContain('Probadores');
    expect(aviso.textContent).toContain('Trastienda B');
  });

  it('la barra de progreso expone su valor a lectores de pantalla', () => {
    renderWith([zone('Sala principal', 98.4)]);

    const barras = screen.getAllByRole('progressbar');
    expect(barras[0].getAttribute('aria-valuenow')).toBe('81');
  });
});
