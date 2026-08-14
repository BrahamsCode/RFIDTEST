import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  catalog,
  labels,
  messageFrom,
  type CatalogProduct,
  type CreatedBatch,
  type LabelBatch,
  type Variant,
} from '../lib/api';
import {
  Button,
  Callout,
  Card,
  EmptyState,
  ErrorState,
  PageShell,
  Spinner,
} from '../components/ui';

const DIFFICULTY_LABEL: Record<number, string> = {
  1: 'fácil',
  2: 'fácil',
  3: 'media',
  4: 'difícil',
  5: 'muy difícil',
};

/**
 * Catálogo y emisión de lotes de etiquetas. Tarea 4.6.
 *
 * La pantalla existe para una operación que **consume seriales de forma
 * irreversible**: cada lote quema un rango de EPC que ya no vuelve. Por eso
 * pide confirmar la cantidad, y por eso el ZPL se descarga en vez de
 * imprimirse solo.
 */
export function Labels() {
  const [variant, setVariant] = useState<{ variant: Variant; product: CatalogProduct } | null>(null);

  const products = useQuery({
    queryKey: ['catalog'],
    queryFn: () => catalog.products({ per_page: 100 }),
  });

  const batches = useQuery({
    queryKey: ['label-batches'],
    queryFn: () => labels.batches({ per_page: 25 }),
  });

  return (
    <PageShell title="Etiquetas" subtitle="Catálogo y emisión de lotes RFID">
      {variant && (
        <BatchForm
          variant={variant.variant}
          product={variant.product}
          onClose={() => setVariant(null)}
        />
      )}

      <Card title="Catálogo">
        {products.isPending ? (
          <Spinner />
        ) : products.isError || !products.data ? (
          <ErrorState>No se pudo cargar el catálogo.</ErrorState>
        ) : products.data.data.length === 0 ? (
          <EmptyState>No hay productos en el catálogo.</EmptyState>
        ) : (
          <div className="space-y-4">
            {products.data.data.map((product) => (
              <ProductRow
                key={product.id}
                product={product}
                onEmit={(v) => setVariant({ variant: v, product })}
              />
            ))}
          </div>
        )}
      </Card>

      <Card title="Lotes emitidos" className="mt-4">
        {batches.isPending ? (
          <Spinner />
        ) : batches.isError || !batches.data ? (
          <ErrorState>No se pudieron cargar los lotes.</ErrorState>
        ) : batches.data.data.length === 0 ? (
          <EmptyState>Todavía no se ha emitido ningún lote.</EmptyState>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-slate-500">
                <th className="py-2 font-medium">Lote</th>
                <th className="py-2 font-medium">SKU</th>
                <th className="py-2 font-medium">Cantidad</th>
                <th className="py-2 font-medium">Seriales</th>
                <th className="py-2 font-medium">Fallidas</th>
                <th className="py-2" />
              </tr>
            </thead>
            <tbody>
              {batches.data.data.map((batch) => (
                <BatchRow key={batch.id} batch={batch} />
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </PageShell>
  );
}

function ProductRow({
  product,
  onEmit,
}: {
  product: CatalogProduct;
  onEmit: (variant: Variant) => void;
}) {
  return (
    <div className="rounded border border-slate-200 p-3">
      <div className="flex items-baseline gap-2">
        <span className="font-medium text-slate-800">{product.name}</span>
        <span className="font-mono text-xs text-slate-500">{product.code}</span>
        {product.rfid_difficulty !== null && product.rfid_difficulty >= 4 && (
          // La dificultad RFID no es un adorno: es lo que explica por qué una
          // prenda concreta no aparece en el inventario.
          <span className="rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">
            lectura {DIFFICULTY_LABEL[product.rfid_difficulty]}
          </span>
        )}
      </div>

      <table className="mt-2 w-full text-sm">
        <tbody>
          {product.variants.map((variant) => (
            <tr key={variant.id} className="border-t border-slate-100">
              <td className="py-1 font-mono text-xs">{variant.sku}</td>
              <td className="py-1 text-slate-600">
                {[variant.size, variant.color].filter(Boolean).join(' · ') || '—'}
              </td>
              <td className="py-1 tabular-nums text-slate-500">
                {variant.item_reference ?? 'sin referencia'}
              </td>
              <td className="py-1 text-right">
                {variant.item_reference === null ? (
                  <span className="text-xs text-slate-500">no codificable</span>
                ) : (
                  <Button onClick={() => onEmit(variant)}>Emitir etiquetas</Button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function BatchForm({
  variant,
  product,
  onClose,
}: {
  variant: Variant;
  product: CatalogProduct;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [quantity, setQuantity] = useState(100);
  const [created, setCreated] = useState<CreatedBatch | null>(null);
  const [error, setError] = useState<string | null>(null);

  const emit = useMutation({
    mutationFn: () => labels.create(variant.id, quantity),
    onSuccess: async (batch) => {
      setCreated(batch);
      setError(null);
      void queryClient.invalidateQueries({ queryKey: ['label-batches'] });
      void queryClient.invalidateQueries({ queryKey: ['catalog'] });
      await labels.downloadZpl(batch.id, batch.code);
    },
    onError: (e) => setError(messageFrom(e, 'No se pudo emitir el lote.')),
  });

  return (
    <Card title={`Emitir etiquetas de ${variant.sku}`} className="mb-4">
      {error && <Callout tone="danger">{error}</Callout>}

      {created === null ? (
        <div className="space-y-3">
          <p className="text-sm text-slate-600">
            {product.name} · {[variant.size, variant.color].filter(Boolean).join(' · ')}
          </p>

          <label className="block text-sm">
            <span className="text-slate-600">Cantidad</span>
            <input
              type="number"
              min={1}
              max={5000}
              value={quantity}
              onChange={(e) => setQuantity(Number(e.target.value))}
              className="mt-1 block w-40 rounded border border-slate-300 px-2 py-1.5 tabular-nums"
            />
          </label>

          <Callout tone="warning">
            Emitir consume {quantity} números de serie de forma definitiva. Aunque no llegues a
            imprimir, esos EPC no se reutilizan.
          </Callout>

          <div className="flex gap-2">
            <Button onClick={() => emit.mutate()} disabled={emit.isPending || quantity < 1}>
              Emitir y descargar ZPL
            </Button>
            <Button onClick={onClose}>Cancelar</Button>
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          <Callout tone="info">
            Lote <strong>{created.code}</strong> emitido: {created.quantity} etiquetas, seriales{' '}
            {created.serial_from}–{created.serial_to}. El fichero ZPL se ha descargado.
          </Callout>

          <p className="text-sm text-slate-600">
            Manda el fichero al puerto 9100 de la impresora. Descarta físicamente toda etiqueta
            que salga marcada VOID y anota cuántas fueron al cerrar el lote: por encima del 1 %
            hay que reclamar al proveedor de inlays.
          </p>

          <Button onClick={onClose}>Cerrar</Button>
        </div>
      )}
    </Card>
  );
}

function BatchRow({ batch }: { batch: LabelBatch }) {
  const queryClient = useQueryClient();
  const [voidCount, setVoidCount] = useState(0);

  const complete = useMutation({
    mutationFn: () => labels.complete(batch.id, batch.quantity - voidCount, voidCount),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['label-batches'] }),
  });

  return (
    <tr className="border-b border-slate-100 last:border-0">
      <td className="py-2 font-mono text-xs">{batch.code}</td>
      <td className="py-2 font-mono text-xs">{batch.sku ?? '—'}</td>
      <td className="py-2 tabular-nums">{batch.quantity}</td>
      <td className="py-2 tabular-nums text-slate-500">
        {batch.serial_from}–{batch.serial_to}
      </td>
      <td className="py-2 tabular-nums">
        {batch.completed_at === null ? (
          '—'
        ) : (
          <span className={batch.void_rate_exceeded ? 'text-red-700' : 'text-slate-600'}>
            {batch.printed_void} ({batch.void_rate} %)
            {batch.void_rate_exceeded && ' ⚠ reclamar'}
          </span>
        )}
      </td>
      <td className="py-2 text-right">
        <div className="flex justify-end gap-2">
          <Button onClick={() => void labels.downloadZpl(batch.id, batch.code)}>ZPL</Button>

          {batch.completed_at === null && (
            <>
              <input
                type="number"
                min={0}
                max={batch.quantity}
                value={voidCount}
                onChange={(e) => setVoidCount(Number(e.target.value))}
                aria-label={`Etiquetas VOID del lote ${batch.code}`}
                className="w-20 rounded border border-slate-300 px-2 py-1 text-sm tabular-nums"
              />
              <Button onClick={() => complete.mutate()} disabled={complete.isPending}>
                Cerrar lote
              </Button>
            </>
          )}
        </div>
      </td>
    </tr>
  );
}
