import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { Devices } from './Devices';
import type { Device, Enrollment, Paginated } from '../lib/api';

/** Tarea 5.6, lado web: generar el QR de alta y no mentir sobre su vigencia. */

let currentDevices: Device[] = [];
const enroll = vi.fn();
const revokeEnrollment = vi.fn(async () => {});

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  devices: {
    list: async (): Promise<Paginated<Device>> => ({
      data: currentDevices,
      meta: { total: currentDevices.length, per_page: 100, current_page: 1, last_page: 1 },
    }),
    enroll: (id: number) => enroll(id),
    revokeEnrollment: () => revokeEnrollment(),
  },
}));

// `qrcode` necesita un lienzo real; en jsdom no lo hay y no es lo que se prueba.
vi.mock('qrcode', () => ({
  default: { toCanvas: vi.fn(async () => undefined) },
}));

function device(overrides: Partial<Device> = {}): Device {
  return {
    id: 3,
    code: 'HH-LIM01-02',
    name: 'Handheld 1',
    kind: 'handheld',
    status: 'inactivo',
    location_id: 1,
    firmware: null,
    last_seen_at: null,
    is_online: false,
    has_pending_enrollment: false,
    ...overrides,
  };
}

function enrollment(minutes = 15): Enrollment {
  return {
    device_code: 'HH-LIM01-02',
    expires_at: new Date(Date.now() + minutes * 60_000).toISOString(),
    expires_in_minutes: 15,
    qr_payload: {
      v: 1,
      url: 'https://traza.ejemplo.pe',
      device_code: 'HH-LIM01-02',
      enrollment_token: 'token-de-un-solo-uso',
      location_id: 1,
    },
  };
}

async function renderWith(list: Device[]): Promise<void> {
  currentDevices = list;

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0, refetchInterval: false } },
  });

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  );

  render(<Devices />, { wrapper });
  await waitFor(() => expect(screen.queryByText('Cargando…')).toBeNull());
}

describe('Devices', () => {
  beforeEach(() => {
    enroll.mockReset();
    enroll.mockResolvedValue(enrollment());
    revokeEnrollment.mockClear();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('distingue un lector vivo de uno sin latido', async () => {
    await renderWith([
      device({ id: 1, code: 'EDGE-01', kind: 'edge', is_online: true, status: 'activo' }),
      device({ id: 2, code: 'HH-02' }),
    ]);

    expect(screen.getByText(/en línea/)).toBeTruthy();
    expect(screen.getByText(/sin latido/)).toBeTruthy();
  });

  it('genera el código de alta y lo dibuja', async () => {
    await renderWith([device()]);

    await userEvent.click(screen.getByRole('button', { name: 'Dar de alta' }));
    await userEvent.click(screen.getByRole('button', { name: 'Generar código' }));

    await waitFor(() => expect(enroll).toHaveBeenCalledWith(3));
    expect(await screen.findByText(/Caduca en 1[45]:/)).toBeTruthy();
  });

  it('avisa de que el código no debe fotografiarse', async () => {
    // Vale una sola vez, pero durante 15 minutos vale de verdad.
    await renderWith([device()]);

    await userEvent.click(screen.getByRole('button', { name: 'Dar de alta' }));
    await userEvent.click(screen.getByRole('button', { name: 'Generar código' }));

    const aviso = await screen.findByText(/No fotografíes ni imprimas/);
    expect(aviso).toBeTruthy();
  });

  it('el botón anuncia que ya hay un alta pendiente', async () => {
    await renderWith([device({ has_pending_enrollment: true })]);

    expect(screen.getByRole('button', { name: 'Alta pendiente' })).toBeTruthy();
  });

  it('anular el código quita el QR de la pantalla', async () => {
    await renderWith([device()]);

    await userEvent.click(screen.getByRole('button', { name: 'Dar de alta' }));
    await userEvent.click(screen.getByRole('button', { name: 'Generar código' }));
    await screen.findByRole('button', { name: 'Anular código' });

    await userEvent.click(screen.getByRole('button', { name: 'Anular código' }));

    await waitFor(() => expect(revokeEnrollment).toHaveBeenCalledOnce());
    await waitFor(() => expect(screen.queryByText(/Caduca en/)).toBeNull());
  });

  it('un código ya caducado no se sigue mostrando como válido', async () => {
    // Si la pantalla dejara el QR puesto, alguien lo escanearía en bucle sin
    // entender por qué el equipo no se da de alta.
    enroll.mockResolvedValue(enrollment(0));
    await renderWith([device()]);

    await userEvent.click(screen.getByRole('button', { name: 'Dar de alta' }));
    await userEvent.click(screen.getByRole('button', { name: 'Generar código' }));

    await waitFor(
      () => expect(screen.queryByText(/Caduca en/)).toBeNull(),
      { timeout: 3000 },
    );
    expect(screen.getByRole('button', { name: 'Generar código' })).toBeTruthy();
  });
});
