# traza-web

Aplicación web de TRAZA. Vite + React 18 + TypeScript + Tailwind.
Ver `docs/08-frontend-react.md`.

## Puesta en marcha

```bash
npm install
cp .env.example .env
npm run dev
```

La API tiene que estar corriendo y con `SANCTUM_STATEFUL_DOMAINS` incluyendo
el dominio de la web (por defecto `localhost:5173`), o el navegador rechazará
la cookie de sesión.

## Comandos

| Comando | Qué hace |
|---|---|
| `npm run dev` | Servidor de desarrollo en :5173 |
| `npm run build` | Compila a `dist/` |
| `npm run lint` | Comprobación de tipos |
| `npm test` | Pruebas con Vitest |

## Pantallas

| Ruta | Qué muestra |
|---|---|
| `/` | Panel de tienda: cuatro números grandes y antigüedad del stock |
| `/inventario` | Listado de ciclos |
| `/inventario/:id` | **Ciclo en vivo**: progreso, avance por zona y aviso de zona lenta |
| `/stock` | Existencias por SKU y zona, valorización y reposición |
| `/prendas` | Buscador con tabla virtualizada |
| `/prendas/:epc` | Ficha de prenda con historial completo |
| `/alertas` | Bandeja, lo más grave primero |
| `/portal` | **Portal antihurto**: alarmas en vivo, falso positivo de un toque y tasa de calibración |
| `/dispositivos` | Lectores y handhelds, con el QR de alta y su cuenta atrás |

## Dirección visual

Lo usa gente de pie, con una mano ocupada, en un almacén con mala luz o una
tienda con mucha luz. Eso manda sobre cualquier consideración estética:

1. Densidad alta pero legible: un jefe de tienda quiere 40 filas, no 8 tarjetas.
2. El estado se lee de un vistazo. El color nunca es decorativo.
3. Los números son el contenido: tipografía tabular en todo lo numérico.
4. Sin animación gratuita. La única con valor es la barra de progreso del
   ciclo, porque comunica que el sistema sigue vivo mientras alguien barre la
   tienda durante media hora.

El estado de una prenda se codifica con **color y forma** (`TAG_STATE_UI` en
`src/lib/tagState.ts`), nunca solo color: hay operarios con daltonismo.

`src/lib/domain.ts` refleja los ENUM de `sql/schema.sql`. Si cambia el
esquema, hay que actualizarlo — la prueba de `tagState.test.ts` detecta el
desajuste.

## El aviso de zona lenta

Es la función con más valor operativo de la aplicación: detecta que alguien no
barrió una zona **mientras aún puede volver**, en lugar de descubrirlo en el
informe final, cuando ya se ha generado merma falsa. Tiene sus propias
pruebas en `src/pages/inventory/CycleLive.test.tsx`.

## El botón de falso positivo

La pantalla del portal existe para una sola cosa: que marcar una alarma como
falsa cueste **un toque**, sin diálogo de confirmación ni nota obligatoria. Es
el único dato que permite calibrar el arco, y si cuesta más nadie lo registra;
un portal con más del 20 % de falsos positivos acaba desconectado, y entonces
no detecta nada. La tasa se muestra en la misma pantalla, con el aviso de
recalibración cuando pasa del umbral. Tiene sus pruebas en
`src/pages/Portal.test.tsx`.

## Estado de implementación

| Pieza | Estado |
|---|---|
| Base, sesión, enrutado y primitivas | Hecho — tarea 4.1 |
| Ciclo en vivo con aviso de zona lenta | Hecho — tarea 4.2 |
| Stock, valorización y reposición | Hecho — tarea 4.3 |
| Ficha de prenda con historial | Hecho — tarea 4.4 |
| Tabla virtualizada | Hecho — tarea 4.5 |
| Panel de tienda | Hecho — tarea 4.7 |
| Catálogo y lotes de etiquetas | Pendiente — tarea 4.6 |
| Tiempo real por Reverb | Hecho — tarea 3.4; sin `VITE_REVERB_KEY` sondea cada 5 s |
| Portal antihurto y falsos positivos | Hecho — tareas 6.3 y 6.4 |
| Dispositivos y QR de alta | Hecho — tarea 5.6 |
| Gráfico de RSSI en la ficha | Pendiente — necesita endpoint de detecciones |
