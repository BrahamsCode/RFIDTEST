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

Las pruebas corren sobre SQLite en memoria. Las que dependan del esquema
PostgreSQL real (particiones, ENUM, triggers) deben apuntar al contenedor.

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
| Migraciones del esquema | Pendiente — tarea 1.1 |
| Codec SGTIN-96 (requiere GMP) | Pendiente — tarea 1.2 |
| `StockMovementService` | Pendiente — tarea 1.4 |
| Endpoint de ingesta | Pendiente — tarea 2.1 |
