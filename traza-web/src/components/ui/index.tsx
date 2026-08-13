import type { ReactNode } from 'react';

/**
 * Primitivas de interfaz. Ver `docs/08` §2.
 *
 * Lo usa gente de pie, con una mano ocupada, en un almacén con mala luz o
 * una tienda con mucha luz. Densidad alta pero legible, números tabulares,
 * y nada de animación que no comunique algo.
 */

export function PageShell({
  title,
  subtitle,
  actions,
  children,
}: {
  title: string;
  subtitle?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
}) {
  return (
    <div className="mx-auto max-w-6xl px-6 py-6">
      <header className="mb-6 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">{title}</h1>
          {subtitle && <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>}
        </div>
        {actions && <div className="flex shrink-0 gap-2">{actions}</div>}
      </header>
      {children}
    </div>
  );
}

export function Card({ title, children, className = '' }: {
  title?: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={`rounded border border-slate-200 bg-white ${className}`}>
      {title && (
        <h2 className="border-b border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700">
          {title}
        </h2>
      )}
      <div className="p-4">{children}</div>
    </section>
  );
}

export function Button({
  children,
  onClick,
  variant = 'default',
  disabled,
  type = 'button',
}: {
  children: ReactNode;
  onClick?: () => void;
  variant?: 'default' | 'primary' | 'danger';
  disabled?: boolean;
  type?: 'button' | 'submit';
}) {
  const styles = {
    default: 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
    primary: 'border-slate-900 bg-slate-900 text-white hover:bg-slate-800',
    danger: 'border-red-600 bg-white text-red-700 hover:bg-red-50',
  }[variant];

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`rounded border px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${styles}`}
    >
      {children}
    </button>
  );
}

export function Callout({ tone = 'info', children }: {
  tone?: 'info' | 'warning' | 'danger';
  children: ReactNode;
}) {
  const styles = {
    info: 'border-blue-200 bg-blue-50 text-blue-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    danger: 'border-red-200 bg-red-50 text-red-900',
  }[tone];

  return (
    <div className={`rounded border px-4 py-3 text-sm ${styles}`} role="status">
      {children}
    </div>
  );
}

/**
 * Un número grande con su etiqueta. El panel de tienda tiene que leerse a
 * 3 metros, así que el número manda y la etiqueta acompaña.
 */
export function BigNumber({ label, value, hint, tone = 'neutral' }: {
  label: string;
  value: string;
  hint?: string;
  tone?: 'neutral' | 'good' | 'warning' | 'bad';
}) {
  const color = {
    neutral: 'text-slate-900',
    good: 'text-emerald-600',
    warning: 'text-amber-600',
    bad: 'text-red-600',
  }[tone];

  return (
    <div className="rounded border border-slate-200 bg-white p-5">
      <p className="text-sm font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`num mt-1 text-5xl font-semibold tabular-nums ${color}`}>{value}</p>
      {hint && <p className="mt-1 text-sm text-slate-500">{hint}</p>}
    </div>
  );
}

/**
 * La única animación con valor del sistema: comunica que sigue vivo
 * mientras alguien barre una tienda durante 30 minutos.
 */
export function ProgressBar({ pct, tone = 'neutral' }: {
  pct: number;
  tone?: 'neutral' | 'good' | 'warning' | 'bad';
}) {
  const bar = {
    neutral: 'bg-slate-700',
    good: 'bg-emerald-600',
    warning: 'bg-amber-500',
    bad: 'bg-red-600',
  }[tone];

  const clamped = Math.max(0, Math.min(100, pct));

  return (
    <div
      className="h-3 w-full overflow-hidden rounded-full bg-slate-200"
      role="progressbar"
      aria-valuenow={Math.round(clamped)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div
        className={`h-full transition-[width] duration-500 ease-out ${bar}`}
        style={{ width: `${clamped}%` }}
      />
    </div>
  );
}

export function Spinner({ label = 'Cargando…' }: { label?: string }) {
  return <p className="py-6 text-center text-sm text-slate-500">{label}</p>;
}

export function EmptyState({ children }: { children: ReactNode }) {
  return <p className="py-8 text-center text-sm text-slate-500">{children}</p>;
}

export function ErrorState({ children }: { children: ReactNode }) {
  return <Callout tone="danger">{children}</Callout>;
}
