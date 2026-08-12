import { useQuery } from '@tanstack/react-query';
import { fetchHealth } from './lib/api';
import { TAG_STATES } from './lib/domain';
import { TagStateBadge } from './components/TagStateBadge';
import { formatEpc } from './lib/format';

function HealthPanel() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['health'],
    queryFn: fetchHealth,
    retry: false,
  });

  if (isPending) return <p className="text-slate-500">Consultando la API…</p>;
  if (isError) return <p className="text-red-600">API no disponible.</p>;

  return (
    <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
      <dt className="text-slate-500">Estado</dt>
      <dd className={data.status === 'ok' ? 'text-emerald-600' : 'text-amber-600'}>
        {data.status}
      </dd>
      <dt className="text-slate-500">Esquema EPC</dt>
      <dd className="epc">{data.epc_scheme}</dd>
      {Object.entries(data.checks).map(([name, ok]) => (
        <div key={name} className="contents">
          <dt className="text-slate-500">{name}</dt>
          <dd className={ok ? 'text-emerald-600' : 'text-red-600'}>{ok ? 'ok' : 'caído'}</dd>
        </div>
      ))}
    </dl>
  );
}

export default function App() {
  return (
    <main className="mx-auto max-w-3xl p-8">
      <header className="mb-8">
        <h1 className="text-2xl font-semibold">TRAZA</h1>
        <p className="text-slate-500">Control de stock por RFID</p>
      </header>

      <section className="mb-8 rounded border border-slate-200 bg-white p-4">
        <h2 className="mb-3 font-medium">Salud del sistema</h2>
        <HealthPanel />
      </section>

      <section className="mb-8 rounded border border-slate-200 bg-white p-4">
        <h2 className="mb-3 font-medium">Estados de prenda</h2>
        <div className="flex flex-wrap gap-2">
          {TAG_STATES.map((state) => (
            <TagStateBadge key={state} state={state} />
          ))}
        </div>
      </section>

      <section className="rounded border border-slate-200 bg-white p-4">
        <h2 className="mb-3 font-medium">Formato de EPC</h2>
        <p className="epc num text-slate-700">{formatEpc('3035D919080C0E403B9ACA2A')}</p>
      </section>
    </main>
  );
}
