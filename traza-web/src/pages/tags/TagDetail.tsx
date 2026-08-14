import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { tags, type Movement } from '../../lib/api';
import { formatEpc } from '../../lib/format';
import { TagStateBadge } from '../../components/TagStateBadge';
import { RssiChart } from '../../components/RssiChart';
import { Card, EmptyState, ErrorState, PageShell, Spinner } from '../../components/ui';

const MOVEMENT_LABELS: Record<string, string> = {
  tarado: 'Tarado',
  recepcion: 'Recepción',
  venta: 'Venta',
  devolucion_cliente: 'Devolución de cliente',
  devolucion_prov: 'Devolución a proveedor',
  transferencia_out: 'Transferencia (salida)',
  transferencia_in: 'Transferencia (entrada)',
  ajuste_positivo: 'Ajuste positivo',
  ajuste_negativo: 'Ajuste negativo',
  merma: 'Merma',
  dano: 'Daño',
  cambio_zona: 'Cambio de zona',
  reetiquetado: 'Re-etiquetado',
  anulacion: 'Anulación',
};

/** Responde "¿qué pasó con esta prenda?". Es la herramienta de investigación de merma. */
export function TagDetail() {
  const { epc = '' } = useParams();

  const { data: tag, isPending, isError } = useQuery({
    queryKey: ['tag', epc],
    queryFn: () => tags.get(epc),
  });

  const { data: history } = useQuery({
    queryKey: ['tag', epc, 'history'],
    queryFn: () => tags.history(epc),
    enabled: !isError,
  });

  if (isPending) return <Spinner />;
  if (isError || !tag) return <ErrorState>No se encontró la prenda {epc}.</ErrorState>;

  return (
    <PageShell
      title={formatEpc(tag.epc)}
      subtitle={`Esquema ${tag.epc_scheme}${tag.tid ? ` · TID ${tag.tid}` : ''}`}
      actions={<TagStateBadge state={tag.state} />}
    >
      <div className="space-y-4">
        <Card>
          <dl className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
            <Field label="Ubicación" value={tag.current_location_id ?? '—'} />
            <Field label="Zona" value={tag.current_zone_id ?? '—'} />
            <Field label="Ciclos sin ver" value={tag.missed_cycles} />
            <Field label="Vista por última vez" value={formatDate(tag.last_seen_at)} />
            <Field label="Tarada" value={formatDate(tag.commissioned_at)} />
            <Field label="Primera detección" value={formatDate(tag.first_seen_at)} />
            <Field label="Vendida" value={formatDate(tag.sold_at)} />
            <Field
              label="Sustituye a"
              value={tag.replaces_tag_id ? `Tag #${tag.replaces_tag_id}` : '—'}
            />
          </dl>
        </Card>

        {tag.sale && (
          <Card title="Venta">
            <dl className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
              <Field label="Comprobante" value={tag.sale.sale_code} />
              <Field label="Referencia externa" value={tag.sale.external_ref ?? '—'} />
              <Field label="Precio" value={`S/ ${tag.sale.unit_price}`} />
              <Field label="Fecha" value={formatDate(tag.sale.sold_at)} />
            </dl>
          </Card>
        )}

        <Card title="Señal de las últimas 72 h">
          <RssiChart epc={epc} />
        </Card>

        <Card title="Historial">
          {history === undefined ? (
            <Spinner />
          ) : history.data.length === 0 ? (
            <EmptyState>Esta prenda no tiene movimientos registrados.</EmptyState>
          ) : (
            <ol className="divide-y divide-slate-100">
              {history.data.map((movement) => (
                <HistoryRow key={movement.id} movement={movement} />
              ))}
            </ol>
          )}
        </Card>
      </div>
    </PageShell>
  );
}

function HistoryRow({ movement }: { movement: Movement }) {
  return (
    <li className="grid grid-cols-[10rem_12rem_1fr] items-baseline gap-3 py-2 text-sm">
      <span className="num tabular-nums text-slate-500">{formatDate(movement.occurred_at)}</span>
      <span className="font-medium text-slate-800">
        {MOVEMENT_LABELS[movement.type] ?? movement.type}
      </span>
      <span className="text-slate-600">
        {movement.reason ?? '—'}
        {movement.state_before && movement.state_after && (
          <span className="ml-2 text-slate-400">
            {movement.state_before} → {movement.state_after}
          </span>
        )}
      </span>
    </li>
  );
}

function Field({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className="num font-medium tabular-nums text-slate-800">{value}</dd>
    </div>
  );
}

function formatDate(iso: string | null): string {
  if (iso === null) return '—';

  return new Date(iso).toLocaleString('es-PE', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
  });
}
