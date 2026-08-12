# 08 — Aplicación web (React 18 + Vite)

---

## 1. Pila técnica

| Preocupación | Elección | Motivo |
|---|---|---|
| Empaquetador | Vite 5 | Ya en uso por el equipo |
| Lenguaje | TypeScript estricto | Los EPC y estados son cadenas con semántica; los tipos evitan errores caros |
| Enrutado | React Router v6 | Ya en uso por el equipo |
| Estado de servidor | TanStack Query v5 | Caché, revalidación y estados de carga sin escribir reductores |
| Estado de cliente | Zustand | Ligero. Solo para ubicación activa, preferencias y filtros persistentes |
| Formularios | React Hook Form + Zod | Validación compartida con el backend vía esquemas |
| Estilos | Tailwind CSS | Ya en uso por el equipo |
| Tablas | TanStack Table | Virtualización necesaria: listados de 20 000 tags |
| Gráficos | Recharts | Suficiente para los tableros previstos |
| Tiempo real | Laravel Echo + Reverb | ADR-007 |
| Pruebas | Vitest + Testing Library + Playwright | — |

---

## 2. Dirección visual

El sistema lo usa gente de pie, a menudo con una mano ocupada, en un almacén con mala luz o en una tienda con mucha luz. Eso manda sobre cualquier consideración estética.

### Principios

1. **Densidad alta pero legible.** Un jefe de tienda quiere ver 40 filas, no 8 tarjetas con mucho aire.
2. **El estado se lee de un vistazo.** El color codifica estado de tag; nunca es decorativo.
3. **Los números son el contenido.** Tipografía tabular para todo lo numérico: las columnas alineadas se comparan sin esfuerzo.
4. **Sin animación gratuita.** La única animación con valor es la barra de progreso del ciclo en curso, porque comunica que el sistema sigue vivo mientras alguien barre una tienda durante 30 minutos.

### Sistema de color por estado

Los estados del tag necesitan distinguirse a distancia y por gente con daltonismo. Se codifican con **color + forma del indicador**, nunca solo color.

| Estado | Color | Indicador |
|---|---|---|
| `en_stock` | Verde 600 | ● círculo lleno |
| `no_visto` | Ámbar 500 | ◐ círculo medio |
| `perdido` | Rojo 600 | ✕ aspa |
| `vendido` | Pizarra 400 | ✓ marca |
| `en_transito` | Azul 500 | → flecha |
| `danado` / `baja` | Pizarra 600 | ■ cuadrado |
| `creado` / `codificado` | Pizarra 300 | ○ círculo vacío |

```ts
// src/lib/tagState.ts
export const TAG_STATE_UI = {
  en_stock:    { label: 'En stock',    color: 'text-emerald-600', dot: '●', bg: 'bg-emerald-50' },
  no_visto:    { label: 'No visto',    color: 'text-amber-600',   dot: '◐', bg: 'bg-amber-50' },
  perdido:     { label: 'Perdido',     color: 'text-red-600',     dot: '✕', bg: 'bg-red-50' },
  vendido:     { label: 'Vendido',     color: 'text-slate-400',   dot: '✓', bg: 'bg-slate-50' },
  en_transito: { label: 'En tránsito', color: 'text-blue-600',    dot: '→', bg: 'bg-blue-50' },
  danado:      { label: 'Dañado',      color: 'text-slate-600',   dot: '■', bg: 'bg-slate-100' },
  baja:        { label: 'Baja',        color: 'text-slate-600',   dot: '■', bg: 'bg-slate-100' },
  creado:      { label: 'Creado',      color: 'text-slate-400',   dot: '○', bg: 'bg-white' },
  codificado:  { label: 'Codificado',  color: 'text-slate-500',   dot: '○', bg: 'bg-white' },
  anulado:     { label: 'Anulado',     color: 'text-slate-400',   dot: '○', bg: 'bg-slate-50' },
} as const satisfies Record<TagState, StateUi>;
```

### Tipografía

- **Interfaz**: Inter (o la pila del sistema). Neutra, alta legibilidad a tamaño pequeño.
- **Datos numéricos y EPC**: JetBrains Mono con `font-variant-numeric: tabular-nums`. Un EPC de 24 caracteres hexadecimales es ilegible en proporcional, y comparar dos visualmente es una tarea real de soporte.

```css
.epc { font-family: 'JetBrains Mono', monospace; letter-spacing: -0.01em; }
.num { font-variant-numeric: tabular-nums; }
```

---

## 3. Mapa de pantallas

