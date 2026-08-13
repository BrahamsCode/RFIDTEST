import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { alerts, type Alert } from '../lib/api';
import { Button, Card, EmptyState, ErrorState, PageShell, Spinner } from '../components/ui';

const SEVERITY_STYLES: Record<number, string> = {
  1: 'bg-red-50 text-red-700 border-red-200',
  2: 'bg-orange-50 text-orange-700 border-orange-200',
  3: 'bg-amber-50 text-amber-700 border-amber-200',
  4: 'bg-slate-50 text-slate-600 border-slate-200',
  5: 'bg-slate-50 text-slate-500 border-slate-200',
};

export function Alerts() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['alerts', 'abierta'],
    queryFn: () => alerts.list({ status: 'abierta' }),
    refetchInterval: 30_000,
  });

  if (isPending) return <Spinner />;
  if (isError || !data) return <ErrorState>No se pudieron cargar las alertas.</ErrorState>;

  return (
    <PageShell title="Alertas" subtitle="Lo más grave primero">
      <Card>
        {data.data.length === 0 ? (
          <EmptyState>No hay alertas abiertas.</EmptyState>
        ) : (
          <ul className="space-y-2">
            {data.data.map((alert) => (
              <AlertRow key={alert.id} alert={alert} />
            ))}
          </ul>
        )}
      </Card>
    </PageShell>
  );
}

function AlertRow({ alert }: { alert: Alert }) {
  const queryClient = useQueryClient();
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['alerts'] });

  const acknowledge = useMutation({
    mutationFn: () => alerts.acknowledge(alert.id),
    onSuccess: invalidate,
  });

  // Marcar un falso positivo de un toque es lo que alimenta el indicador de
  // `docs/13` §2. Si cuesta, nadie lo registra.
  const dismiss = useMutation({
    mutationFn: () => alerts.resolve(alert.id, true),
    onSuccess: invalidate,
  });

  return (
    <li className="flex items-start justify-between gap-4 rounded border border-slate-200 p-3">
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <span
            className={`rounded border px-1.5 py-0.5 text-xs font-medium ${
              SEVERITY_STYLES[alert.severity] ?? SEVERITY_STYLES[5]
            }`}
          >
            S{alert.severity}
          </span>
          <span className="font-medium text-slate-800">{alert.title}</span>
        </div>
        <p className="mt-0.5 text-sm text-slate-500">
          {new Date(alert.triggered_at).toLocaleString('es-PE')}
        </p>
      </div>

      <div className="flex shrink-0 gap-2">
        <Button onClick={() => acknowledge.mutate()} disabled={acknowledge.isPending}>
          Revisar
        </Button>
        <Button onClick={() => dismiss.mutate()} disabled={dismiss.isPending}>
          Falso positivo
        </Button>
      </div>
    </li>
  );
}
