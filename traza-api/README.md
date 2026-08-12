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

⚠️ `TRAZA_GS1_COMPANY_PREFIX` debe obtenerse de GS1 Perú antes de emitir
etiquetas (tarea 0.2). Cambiar el esquema EPC después de tarar invalida todo
lo ya etiquetado.

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
| Codec SGTIN-96 (requiere GMP) | Pendiente — tarea 1.2, bloqueada por la 0.2 |
| Reserva de seriales (envoltorio PHP) | Pendiente — tarea 1.5 |
| Endpoint de ingesta | Pendiente — tarea 2.1 |