```
/                          Panel de la ubicación activa
/inventario
   /ciclos                 Listado de ciclos
   /ciclos/nuevo           Crear ciclo (alcance, zonas, categorías)
   /ciclos/:id             Ciclo en vivo — progreso, por zona
   /ciclos/:id/informe     Conciliación: faltantes, inesperados, diferencias
/stock
   /                       Stock por SKU y ubicación
   /reposicion             Qué falta en sala habiendo en trastienda
   /antiguedad             Aging por tramos
   /valorizacion           Stock valorizado
/prendas
   /                       Buscador de tags
   /:epc                   Ficha de la prenda: estado, ubicación, historial
/catalogo
   /productos              CRUD de productos
   /productos/:id          Variantes, precios, dificultad RFID
   /etiquetas              Generación de lotes de etiquetas e impresión
/recepcion
   /                       Órdenes de recepción
   /:id                    Recepción en vivo por túnel
/transferencias
/alertas                   Bandeja de alertas
/dispositivos
   /                       Lectores, impresoras, salud
   /:id/perfil             Perfil de lectura: sesión, Q, potencia, umbrales
/configuracion
   /ubicaciones            Tiendas y zonas
   /usuarios
   /organizacion           Máscara EPC, esquema, prefijo GS1
```

---

## 4. Pantalla clave: ciclo de inventario en vivo

Es la pantalla que define el producto. Alguien barre la tienda con el handheld; el jefe ve el avance desde el escritorio.

```
┌───────────────────────────────────────────────────────────────────────┐
│  Ciclo INV-2026-08-032 · Tienda Gamarra 1        [Pausar] [Cerrar]     │
│  Iniciado 14:02 · 18 min transcurridos · Operario: M. Quispe          │
├───────────────────────────────────────────────────────────────────────┤
│                                                                        │
│   14 812 de 18 240 esperados                                    81.2 % │
│   ████████████████████████████████████░░░░░░░░░                        │
│                                                                        │
│   Contados 14 903   ·   Esperados 18 240   ·   Inesperados 91          │
├───────────────────────────────────────────────────────────────────────┤
│  Avance por zona                                                       │
│                                                                        │
│  Sala principal      ████████████████████████  98.4 %   6 210/6 312    │
│  Escaparate          ████████████████████████  99.1 %     221/223      │
│  Probadores          ██████████░░░░░░░░░░░░░░  42.0 %      63/150      │
│  Trastienda A        ████████████████░░░░░░░░  67.3 %   4 891/7 268    │
│  Trastienda B        ░░░░░░░░░░░░░░░░░░░░░░░░   0.0 %       0/4 287    │
│                                                                        │
│  ⚠ Probadores muy por debajo. Suele indicar zona no barrida.           │
└───────────────────────────────────────────────────────────────────────┘
```

```tsx
// src/pages/inventory/CycleLive.tsx
export function CycleLive() {
  const { id } = useParams();
  const cycleId = Number(id);

  const { data: cycle } = useQuery({
    queryKey: ['cycle', cycleId],
    queryFn: () => api.cycles.get(cycleId),
    // Sin sondeo: los datos llegan por WebSocket. El refetch de respaldo
    // es lento a propósito, solo para recuperarse si se cae la conexión.
    refetchInterval: 60_000,
  });

  const progress = useCycleProgress(cycleId);   // hook de Echo

  const scanned  = progress?.scanned  ?? cycle?.counted_count ?? 0;
  const expected = progress?.expected ?? cycle?.expected_count ?? 0;
  const pct      = expected > 0 ? (scanned / expected) * 100 : 0;

  return (
    <PageShell
      title={`Ciclo ${cycle?.code ?? ''}`}
      subtitle={cycle && `${cycle.location_name} · iniciado ${formatTime(cycle.started_at)}`}
      actions={<CycleActions cycle={cycle} />}
    >
      <ProgressPanel scanned={scanned} expected={expected} pct={pct} />
      <ZoneBreakdown cycleId={cycleId} />
      <SlowZoneHint cycleId={cycleId} />
    </PageShell>
  );
}
```

```ts
// src/hooks/useCycleProgress.ts
export function useCycleProgress(cycleId: number) {
  const [progress, setProgress] = useState<CycleProgress | null>(null);
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = echo.private(`inventory-cycle.${cycleId}`);

    channel.listen('.InventoryCycleProgressed', (e: CycleProgress) => {
      setProgress(e);
      // Se invalida el desglose por zona, no el ciclo entero:
      // recargar todo en cada evento haría parpadear la pantalla.
      queryClient.invalidateQueries({ queryKey: ['cycle', cycleId, 'zones'] });
    });

    return () => { echo.leave(`inventory-cycle.${cycleId}`); };
  }, [cycleId, queryClient]);

  return progress;
}
```

