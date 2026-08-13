import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { portal, type PortalEvent, type PortalStats } from '../lib/api';
import { isRealtimeEnabled } from '../lib/echo';
import { usePortalAlarms } from '../hooks/usePortalAlarms';
import { useAuth } from '../hooks/useAuth';
import {
  BigNumber,
  Button,
  Callout,
  Card,
  EmptyState,
  ErrorState,
  PageShell,
  Spinner,
} from '../components/ui';

/**
 * Portal antihurto. Ver P09 de `docs/10` y la épica 6.
 *
 * La pantalla existe para una sola cosa: que marcar un falso positivo cueste
 * un toque. Ese dato es el único que permite calibrar el portal, y si cuesta
 * más que eso nadie lo registra.
 */
export function Portal() {
  const { user } = useAuth();
  const locationId = user?.default_location_id ?? null;
  const alarms = usePortalAlarms(locationId);

  const events = useQuery({
    queryKey: ['portal-events', locationId],
    queryFn: () => portal.list({ only_alarms: true, per_page: 25 }),
    // Sin Reverb el sondeo es lo único que hay, y una alarma vieja no sirve.
    refetchInterval: isRealtimeEnabled() ? 60_000 : 5_000,
  });

  const stats = useQuery({
    queryKey: ['portal-stats', locationId],
    queryFn: () => portal.stats(locationId!),
    enabled: locationId !== null,
  });

  return (
    <PageShell
      title="Portal antihurto"
      subtitle="Nunca acusar a nadie. El sistema avisa; la persona decide."
    >
      {stats.data && <Calibration stats={stats.data} />}

      {alarms.length > 0 && (
        <Callout tone="danger">
          <strong>Alarma ahora mismo:</strong>{' '}
          {alarms[0]!.product_name ?? alarms[0]!.epc} · confianza{' '}
          {Math.round(alarms[0]!.confidence * 100)} %
        </Callout>
      )}

      <Card title="Últimas alarmas">
        {events.isPending ? (
          <Spinner />
        ) : events.isError || !events.data ? (
          <ErrorState>No se pudieron cargar los tránsitos del portal.</ErrorState>
        ) : events.data.data.length === 0 ? (
          <EmptyState>No hay alarmas registradas.</EmptyState>
        ) : (
          <ul className="space-y-2">
            {events.data.data.map((event) => (
              <EventRow key={event.id} event={event} />
            ))}
          </ul>
        )}
      </Card>
    </PageShell>
  );
}

/** Indicador de `docs/13` §2. Por encima del 20 % el portal se acaba ignorando. */
function Calibration({ stats }: { stats: PortalStats }) {
  return (
    <div className="space-y-3">
      <div className="grid gap-4 sm:grid-cols-3">
        <BigNumber
          label="Falsos positivos"
          value={`${stats.rate.toFixed(0)} %`}
          hint={`${stats.dismissed} de ${stats.alarms} en ${stats.days} días`}
          tone={stats.needs_recalibration ? 'bad' : 'good'}
        />
        <BigNumber label="Alarmas" value={String(stats.alarms)} hint={`Últimos ${stats.days} días`} />
        <BigNumber label="Descartadas" value={String(stats.dismissed)} hint="Marcadas como falsas" />
      </div>

      {stats.needs_recalibration && (
        <Callout tone="warning">
          Más del {stats.threshold} % de las alarmas resultan falsas. A este ritmo el personal
          deja de hacer caso al portal. Toca revisar la potencia, la separación de las antenas
          y el umbral de confianza antes de que se acabe desconectando.
        </Callout>
      )}
    </div>
  );
}

function EventRow({ event }: { event: PortalEvent }) {
  const queryClient = useQueryClient();

  const dismiss = useMutation({
    mutationFn: () => portal.markFalsePositive(event.id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['portal-events'] });
      void queryClient.invalidateQueries({ queryKey: ['portal-stats'] });
      void queryClient.invalidateQueries({ queryKey: ['alerts'] });
    },
  });

  const confidence = event.confidence === null ? null : Math.round(event.confidence * 100);

  return (
    <li className="flex items-start justify-between gap-4 rounded border border-slate-200 p-3">
      <div className="min-w-0">
        <p className="truncate font-mono text-sm text-slate-800">{event.epc}</p>
        <p className="mt-0.5 text-sm text-slate-500">
          {new Date(event.occurred_at).toLocaleString('es-PE')}
          {confidence !== null && ` · confianza ${confidence} %`}
          {event.tag_id === null && ' · EPC ajeno'}
        </p>
      </div>

      <div className="shrink-0">
        <Button onClick={() => dismiss.mutate()} disabled={dismiss.isPending}>
          Falso positivo
        </Button>
      </div>
    </li>
  );
}
