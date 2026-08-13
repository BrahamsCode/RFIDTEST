import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { stock } from '../../lib/api';
import { formatCurrency, formatNumber } from '../../lib/format';
import { Card, EmptyState, ErrorState, PageShell, Spinner } from '../../components/ui';

export function StockList() {
  const [search, setSearch] = useState('');

  const { data, isPending, isError } = useQuery({
    queryKey: ['stock', 'list', search],
    queryFn: () => stock.list({ search: search || undefined, per_page: 100 }),
  });

  const { data: valuation } = useQuery({
    queryKey: ['stock', 'valuation'],
    queryFn: () => stock.valuation(),
  });

  const { data: replenishment } = useQuery({
    queryKey: ['stock', 'replenishment'],
    queryFn: () => stock.replenishment(),
  });

  return (
    <PageShell title="Stock" subtitle="Existencias por SKU y zona">
      {valuation && (
        <div className="mb-4 grid gap-4 sm:grid-cols-3">
          <Summary label="Unidades" value={formatNumber(valuation.totals.units)} />
          <Summary label="Valor a coste" value={formatCurrency(valuation.totals.cost_value)} />
          <Summary label="Valor a venta" value={formatCurrency(valuation.totals.retail_value)} />
        </div>
      )}

      {replenishment && replenishment.data.length > 0 && (
        <Card title="Reponer en sala" className="mb-4">
          <ul className="space-y-1 text-sm">
            {replenishment.data.slice(0, 10).map((row) => (
              <li key={row.product_variant_id} className="flex justify-between">
                <span className="text-slate-700">
                  {row.product_name} <span className="text-slate-400">· {row.sku}</span>
                </span>
                <span className="num tabular-nums text-slate-600">
                  {row.on_floor} en sala · {row.in_back} en trastienda
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <Card>
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Buscar por SKU o producto…"
          className="mb-3 w-full max-w-sm rounded border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:outline-none"
        />

        {isPending ? (
          <Spinner />
        ) : isError || !data ? (
          <ErrorState>No se pudo cargar el stock.</ErrorState>
        ) : data.data.length === 0 ? (
          <EmptyState>No hay existencias que coincidan.</EmptyState>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="pb-2 font-medium">SKU</th>
                <th className="pb-2 font-medium">Producto</th>
                <th className="pb-2 font-medium">Zona</th>
                <th className="pb-2 text-right font-medium">Total</th>
                <th className="pb-2 text-right font-medium">En sala</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((row) => (
                <tr key={`${row.product_variant_id}-${row.zone_id}`} className="border-b border-slate-100">
                  <td className="py-2 font-medium text-slate-800">{row.sku}</td>
                  <td className="py-2 text-slate-600">{row.product_name}</td>
                  <td className="py-2 text-slate-600">{row.zone_name ?? '—'}</td>
                  <td className="num py-2 text-right tabular-nums">{formatNumber(row.quantity)}</td>
                  <td className="num py-2 text-right tabular-nums text-slate-500">
                    {formatNumber(row.sellable_quantity)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </PageShell>
  );
}

function Summary({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded border border-slate-200 bg-white p-4">
      <p className="text-sm text-slate-500">{label}</p>
      <p className="num mt-0.5 text-2xl font-semibold tabular-nums">{value}</p>
    </div>
  );
}