### El aviso de zona lenta

Es una pequeña función con mucho valor operativo: detecta que alguien no barrió una zona **mientras aún puede volver**, en lugar de descubrirlo en el informe final.

```tsx
function SlowZoneHint({ cycleId }: { cycleId: number }) {
  const { data: zones } = useQuery({
    queryKey: ['cycle', cycleId, 'zones'],
    queryFn: () => api.cycles.zonePerformance(cycleId),
  });

  const laggards = zones?.filter((z) => z.accuracy_pct !== null && z.accuracy_pct < 60) ?? [];
  if (laggards.length === 0) return null;

  return (
    <Callout tone="warning">
      <strong>{laggards.map((z) => z.zone_name).join(', ')}</strong>{' '}
      {laggards.length === 1 ? 'está' : 'están'} muy por debajo del resto.
      Suele indicar una zona que no se llegó a barrer. Vuelve a pasar por ahí
      antes de cerrar el ciclo.
    </Callout>
  );
}
```

---

## 5. Ficha de prenda

La pantalla que responde "¿qué pasó con esta prenda?". Es la herramienta de investigación de merma.

```
┌──────────────────────────────────────────────────────────────────────┐
│  3035D919080C0E403B9ACA2A                        ● En stock          │
│  Polera Oversize Negra · Talla M · SKU 12345                         │
│  Tienda Gamarra 1 → Sala principal                                   │
│  Tarada hace 47 días · Vista por última vez hace 2 h                 │
├──────────────────────────────────────────────────────────────────────┤
│  Historial                                                            │
│                                                                       │
│  10 ago 14:22   Cambio de zona    Trastienda A → Sala principal      │
│                 M. Quispe · Handheld HH-01                            │
│  08 ago 09:14   Ajuste positivo   Reaparece en ciclo INV-031          │
│                 Sistema · Handheld HH-01                              │
│  02 ago 18:40   Ajuste negativo   No detectado en ciclo INV-029       │
│                 Sistema · Handheld HH-02                              │
│  24 jun 11:03   Recepción         → Tienda Gamarra 1                  │
│                 J. Flores · Túnel TUN-01 · OC-2026-118                │
│  24 jun 10:58   Tarado            EPC asociado a SKU 12345            │
│                 J. Flores · Impresora ZT411-01                        │
├──────────────────────────────────────────────────────────────────────┤
│  Detecciones recientes (72 h)                          [Ver todas]    │
│  Gráfico de RSSI por hora y antena                                    │
└──────────────────────────────────────────────────────────────────────┘
```

> El gráfico de RSSI parece un detalle técnico, pero es lo primero que mira soporte cuando alguien dice "el sistema dice que está y no está". Un RSSI consistentemente bajo (−75 dBm) sugiere que se está leyendo desde la tienda vecina o desde otra zona, no que la prenda esté donde dice.

---

## 6. Tabla virtualizada de tags

20 000 filas no caben en el DOM. Virtualización obligatoria.

```tsx
// src/components/TagTable.tsx
export function TagTable({ filters }: { filters: TagFilters }) {
  const { data, fetchNextPage, hasNextPage, isFetchingNextPage } =
    useInfiniteQuery({
      queryKey: ['tags', filters],
      queryFn: ({ pageParam = 1 }) => api.tags.list({ ...filters, page: pageParam }),
      getNextPageParam: (last) => last.meta.current_page < last.meta.last_page
        ? last.meta.current_page + 1
        : undefined,
      initialPageParam: 1,
    });

  const rows = useMemo(() => data?.pages.flatMap((p) => p.data) ?? [], [data]);

  const parentRef = useRef<HTMLDivElement>(null);
  const virtualizer = useVirtualizer({
    count: hasNextPage ? rows.length + 1 : rows.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 44,
    overscan: 12,
  });

  // Carga de la página siguiente al acercarse al final
  useEffect(() => {
    const items = virtualizer.getVirtualItems();
    const last = items.at(-1);
    if (last && last.index >= rows.length - 1 && hasNextPage && !isFetchingNextPage) {
      void fetchNextPage();
    }
  }, [virtualizer.getVirtualItems(), rows.length, hasNextPage, isFetchingNextPage]);

  return (
    <div ref={parentRef} className="h-[70vh] overflow-auto">
      <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
        {virtualizer.getVirtualItems().map((v) => {
          const tag = rows[v.index];
          if (!tag) return <RowSkeleton key={v.key} virtualItem={v} />;
          return <TagRow key={v.key} tag={tag} virtualItem={v} />;
        })}
      </div>
    </div>
  );
}
```

