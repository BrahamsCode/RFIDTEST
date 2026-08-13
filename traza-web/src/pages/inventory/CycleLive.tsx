import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { cycles, messageFrom, type Cycle, type ZonePerformance } from '../../lib/api';
import { formatNumber, formatPercent } from '../../lib/format';
import {
  Button, Callout, Card, EmptyState, ErrorState, PageShell, ProgressBar, Spinner,
} from '../../components/ui';
import { useState } from 'react';
import { useCycleProgress } from '../../hooks/useCycleProgress';
import { isRealtimeEnabled } from '../../lib/echo';

/** Por debajo de esto, una zona casi seguro que no se barrió. */
const LAGGARD_THRESHOLD = 60;

export function CycleLive() {
  const { id } = useParams();
  const cycleId = Number(id);

  // Los datos llegan por WebSocket. El sondeo de respaldo es lento a
  // propósito, y solo entra si Reverb no está configurado o se cae la
  // conexión: sin él la pantalla habría que recargarla a mano.
  const progress = useCycleProgress(cycleId);

  const { data: cycle, isPending, isError } = useQuery({
    queryKey: ['cycle', cycleId],
    queryFn: () => cycles.get(cycleId),
    refetchInterval: (query) => {
      if (!query.state.data || isFinished(query.state.data)) return false;

      return isRealtimeEnabled() ? 60_000 : 5_000;
    },
  });

  if (isPending) return <Spinner />;
  if (isError || !cycle) return <ErrorState>No se pudo cargar el ciclo.</ErrorState>;

  const expected = progress?.expected ?? cycle.expected_count ?? 0;
  const scanned = progress?.scanned ?? cycle.scanned_count;
  const pct = expected > 0 ? (scanned / expected) * 100 : 0;

  return (
    <PageShell
      title={`Ciclo ${cycle.code}`}
      subtitle={
        <>
          {cycle.started_at && <>Iniciado {formatTime(cycle.started_at)} · </>}
          <span className="capitalize">{cycle.status.replace('_', ' ')}</span>
        </>
      }
      actions={<CycleActions cycle={cycle} />}
    >
      <div className="space-y-4">
        <Card>
          <div className="flex items-baseline justify-between">
            <p className="num text-2xl font-semibold tabular-nums">
              {formatNumber(scanned)}{' '}
              <span className="text-base font-normal text-slate-500">
                de {formatNumber(expected)} esperados
              </span>
            </p>
            <p className="num text-2xl font-semibold tabular-nums">{formatPercent(pct)}</p>
          </div>

          <div className="mt-3">
            <ProgressBar pct={pct} tone={pct >= 95 ? 'good' : pct >= 60 ? 'neutral' : 'warning'} />
          </div>

          <dl className="mt-4 flex gap-8 text-sm">
            <Stat label="Contados" value={formatNumber(cycle.found_count ?? scanned)} />
            <Stat label="Esperados" value={formatNumber(expected)} />
            <Stat label="Inesperados" value={formatNumber(cycle.unexpected_count ?? 0)} />
          </dl>
        </Card>

        <ZoneBreakdown cycleId={cycleId} />
      </div>
    </PageShell>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className="num font-medium tabular-nums">{value}</dd>
    </div>
  );
}

function ZoneBreakdown({ cycleId }: { cycleId: number }) {
  const { data: zones, isPending } = useQuery({
    queryKey: ['cycle', cycleId, 'zones'],
    queryFn: () => cycles.zonePerformance(cycleId),
    refetchInterval: 5_000,
  });

  if (isPending) return <Card title="Avance por zona"><Spinner /></Card>;

  if (!zones || zones.length === 0) {
    return (
      <Card title="Avance por zona">
        <EmptyState>Este ciclo no tiene prendas asignadas a ninguna zona.</EmptyState>
      </Card>
    );
  }

  return (
    <>
      <Card title="Avance por zona">
        <ul className="space-y-3">
          {zones.map((zone) => (
            <ZoneRow key={zone.zone_id ?? zone.zone_name} zone={zone} />
          ))}
        </ul>
      </Card>

      <SlowZoneHint zones={zones} />
    </>
  );
}

function ZoneRow({ zone }: { zone: ZonePerformance }) {
  const pct = zone.accuracy_pct === null ? 0 : Number(zone.accuracy_pct);
  const lagging = pct < LAGGARD_THRESHOLD;

  return (
    <li className="grid grid-cols-[10rem_1fr_4rem_7rem] items-center gap-3 text-sm">
      <span className={lagging ? 'font-medium text-amber-700' : 'text-slate-700'}>
        {zone.zone_name ?? 'Sin zona'}
      </span>
      <ProgressBar pct={pct} tone={pct >= 95 ? 'good' : lagging ? 'warning' : 'neutral'} />
      <span className="num text-right tabular-nums">{formatPercent(pct)}</span>
      <span className="num text-right tabular-nums text-slate-500">
        {formatNumber(Number(zone.found))}/{formatNumber(Number(zone.expected))}
      </span>
    </li>
  );
}

/**
 * Pequeña función con mucho valor operativo: detecta que alguien no barrió
 * una zona MIENTRAS aún puede volver, en lugar de descubrirlo en el informe
 * final, cuando ya se ha generado merma falsa.
 */
function SlowZoneHint({ zones }: { zones: ZonePerformance[] }) {
  const laggards = zones.filter(
    (z) => z.accuracy_pct !== null && Number(z.accuracy_pct) < LAGGARD_THRESHOLD,
  );

  if (laggards.length === 0) return null;

  const names = laggards.map((z) => z.zone_name ?? 'Sin zona').join(', ');

  return (
    <Callout tone="warning">
      <strong>{names}</strong> {laggards.length === 1 ? 'está' : 'están'} muy por debajo del
      resto. Suele indicar una zona que no se llegó a barrer. Vuelve a pasar por ahí antes de
      cerrar el ciclo.
    </Callout>
  );
}

function CycleActions({ cycle }: { cycle: Cycle }) {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['cycle', cycle.id] });
  };

  const pause = useMutation({
    mutationFn: () => cycles.pause(cycle.id),
    onSuccess: invalidate,
    onError: (e) => setError(messageFrom(e)),
  });

  const start = useMutation({
    mutationFn: () => cycles.start(cycle.id),
    onSuccess: invalidate,
    onError: (e) => setError(messageFrom(e)),
  });

  const close = useMutation({
    mutationFn: () => cycles.close(cycle.id),
    onSuccess: invalidate,
    // El backend puede negar el cierre si la exactitud es baja y no hay
    // justificación. Ese mensaje es útil y hay que mostrarlo tal cual.
    onError: (e) => setError(messageFrom(e)),
  });

  if (isFinished(cycle)) {
    return <span className="text-sm text-slate-500">Cerrado</span>;
  }

  return (
    <div className="flex flex-col items-end gap-2">
      <div className="flex gap-2">
        {cycle.status === 'pausado' ? (
          <Button onClick={() => start.mutate()} disabled={start.isPending}>
            Reanudar
          </Button>
        ) : (
          <Button onClick={() => pause.mutate()} disabled={pause.isPending}>
            Pausar
          </Button>
        )}
        <Button variant="primary" onClick={() => close.mutate()} disabled={close.isPending}>
          Cerrar y conciliar
        </Button>
      </div>

      {error && (
        <p className="max-w-md text-right text-sm text-red-700" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

function isFinished(cycle: Cycle): boolean {
  return cycle.status === 'cerrado' || cycle.status === 'cancelado';
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
}
