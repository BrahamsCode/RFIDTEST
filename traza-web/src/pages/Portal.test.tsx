import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { Portal } from './Portal';
import type { Paginated, PortalEvent, PortalStats } from '../lib/api';

/**
 * Lo que se prueba es la tarea 6.4: que marcar un falso positivo cueste un
 * toque, y que el indicador avise cuando el portal ya no es de fiar.
 */

const markFalsePositive = vi.fn(async (_id: number) => {});
let currentEvents: PortalEvent[] = [];
let currentStats: PortalStats | null = null;

const listEvents = vi.fn(
  async (): Promise<Paginated<PortalEvent>> => ({
    data: currentEvents,
    meta: { total: currentEvents.length, per_page: 25, current_page: 1, last_page: 1 },
  }),
);
const fetchStats = vi.fn(async (): Promise<PortalStats> => currentStats!);

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  portal: {
    list: () => listEvents(),
    stats: () => fetchStats(),
    markFalsePositive: (id: number) => markFalsePositive(id),
  },
}));

vi.mock('../hooks/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Vendedora', email: 'v@vivatech-peru.com', organization_id: 1, default_location_id: 7 },
    isLoading: false,
    logout: vi.fn(),
  }),
}));

function event(overrides: Partial<PortalEvent> = {}): PortalEvent {
  return {
    id: 501,
    location_id: 7,
    device_id: 3,
    tag_id: 88,
    epc: '3035D919080C0E403B9ACA2A',
    direction: 'salida',
    confidence: 0.93,
    was_sold: false,
    alarm_raised: true,
    occurred_at: '2026-08-13T18:22:00Z',
    ...overrides,
  };
}

function stats(overrides: Partial<PortalStats> = {}): PortalStats {
  return {
    location_id: 7,
    days: 30,
    alarms: 40,
    dismissed: 4,
    rate: 10,
    threshold: 20,
    needs_recalibration: false,
    ...overrides,
  };
}

async function renderWith(events: PortalEvent[], portalStats: PortalStats): Promise<void> {
  currentEvents = events;
  currentStats = portalStats;

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0, refetchInterval: false } },
  });

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  );

  render(<Portal />, { wrapper });

  // La pantalla arranca cargando; se espera a que las dos consultas
  // hayan respondido antes de mirar nada.
  await screen.findByText('Alarmas');
  await waitFor(() => expect(screen.queryByText('Cargando…')).toBeNull());
}

describe('Portal', () => {
  beforeEach(() => {
    markFalsePositive.mockClear();
    listEvents.mockClear();
    fetchStats.mockClear();
  });

  it('lista las alarmas con su confianza', async () => {
    await renderWith([event()], stats());

    expect(screen.getByText('3035D919080C0E403B9ACA2A')).toBeTruthy();
    expect(screen.getByText(/confianza 93 %/)).toBeTruthy();
  });

  it('marca el falso positivo con un solo toque', async () => {
    await renderWith([event()], stats());

    await userEvent.click(screen.getByRole('button', { name: 'Falso positivo' }));

    // Un toque: sin diálogo de confirmación ni nota obligatoria. Si costara
    // más, nadie lo registraría y el indicador quedaría vacío.
    await waitFor(() => expect(markFalsePositive).toHaveBeenCalledWith(501));
  });

  it('avisa cuando hay que recalibrar el portal', async () => {
    await renderWith([event()], stats({ alarms: 40, dismissed: 14, rate: 35, needs_recalibration: true }));

    const avisos = screen.getAllByRole('status');
    expect(avisos.some((a) => a.textContent?.includes('deja de hacer caso'))).toBe(true);
    expect(screen.getByText('35 %')).toBeTruthy();
  });

  it('no avisa cuando la tasa está por debajo del umbral', async () => {
    await renderWith([event()], stats());

    const avisos = screen.queryAllByRole('status');
    expect(avisos.some((a) => a.textContent?.includes('deja de hacer caso'))).toBe(false);
  });

  it('señala los EPC ajenos, que no son de la tienda', async () => {
    await renderWith([event({ tag_id: null, epc: 'E28011000000000000ABCD' })], stats());

    expect(screen.getByText(/EPC ajeno/)).toBeTruthy();
  });

  it('sin alarmas lo dice en vez de dejar la tarjeta vacía', async () => {
    await renderWith([], stats({ alarms: 0, dismissed: 0, rate: 0 }));

    expect(screen.getByText('No hay alarmas registradas.')).toBeTruthy();
  });
});
