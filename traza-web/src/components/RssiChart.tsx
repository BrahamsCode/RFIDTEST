import { useQuery } from '@tanstack/react-query';
import {
  CartesianGrid,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { tags } from '../lib/api';
import { Callout, EmptyState, ErrorState, Spinner } from './ui';

/**
 * RSSI por hora de una prenda. Ver `docs/08` §5.
 *
 * Parece un detalle técnico y es lo primero que mira soporte cuando alguien
 * dice «el sistema dice que está y no está». Un RSSI plano en torno a
 * −75 dBm no significa que la prenda esté donde dice: significa que se está
 * leyendo de lejos, probablemente desde el local de al lado.
 */

/** Por debajo de esto la lectura es de lejos y no prueba presencia. */
const UMBRAL_DEBIL = -70;

export function RssiChart({ epc, hours = 72 }: { epc: string; hours?: number }) {
  const { data, isPending, isError } = useQuery({
    queryKey: ['tag-detections', epc, hours],
    queryFn: () => tags.detections(epc, hours),
  });

  if (isPending) return <Spinner />;
  if (isError || !data) return <ErrorState>No se pudieron cargar las detecciones.</ErrorState>;

  if (data.data.length === 0) {
    return <EmptyState>Sin lecturas en las últimas {hours} horas.</EmptyState>;
  }

  const puntos = data.data.map((d) => ({
    ...d,
    etiqueta: new Date(d.hour).toLocaleString('es-PE', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
    }),
  }));

  return (
    <div className="space-y-3">
      {data.summary.weak_signal && (
        <Callout tone="warning">
          Señal media de {data.summary.rssi_avg} dBm. Por debajo de {UMBRAL_DEBIL} dBm la prenda
          se está leyendo de lejos: puede estar en otra zona, o incluso en el local vecino. La
          ubicación que muestra el sistema no es de fiar.
        </Callout>
      )}

      <div className="h-64 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={puntos} margin={{ top: 8, right: 12, bottom: 8, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
            <XAxis dataKey="etiqueta" tick={{ fontSize: 11 }} />
            {/* Dominio fijo: con uno automático, una variación de 2 dBm
                parecería un desplome y una caída real pasaría desapercibida. */}
            <YAxis
              domain={[-90, -30]}
              tick={{ fontSize: 11 }}
              label={{ value: 'dBm', angle: -90, position: 'insideLeft', fontSize: 11 }}
            />
            <Tooltip
              formatter={(v: number, name) => [`${v} dBm`, name === 'rssi_avg' ? 'medio' : name]}
              labelFormatter={(l) => `Hora: ${l}`}
            />
            <ReferenceLine
              y={UMBRAL_DEBIL}
              stroke="#dc2626"
              strokeDasharray="4 4"
              label={{ value: 'lectura lejana', fontSize: 10, fill: '#dc2626' }}
            />
            <Line type="monotone" dataKey="rssi_max" stroke="#94a3b8" dot={false} strokeWidth={1} />
            <Line type="monotone" dataKey="rssi_avg" stroke="#0f172a" dot={false} strokeWidth={2} />
            <Line type="monotone" dataKey="rssi_min" stroke="#94a3b8" dot={false} strokeWidth={1} />
          </LineChart>
        </ResponsiveContainer>
      </div>

      <p className="text-sm text-slate-500">
        <span className="num tabular-nums">{data.summary.total_reads}</span> lecturas en{' '}
        {hours} h · medio {data.summary.rssi_avg} dBm · mínimo {data.summary.rssi_min} dBm
      </p>
    </div>
  );
}
