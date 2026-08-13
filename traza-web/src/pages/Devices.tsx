import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import QRCode from 'qrcode';
import { devices, messageFrom, type Device, type Enrollment } from '../lib/api';
import {
  Button,
  Callout,
  Card,
  EmptyState,
  ErrorState,
  PageShell,
  Spinner,
} from '../components/ui';

const KIND_LABEL: Record<string, string> = {
  handheld: 'Handheld',
  lector_fijo: 'Lector fijo',
  impresora: 'Impresora',
  edge: 'Borde',
};

/**
 * Dispositivos y alta por QR. Ver `docs/09` §10.
 *
 * El QR se dibuja aquí y no en el servidor porque el token no debería pasar
 * por más sitios de los imprescindibles: llega en JSON, se pinta en el lienzo
 * y no se guarda en ningún lado.
 */
export function Devices() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['devices'],
    queryFn: () => devices.list({ per_page: 100 }),
    // Un lector caído se nota por `is_online`, y eso cambia solo con el paso
    // del tiempo: sin refresco, la pantalla mentiría indefinidamente.
    refetchInterval: 30_000,
  });

  const [enrolling, setEnrolling] = useState<Device | null>(null);

  if (isPending) return <Spinner />;
  if (isError || !data) return <ErrorState>No se pudieron cargar los dispositivos.</ErrorState>;

  return (
    <PageShell title="Dispositivos" subtitle="Lectores, handhelds e impresoras de la organización">
      <Card>
        {data.data.length === 0 ? (
          <EmptyState>No hay dispositivos dados de alta.</EmptyState>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-slate-500">
                <th className="py-2 font-medium">Código</th>
                <th className="py-2 font-medium">Nombre</th>
                <th className="py-2 font-medium">Tipo</th>
                <th className="py-2 font-medium">Estado</th>
                <th className="py-2 font-medium">Último latido</th>
                <th className="py-2" />
              </tr>
            </thead>
            <tbody>
              {data.data.map((device) => (
                <DeviceRow key={device.id} device={device} onEnroll={() => setEnrolling(device)} />
              ))}
            </tbody>
          </table>
        )}
      </Card>

      {enrolling && <EnrollmentPanel device={enrolling} onClose={() => setEnrolling(null)} />}
    </PageShell>
  );
}

function DeviceRow({ device, onEnroll }: { device: Device; onEnroll: () => void }) {
  return (
    <tr className="border-b border-slate-100 last:border-0">
      <td className="py-2 font-mono">{device.code}</td>
      <td className="py-2">{device.name}</td>
      <td className="py-2 text-slate-500">{KIND_LABEL[device.kind] ?? device.kind}</td>
      <td className="py-2">
        {/* Color y forma, nunca solo color: hay operarios con daltonismo. */}
        <span className={device.is_online ? 'text-emerald-700' : 'text-slate-500'}>
          {device.is_online ? '● en línea' : '○ sin latido'}
        </span>
        {device.status !== 'activo' && (
          <span className="ml-2 text-amber-700">· {device.status}</span>
        )}
      </td>
      <td className="py-2 tabular-nums text-slate-500">
        {device.last_seen_at ? new Date(device.last_seen_at).toLocaleString('es-PE') : '—'}
      </td>
      <td className="py-2 text-right">
        <Button onClick={onEnroll}>
          {device.has_pending_enrollment ? 'Alta pendiente' : 'Dar de alta'}
        </Button>
      </td>
    </tr>
  );
}

function EnrollmentPanel({ device, onClose }: { device: Device; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [enrollment, setEnrollment] = useState<Enrollment | null>(null);
  const [error, setError] = useState<string | null>(null);

  const issue = useMutation({
    mutationFn: () => devices.enroll(device.id, window.location.origin),
    onSuccess: (data) => {
      setEnrollment(data);
      setError(null);
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
    },
    onError: (e) => setError(messageFrom(e, 'No se pudo generar el código de alta.')),
  });

  const revoke = useMutation({
    mutationFn: () => devices.revokeEnrollment(device.id),
    onSuccess: () => {
      setEnrollment(null);
      void queryClient.invalidateQueries({ queryKey: ['devices'] });
    },
  });

  return (
    <Card title={`Alta de ${device.code}`} className="mt-4">
      {error && <Callout tone="danger">{error}</Callout>}

      {enrollment === null ? (
        <div className="space-y-3">
          <p className="text-sm text-slate-600">
            Se generará un código de un solo uso que caduca en 15 minutos. Escanéalo desde la
            aplicación del handheld para que reciba su token permanente.
          </p>
          <div className="flex gap-2">
            <Button onClick={() => issue.mutate()} disabled={issue.isPending}>
              Generar código
            </Button>
            <Button onClick={onClose}>Cerrar</Button>
          </div>
        </div>
      ) : (
        <div className="space-y-4">
          <QrCanvas payload={JSON.stringify(enrollment.qr_payload)} />

          <Countdown expiresAt={enrollment.expires_at} onExpired={() => setEnrollment(null)} />

          <Callout tone="warning">
            No fotografíes ni imprimas este código. Vale una sola vez, pero durante 15 minutos
            cualquiera que lo tenga puede dar de alta un equipo con las credenciales de{' '}
            {device.code}.
          </Callout>

          <div className="flex gap-2">
            <Button onClick={() => revoke.mutate()} disabled={revoke.isPending}>
              Anular código
            </Button>
            <Button onClick={onClose}>Cerrar</Button>
          </div>
        </div>
      )}
    </Card>
  );
}

function QrCanvas({ payload }: { payload: string }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (canvas.current === null) return;

    // Corrección de errores alta: la pantalla de la tienda tiene huellas y el
    // handheld enfoca regular.
    QRCode.toCanvas(canvas.current, payload, { width: 280, errorCorrectionLevel: 'H' })
      .then(() => setFailed(false))
      .catch(() => setFailed(true));
  }, [payload]);

  return (
    <div>
      <canvas ref={canvas} className="rounded border border-slate-200 bg-white" />
      {failed && <ErrorState>No se pudo dibujar el QR.</ErrorState>}
    </div>
  );
}

/** Cuenta atrás visible: sin ella nadie sabe si el QR de la pantalla sigue valiendo. */
function Countdown({ expiresAt, onExpired }: { expiresAt: string; onExpired: () => void }) {
  const [remaining, setRemaining] = useState(() => secondsUntil(expiresAt));

  useEffect(() => {
    const timer = setInterval(() => {
      const left = secondsUntil(expiresAt);
      setRemaining(left);
      if (left <= 0) onExpired();
    }, 1000);

    return () => clearInterval(timer);
  }, [expiresAt, onExpired]);

  const minutes = Math.floor(Math.max(0, remaining) / 60);
  const seconds = Math.max(0, remaining) % 60;

  return (
    <p className="num text-sm tabular-nums text-slate-600">
      Caduca en {minutes}:{String(seconds).padStart(2, '0')}
    </p>
  );
}

function secondsUntil(iso: string): number {
  return Math.round((new Date(iso).getTime() - Date.now()) / 1000);
}
