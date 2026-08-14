import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { Labels } from './Labels';
import type { CatalogProduct, CreatedBatch, LabelBatch, Paginated, Variant } from '../lib/api';

/** Tarea 4.6, lado web: emitir un lote y no dejar que sea un accidente. */

let currentProducts: CatalogProduct[] = [];
let currentBatches: LabelBatch[] = [];

const createBatch = vi.fn(async (_v: number, _q: number): Promise<CreatedBatch> => created());
const downloadZpl = vi.fn(async () => {});
const completeBatch = vi.fn(async () => ({}) as LabelBatch);

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  catalog: {
    products: async (): Promise<Paginated<CatalogProduct>> => page(currentProducts),
  },
  labels: {
    batches: async (): Promise<Paginated<LabelBatch>> => page(currentBatches),
    create: (v: number, q: number) => createBatch(v, q),
    downloadZpl: () => downloadZpl(),
    complete: () => completeBatch(),
  },
}));

function page<T>(data: T[]): Paginated<T> {
  return { data, meta: { total: data.length, per_page: 100, current_page: 1, last_page: 1 } };
}

function variant(overrides: Partial<Variant> = {}): Variant {
  return {
    id: 7,
    sku: 'POL-OVER-M-NEG',
    size: 'M',
    color: 'Negro',
    color_hex: '#111111',
    barcode: '7751234123456',
    item_reference: '012345',
    cost_price: '32.00',
    sale_price: '89.90',
    min_stock: 2,
    is_active: true,
    serials_remaining: 274_877_906_843,
    ...overrides,
  };
}

function product(overrides: Partial<CatalogProduct> = {}): CatalogProduct {
  return {
    id: 1,
    code: 'POL-OVER',
    name: 'Polera Oversize',
    brand: 'Ejemplo',
    composition: '100% algodón',
    rfid_difficulty: 1,
    is_active: true,
    variants: [variant()],
    ...overrides,
  };
}

function created(): CreatedBatch {
  return {
    id: 12,
    code: 'LOTE-20260814-0012',
    sku: 'POL-OVER-M-NEG',
    quantity: 100,
    serial_from: 1,
    serial_to: 100,
    zpl_url: 'http://api/api/v1/label-batches/12/zpl',
  };
}

function batch(overrides: Partial<LabelBatch> = {}): LabelBatch {
  return {
    id: 12,
    code: 'LOTE-20260814-0012',
    sku: 'POL-OVER-M-NEG',
    quantity: 100,
    serial_from: 1,
    serial_to: 100,
    printed_ok: 0,
    printed_void: 0,
    void_rate: 0,
    void_rate_exceeded: false,
    created_at: '2026-08-14T10:00:00Z',
    completed_at: null,
    ...overrides,
  };
}

async function renderWith(products: CatalogProduct[], batches: LabelBatch[]): Promise<void> {
  currentProducts = products;
  currentBatches = batches;

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0, refetchInterval: false } },
  });

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  );

  render(<Labels />, { wrapper });
  await waitFor(() => expect(screen.queryAllByText('Cargando…')).toHaveLength(0));
}

describe('Labels', () => {
  beforeEach(() => {
    createBatch.mockClear();
    downloadZpl.mockClear();
    completeBatch.mockClear();
  });

  it('emitir avisa de que los seriales no se recuperan', async () => {
    // Es una operación irreversible con aspecto de botón cualquiera: si no lo
    // dice, alguien emite 5 000 etiquetas probando.
    await renderWith([product()], []);

    await userEvent.click(screen.getByRole('button', { name: 'Emitir etiquetas' }));

    expect(screen.getByText(/no se reutilizan/)).toBeTruthy();
  });

  it('emite el lote y descarga el ZPL sin pasos extra', async () => {
    await renderWith([product()], []);

    await userEvent.click(screen.getByRole('button', { name: 'Emitir etiquetas' }));
    await userEvent.click(screen.getByRole('button', { name: 'Emitir y descargar ZPL' }));

    await waitFor(() => expect(createBatch).toHaveBeenCalledWith(7, 100));
    await waitFor(() => expect(downloadZpl).toHaveBeenCalledOnce());
    expect(await screen.findByText(/LOTE-20260814-0012/)).toBeTruthy();
  });

  it('recuerda descartar las etiquetas VOID', async () => {
    // Regla de proceso de `docs/04` §5: una etiqueta VOID que se cuelga de
    // una prenda es una prenda invisible para el inventario.
    await renderWith([product()], []);

    await userEvent.click(screen.getByRole('button', { name: 'Emitir etiquetas' }));
    await userEvent.click(screen.getByRole('button', { name: 'Emitir y descargar ZPL' }));

    expect(await screen.findByText(/marcada VOID/)).toBeTruthy();
  });

  it('una variante sin referencia no se puede etiquetar', async () => {
    // Sin `item_reference` no hay EPC posible; ofrecer el botón daría un
    // error del servidor en vez de explicarlo aquí.
    await renderWith([product({ variants: [variant({ item_reference: null })] })], []);

    expect(screen.queryByRole('button', { name: 'Emitir etiquetas' })).toBeNull();
    expect(screen.getByText('no codificable')).toBeTruthy();
  });

  it('señala la prenda difícil de leer', async () => {
    await renderWith([product({ rfid_difficulty: 5 })], []);

    expect(screen.getByText(/lectura muy difícil/)).toBeTruthy();
  });

  it('avisa del lote que supera el 1 % de fallos', async () => {
    await renderWith(
      [product()],
      [batch({ printed_ok: 95, printed_void: 5, void_rate: 5, void_rate_exceeded: true, completed_at: '2026-08-14T11:00:00Z' })],
    );

    expect(screen.getByText(/5 \(5 %\).*reclamar/s)).toBeTruthy();
  });

  it('cierra el lote con el número de VOID contadas', async () => {
    await renderWith([product()], [batch()]);

    const input = screen.getByLabelText(/Etiquetas VOID del lote/);
    await userEvent.clear(input);
    await userEvent.type(input, '3');
    await userEvent.click(screen.getByRole('button', { name: 'Cerrar lote' }));

    await waitFor(() => expect(completeBatch).toHaveBeenCalledOnce());
  });

  it('un lote ya cerrado no se vuelve a cerrar', async () => {
    await renderWith([product()], [batch({ completed_at: '2026-08-14T11:00:00Z' })]);

    expect(screen.queryByRole('button', { name: 'Cerrar lote' })).toBeNull();
    expect(screen.getByRole('button', { name: 'ZPL' })).toBeTruthy();
  });
});
