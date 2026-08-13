import { useQuery } from '@tanstack/react-query';
import { stock } from '../lib/api';
import { formatNumber, formatPercent } from '../lib/format';
import { BigNumber, Card, ErrorState, PageShell, Spinner } from '../components/ui';

/**
 * Panel de tienda: cuatro números grandes legibles a 3 metros.
 * Ver `docs/13` §3.1.
 */
export function Dashboard() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['stock', 'summary'],
    queryFn: () => stock.summary(),
    refetchInterval: 60_000,
  });

  if (isPending) return <Spinner />;
  if (isError || !data) return <ErrorState>No se pudo cargar el panel.</ErrorState>;

  return (
    <PageShell title="Panel de tienda" subtitle="Estado ahora mismo">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <BigNumber
          label="Prendas en stock"
          value={formatNumber(data.units)}
          hint={`${formatNumber(data.sellable_units)} disponibles en sala`}
        />
        <BigNumber
          label="Exactitud"
          value={data.last_accuracy_pct === null ? '—' : formatPercent(data.last_accuracy_pct)}
          hint={
            data.last_cycle_closed_at
              ? `Último ciclo: ${formatDay(data.last_cycle_closed_at)}`
              : 'Sin ciclos cerrados'
          }
          tone={accuracyTone(data.last_accuracy_pct)}
        />
        <BigNumber
          label="Alertas abiertas"
          value={formatNumber(data.open_alerts)}
          tone={data.open_alerts === 0 ? 'good' : data.open_alerts > 5 ? 'bad' : 'warning'}
        />
        <BigNumber
          label="Reponer en sala"
          value={formatNumber(data.replenishment_needed)}
          hint="SKU con stock en trastienda"
          tone={data.replenishment_needed === 0 ? 'good' : 'warning'}
        />
      </div>

      <div className="mt-4">
        <AgingCard />
      </div>
    </PageShell>
  );
}

function AgingCard() {
  const { data, isPending } = useQuery({
    queryKey: ['stock', 'aging'],
    queryFn: () => stock.aging(),
  });

  if (isPending) return <Card title="Antigüedad del stock"><Spinner /></Card>;

  const total = data?.reduce((sum, bucket) => sum + bucket.units, 0) ?? 0;

  return (
    <Card title="Antigüedad del stock">
      <ul className="space-y-2">
        {(data ?? []).map((bucket) => {
          const pct = total > 0 ? (bucket.units / total) * 100 : 0;

          return (
            <li key={bucket.age_bucket} className="grid grid-cols-[5rem_1fr_5rem] items-center gap-3 text-sm">
              <span className="text-slate-600">{bucket.age_bucket} días</span>
              <div className="h-2 overflow-hidden rounded bg-slate-100">
                <div
                  className={bucket.age_bucket === '180+' ? 'h-full bg-red-500' : 'h-full bg-slate-500'}
                  style={{ width: `${pct}%` }}
                />
              </div>
              <span className="num text-right tabular-nums">{formatNumber(bucket.units)}</span>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}

function accuracyTone(pct: number | null): 'neutral' | 'good' | 'warning' | 'bad' {
  if (pct === null) return 'neutral';
  if (pct >= 97) return 'good';
  if (pct >= 90) return 'warning';
  return 'bad';
}

function formatDay(iso: string): string {
  return new Date(iso).toLocaleDateString('es-PE', { day: '2-digit', month: 'short' });
}
