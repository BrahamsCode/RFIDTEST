import { useEffect, useMemo, useRef } from 'react';
import { useInfiniteQuery } from '@tanstack/react-query';
import { useVirtualizer } from '@tanstack/react-virtual';
import { Link } from 'react-router-dom';
import { tags, type TagFilters } from '../lib/api';
import { formatEpc, formatNumber } from '../lib/format';
import { TagStateBadge } from './TagStateBadge';
import { EmptyState, Spinner } from './ui';

const ROW_HEIGHT = 44;

/**
 * 20 000 filas no caben en el DOM. Virtualización obligatoria: se renderiza
 * solo lo visible y se pide la página siguiente al acercarse al final.
 */
export function TagTable({ filters }: { filters: TagFilters }) {
  const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isPending } =
    useInfiniteQuery({
      queryKey: ['tags', filters],
      queryFn: ({ pageParam }) => tags.list({ ...filters, page: pageParam }),
      getNextPageParam: (last) =>
        last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
      initialPageParam: 1,
    });

  const rows = useMemo(() => data?.pages.flatMap((p) => p.data) ?? [], [data]);
  const total = data?.pages[0]?.meta.total ?? 0;

  const parentRef = useRef<HTMLDivElement>(null);
  const virtualizer = useVirtualizer({
    count: hasNextPage ? rows.length + 1 : rows.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 12,
  });

  const items = virtualizer.getVirtualItems();

  useEffect(() => {
    const last = items.at(-1);

    if (last && last.index >= rows.length - 1 && hasNextPage && !isFetchingNextPage) {
      void fetchNextPage();
    }
  }, [items, rows.length, hasNextPage, isFetchingNextPage, fetchNextPage]);

  if (isPending) return <Spinner />;
  if (rows.length === 0) return <EmptyState>Ninguna prenda coincide con el filtro.</EmptyState>;

  return (
    <div>
      <p className="mb-2 text-sm text-slate-500">
        <span className="num tabular-nums">{formatNumber(total)}</span> prendas
      </p>

      <div className="grid grid-cols-[16rem_9rem_1fr_9rem] gap-3 border-b border-slate-200 px-3 pb-2 text-xs font-medium uppercase tracking-wide text-slate-500">
        <span>EPC</span>
        <span>Estado</span>
        <span>Ubicación</span>
        <span className="text-right">Vista</span>
      </div>

      <div ref={parentRef} className="h-[32rem] overflow-auto">
        <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
          {items.map((item) => {
            const tag = rows[item.index];

            return (
              <div
                key={item.key}
                style={{
                  position: 'absolute',
                  top: 0,
                  left: 0,
                  width: '100%',
                  height: item.size,
                  transform: `translateY(${item.start}px)`,
                }}
                className="grid grid-cols-[16rem_9rem_1fr_9rem] items-center gap-3 border-b border-slate-100 px-3 text-sm"
              >
                {tag === undefined ? (
                  <span className="col-span-4 text-slate-400">Cargando más…</span>
                ) : (
                  <>
                    <Link
                      to={`/prendas/${tag.epc}`}
                      className="epc truncate text-slate-800 underline-offset-2 hover:underline"
                    >
                      {formatEpc(tag.epc)}
                    </Link>
                    <TagStateBadge state={tag.state} />
                    <span className="truncate text-slate-600">
                      {tag.current_zone_id ? `Zona ${tag.current_zone_id}` : '—'}
                    </span>
                    <span className="num text-right tabular-nums text-slate-500">
                      {tag.last_seen_at ? relativeTime(tag.last_seen_at) : '—'}
                    </span>
                  </>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

function relativeTime(iso: string): string {
  const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60_000);

  if (minutes < 60) return `${minutes} min`;
  if (minutes < 60 * 24) return `${Math.round(minutes / 60)} h`;
  return `${Math.round(minutes / (60 * 24))} d`;
}
