import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { cycles } from '../../lib/api';
import { formatNumber, formatPercent } from '../../lib/format';
import { Card, EmptyState, ErrorState, PageShell, Spinner } from '../../components/ui';

export function CycleList() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['cycles'],
    queryFn: () => cycles.list(),
  });

  if (isPending) return <Spinner />;
  if (isError || !data) return <ErrorState>No se pudieron cargar los ciclos.</ErrorState>;

  return (
    <PageShell title="Ciclos de inventario" subtitle={`${formatNumber(data.meta.total)} en total`}>
      <Card>
        {data.data.length === 0 ? (
          <EmptyState>Todavía no hay ningún ciclo.</EmptyState>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="pb-2 font-medium">Código</th>
                <th className="pb-2 font-medium">Estado</th>
                <th className="pb-2 text-right font-medium">Esperados</th>
                <th className="pb-2 text-right font-medium">Exactitud</th>
                <th className="pb-2 text-right font-medium">Cerrado</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((cycle) => (
                <tr key={cycle.id} className="border-b border-slate-100">
                  <td className="py-2">
                    <Link
                      to={`/inventario/${cycle.id}`}
                      className="font-medium text-slate-800 underline-offset-2 hover:underline"
                    >
                      {cycle.code}
                    </Link>
                  </td>
                  <td className="py-2 capitalize text-slate-600">
                    {cycle.status.replace('_', ' ')}
                  </td>
                  <td className="num py-2 text-right tabular-nums">
                    {formatNumber(cycle.expected_count ?? 0)}
                  </td>
                  <td className="num py-2 text-right tabular-nums">
                    {cycle.accuracy_pct === null ? '—' : formatPercent(cycle.accuracy_pct)}
                  </td>
                  <td className="num py-2 text-right tabular-nums text-slate-500">
                    {cycle.closed_at
                      ? new Date(cycle.closed_at).toLocaleDateString('es-PE')
                      : '—'}
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
