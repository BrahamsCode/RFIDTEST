import type { TagState } from '../lib/domain';
import { stateUi } from '../lib/tagState';

export function TagStateBadge({ state }: { state: TagState }) {
  const ui = stateUi(state);

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded px-2 py-0.5 text-sm font-medium ${ui.color} ${ui.bg}`}
    >
      <span aria-hidden="true">{ui.dot}</span>
      {ui.label}
    </span>
  );
}
