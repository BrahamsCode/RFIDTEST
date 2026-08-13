# traza-api

Backend de TRAZA. Laravel 11 + PostgreSQL 16 + Redis. Ver `docs/06-backend-laravel.md`.

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Con Docker se levanta junto al resto del entorno:

```bash
docker compose -f ../infra/docker-compose.yml up -d
```

## Comandos

| Comando | Qué hace |
|---|---|
| `php artisan serve` | Servidor HTTP |
| `php artisan horizon` | Trabajos en cola |
| `php artisan reverb:start` | WebSocket para tiempo real |
| `php artisan test` | Pruebas |

Las pruebas corren sobre SQLite en memoria y las que dependen del esquema
PostgreSQL real se saltan solas. Para ejecutarlas:

```bash
DB_CONNECTION=pgsql php artisan test --group=pgsql
```

## ⚠️ `migrate:fresh` necesita `--drop-types`

```bash
php artisan migrate:fresh --drop-types
```

`migrate:fresh` a secas borra las tablas pero **no** los tipos ENUM de
PostgreSQL. La primera vez funciona; la segunda falla con
`type "tag_state" already exists`. No es un fallo del esquema, es cómo
funciona Laravel: `--drop-types` existe justo para esto.

Las funciones no dan problema porque se declaran con `CREATE OR REPLACE`.

## Regla arquitectónica

`StockMovementService` es el **único** punto autorizado para escribir en
`stock_movements` o mutar `tags.state`, `tags.current_location_id` y
`tags.current_zone_id`. Sin excepciones. Ver `docs/06` §2.

## Configuración de dominio

Los parámetros de negocio viven en `config/traza.php`, no dispersos por el
código: esquema EPC, umbral de prendas no vistas, gracia del portal y límites
de ingesta.

## Codificación EPC

El sistema soporta **SGTIN-96 y GID-96 a la vez** (ADR-009). `EpcCodecFactory`
deduce el esquema por la cabecera del propio EPC, así que migrar de uno a otro
no obliga a re-etiquetar el inventario existente.

- **SGTIN-96** (`0x30`) requiere prefijo de compañía GS1. Es el único camino a
  interoperar con proveedores y marketplaces.
- **GID-96** (`0x35`) no requiere GS1 y sirve para arrancar, pero **no es
  interoperable**: ningún socio comercial podrá leer esos EPC.

Sin `TRAZA_GS1_COMPANY_PREFIX`, emitir etiquetas SGTIN falla con un mensaje
que remite a la tarea 0.2. Para arrancar sin GS1, poner
`TRAZA_EPC_SCHEME=gid-96`.

El grupo `epc` se ejecuta aparte en CI para que su fallo sea inconfundible:

```bash
php artisan test --group=epc
```

Un error ahí corrompe identificadores de forma silenciosa e irreversible.

## Estado de implementación

| Pieza | Estado |
|---|---|
| Proyecto, configuración y Dockerfile | Hecho |
| `config/traza.php` y `config/mqtt.php` | Hecho |
| `GET /api/v1/health` | Hecho |
| Sanctum, Horizon, Reverb instalados | Hecho |
| Migraciones del esquema (15) | Hecho — tarea 1.1 |
| `TagStateMachine` y enums | Hecho — tarea 1.3 |
| `StockMovementService` y `MovementIntent` | Hecho — tarea 1.4 |
| Ingesta de lecturas y latido | Hecho — tarea 2.1 |
| `ProcessReadBatch` (clonación por TID) | Parcial — tarea 2.2, falta el enrutado |
| Ciclos de inventario y conciliación | Hecho — tareas 3.1, 3.2 y 3.3 |
| Recepción, transferencias, ventas, re-etiquetado | Hecho — épica 7 |
| Superficie REST con RFC 7807, paginación e idempotencia | Hecho — `docs/06` §6 |
| Vistas y funciones analíticas | Hecho — tarea 8.1 |
| Codecs SGTIN-96 y GID-96 con su factoría | Hecho — tarea 1.2 |
| Reserva concurrente de seriales | Hecho — tarea 1.5 |
| Catálogo, stock, dispositivos, lotes de etiquetas | Pendiente — endpoints; las vistas ya están |
| Codec SGTIN-96 (requiere GMP) | Pendiente — tarea 1.2, bloqueada por la 0.2 |
| Reserva de seriales (envoltorio PHP) | Pendiente — tarea 1.5 |

## Endpoints

Superficie de `docs/06` §6. `php artisan route:list --path=api/v1` da la lista viva.

**Token de dispositivo** (borde y handheld, sin sesión):

| Ruta | Qué hace |
|---|---|
| `POST /api/v1/ingest/reads` | Ingesta de lecturas, idempotente por `batch_id` |
| `POST /api/v1/ingest/heartbeat` | Latido del borde |
| `POST /api/v1/inventory-cycles/{id}/scans` | Escaneos del handheld, deduplicados por EPC |

**Sesión de usuario** (Sanctum): tags y su historial, ciclos de inventario
(crear, arrancar, pausar, cerrar, informe, avance por zona), movimientos y
transferencias, órdenes de recepción, ventas y devoluciones, y la bandeja de
alertas.

### Convenciones

| Aspecto | Cómo |
|---|---|
| Errores | RFC 7807, `application/problem+json` |
| Paginación | `?page=` y `?per_page=` (máximo 200), con `meta.total` |
| Idempotencia | Cabecera `Idempotency-Key` en los POST que mutan stock |
| Autenticación de dispositivo | `X-Device-Code` y `X-Device-Token`; el token se guarda hasheado |

`Idempotency-Key` no es opcional en la práctica: sin ella, un reintento por
timeout de red cobra, recibe o transfiere dos veces la misma mercadería. La
respuesta original se reproduce con la cabecera `Idempotent-Replay: true`.