---

## 7. Estados vacíos y de error

Siguiendo el principio de que una pantalla vacía es una invitación a actuar, no un mensaje de disculpa:

| Situación | Texto |
|---|---|
| Sin ciclos de inventario | «Aún no has hecho ningún conteo en esta tienda. Un ciclo completo toma unos 30 minutos con un lector.» + botón «Crear ciclo» |
| Sin resultados de búsqueda de EPC | «Ningún tag coincide con "3035D9". Prueba con menos caracteres, o revisa si la prenda llegó a tararse.» |
| Sin alertas abiertas | «Sin alertas. La última se resolvió el 8 de agosto.» |
| Fallo de red | «No se pudo cargar el stock. Reintentar» + botón. Sin disculpas, sin código de error visible salvo en un desplegable de detalle |
| Lector desconectado | «El lector del portal lleva 12 minutos sin responder. Revisa que esté encendido y conectado a la red.» |

---

## 8. Selección de ubicación activa

Casi toda la aplicación se lee en el contexto de una tienda. La ubicación activa es estado global persistente.

```ts
// src/stores/useLocationStore.ts
export const useLocationStore = create<LocationState>()(
  persist(
    (set) => ({
      activeLocationId: null,
      setActiveLocation: (id) => set({ activeLocationId: id }),
    }),
    { name: 'traza.location' },
  ),
);
```

Un interceptor de la API añade la ubicación activa a las peticiones que la admiten, para que ninguna pantalla tenga que acordarse:

```ts
api.interceptors.request.use((config) => {
  const id = useLocationStore.getState().activeLocationId;
  if (id && config.method === 'get' && !('location' in (config.params ?? {}))) {
    config.params = { ...config.params, location: id };
  }
  return config;
});
```

> **Aviso de seguridad**: esto es una comodidad de la interfaz, no un control de acceso. El backend **siempre** verifica que el usuario tiene permiso sobre esa ubicación mediante políticas. Un parámetro de consulta nunca es una frontera de seguridad.

---

## 9. Accesibilidad y rendimiento

| Requisito | Implementación |
|---|---|
| Foco visible en teclado | `focus-visible` con anillo de 2 px, nunca `outline: none` |
| Contraste | Mínimo 4.5:1 en texto; los estados se distinguen por forma además de color |
| Movimiento reducido | `prefers-reduced-motion` desactiva las transiciones de la barra de progreso |
| Lectores de pantalla | La barra de progreso del ciclo usa `role="progressbar"` con `aria-valuenow` |
| Objetivo táctil | Mínimo 44 × 44 px: la web también se usa en tablet en la trastienda |
| Presupuesto de carga | JS inicial < 250 KB comprimido; las páginas de tablero se cargan bajo demanda |
| Tabla larga | Virtualización obligatoria por encima de 200 filas |

---

## 10. Estructura de carpetas

```
src/
├── main.tsx  App.tsx  router.tsx
├── api/
│   ├── client.ts              # axios + interceptores
│   ├── tags.ts  cycles.ts  stock.ts  devices.ts  alerts.ts
│   └── types.ts               # tipos generados desde el backend
├── components/
│   ├── ui/                    # primitivas: Button, Callout, Badge, PageShell
│   ├── TagTable.tsx  TagStateBadge.tsx  EpcText.tsx
│   └── charts/
├── pages/
│   ├── dashboard/  inventory/  stock/  tags/  catalog/
│   ├── receiving/  transfers/  alerts/  devices/  settings/
├── hooks/
│   ├── useCycleProgress.ts  useEcho.ts  useDebounced.ts
├── stores/
│   ├── useLocationStore.ts  useFiltersStore.ts
├── lib/
│   ├── tagState.ts  format.ts  epc.ts
└── styles/
    └── index.css
```

### Utilidad de formato de EPC

Un EPC de 24 caracteres es ilegible de corrido. Se agrupa visualmente sin alterar el valor:

```ts
// src/lib/epc.ts
/** 3035D919080C0E403B9ACA2A → 3035 D919 080C 0E40 3B9A CA2A */
export const formatEpc = (epc: string): string =>
  epc.replace(/(.{4})/g, '$1 ').trim();

/** Para listados estrechos: 3035…CA2A */
export const shortEpc = (epc: string): string =>
  `${epc.slice(0, 4)}…${epc.slice(-4)}`;
```

> El valor copiado al portapapeles debe ser siempre el EPC **sin espacios**. Un EPC con espacios pegado en un buscador no encuentra nada, y ese es un caso de soporte que se repite.
