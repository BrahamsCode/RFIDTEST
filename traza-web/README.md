# traza-web

Aplicación web de TRAZA. Vite + React 18 + TypeScript + Tailwind.
Ver `docs/08-frontend-react.md`.

## Puesta en marcha

```bash
npm install
cp .env.example .env
npm run dev
```

## Comandos

| Comando | Qué hace |
|---|---|
| `npm run dev` | Servidor de desarrollo en :5173 |
| `npm run build` | Compila a `dist/` |
| `npm run typecheck` | Comprobación de tipos |
| `npm test` | Pruebas con Vitest |

## Dirección visual

Lo usa gente de pie, con una mano ocupada, en un almacén con mala luz o una
tienda con mucha luz. Eso manda sobre cualquier consideración estética:

1. Densidad alta pero legible: un jefe de tienda quiere 40 filas, no 8 tarjetas.
2. El estado se lee de un vistazo. El color nunca es decorativo.
3. Los números son el contenido: tipografía tabular en todo lo numérico.
4. Sin animación gratuita.

El estado de una prenda se codifica con **color y forma** (`TAG_STATE_UI` en
`src/lib/tagState.ts`), nunca solo color: hay operarios con daltonismo.

`src/lib/domain.ts` refleja los ENUM de `sql/schema.sql`. Si cambia el esquema,
hay que actualizarlo — la prueba de `tagState.test.ts` detecta el desajuste.

## Estado de implementación

| Pieza | Estado |
|---|---|
| Proyecto, Tailwind y Dockerfile | Hecho |
| Cliente de API con interceptores | Hecho |
| `TAG_STATE_UI` y tipos de dominio | Hecho |
| Pantalla de salud del sistema | Hecho |
| Autenticación con Sanctum | Pendiente — tarea 4.1 |
| Ciclo de inventario en vivo | Pendiente — tarea 4.2 |
| Tabla virtualizada de tags | Pendiente — tarea 4.5 |
