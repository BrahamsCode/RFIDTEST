import { useState } from 'react';
import { TagTable } from '../../components/TagTable';
import { Card, PageShell } from '../../components/ui';
import { TAG_STATES, type TagState } from '../../lib/domain';
import { TAG_STATE_UI } from '../../lib/tagState';

export function TagList() {
  const [epc, setEpc] = useState('');
  const [state, setState] = useState<TagState | ''>('');

  return (
    <PageShell title="Prendas" subtitle="Buscador por EPC y estado">
      <Card className="mb-4">
        <div className="flex flex-wrap gap-3">
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">EPC empieza por</span>
            <input
              value={epc}
              onChange={(e) => setEpc(e.target.value.toUpperCase())}
              placeholder="3035D9…"
              className="epc w-64 rounded border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:outline-none"
            />
          </label>

          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Estado</span>
            <select
              value={state}
              onChange={(e) => setState(e.target.value as TagState | '')}
              className="w-48 rounded border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:outline-none"
            >
              <option value="">Todos</option>
              {TAG_STATES.map((s) => (
                <option key={s} value={s}>
                  {TAG_STATE_UI[s].label}
                </option>
              ))}
            </select>
          </label>
        </div>
      </Card>

      <Card>
        <TagTable filters={{ epc: epc || undefined, state: state || undefined }} />
      </Card>
    </PageShell>
  );
}
