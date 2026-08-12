# 11 — Infraestructura, despliegue y observabilidad

---

## 1. Topología de despliegue

```
                        ┌──────────────────────────────┐
                        │   VPS / servidor central     │
                        │   (Lima o nube regional)     │
                        │                              │
                        │  nginx · api · horizon       │
                        │  reverb · scheduler          │
                        │  postgres · redis · mqtt     │
                        │  prometheus · grafana · loki │
                        │  minio                       │
                        └───────────┬──────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    │ WireGuard     │ WireGuard     │ WireGuard
                    │               │               │
            ┌───────▼──────┐ ┌──────▼───────┐ ┌─────▼────────┐
            │ Tienda LIM-01│ │ Tienda LIM-02│ │ Almacén CEN  │
            │              │ │              │ │              │
            │ traza-edge   │ │ traza-edge   │ │ traza-edge   │
            │ + lectores   │ │ + lectores   │ │ + túnel      │
            │ + handhelds  │ │ + handhelds  │ │ + impresora  │
            └──────────────┘ └──────────────┘ └──────────────┘
```

### Dimensionado del servidor central

| Nº de tiendas | vCPU | RAM | Disco | Coste ref./mes |
|---:|---:|---:|---:|---:|
| 1–3 | 4 | 8 GB | 100 GB SSD | USD 25–45 |
| 4–10 | 8 | 16 GB | 250 GB SSD | USD 60–110 |
| 11–30 | 16 | 32 GB | 500 GB SSD NVMe | USD 150–280 |
| 30+ | Separar PostgreSQL a instancia propia | | | |

> **Alternativa auto-hospedada**: el Mac mini M4 del equipo puede servir como servidor central para las Fases 0 y 1 (hasta 2–3 tiendas), con túnel Cloudflare o WireGuard para exposición. No es apropiado para producción con múltiples tiendas: sin redundancia eléctrica ni de red, y una caída deja las tiendas sin sincronizar (aunque siguen operando gracias al buffer local).

---

## 2. Compose del borde

Fichero aparte, desplegado en cada tienda: `infra/docker-compose.edge.yml`.

```yaml
name: traza-edge

services:
  edge:
    image: ${REGISTRY}/traza-edge:${TAG:-latest}
    container_name: traza-edge
    env_file: [.env.edge]
    # Modo host: el descubrimiento de lectores por multicast y las
    # conexiones LLRP salientes son mucho más simples sin NAT de Docker.
    network_mode: host
    volumes:
      - edge-buffer:/app/data
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:9100/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 20s
    restart: always
    logging:
      driver: json-file
      options: { max-size: "10m", max-file: "3" }

  # Reinicia el contenedor si el healthcheck falla de forma sostenida.
  # En una tienda sin personal técnico, esto evita el 90 % de las llamadas.
  watchtower:
    image: containrrr/watchtower
    command: --interval 3600 --cleanup traza-edge
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    restart: always

  # Túnel al central
  wireguard:
    image: linuxserver/wireguard
    cap_add: [NET_ADMIN, SYS_MODULE]
    volumes:
      - ./wireguard:/config
      - /lib/modules:/lib/modules
    sysctls:
      net.ipv4.conf.all.src_valid_mark: 1
    restart: always

volumes:
  edge-buffer:
```

### Requisitos del equipo de borde

| Requisito | Motivo |
|---|---|
| **UPS de 500 VA mínimo** | Un corte eléctrico con escritura en SQLite en curso puede corromper el buffer. El WAL lo mitiga, pero el UPS lo elimina |
| Arranque automático tras corte | `restart: always` + Docker habilitado en el arranque del sistema |
| Reloj sincronizado (NTP) | Las marcas de tiempo de las lecturas se usan para clasificar dirección en el portal. Un reloj desviado 30 s arruina la conciliación |
| Disco con al menos 20 GB libres | El buffer puede crecer durante un corte de red prolongado |
| Acceso remoto (SSH por WireGuard) | Nadie va a viajar a Gamarra a reiniciar un contenedor |

> **El reloj es un problema real y silencioso.** Si el borde tiene el reloj adelantado, las lecturas se insertarán en una partición futura de `tag_reads`, y la conciliación del ciclo puede excluirlas. Configurar `chrony` o `systemd-timesyncd` y **alertar si la desviación supera 5 segundos**.

---

## 3. Dockerfile de la API (multietapa)

```dockerfile
# ---------- Etapa base ----------
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        postgresql-dev libzip-dev icu-dev gmp-dev linux-headers \
    && docker-php-ext-install pdo_pgsql zip intl gmp bcmath pcntl sockets opcache \
    && pecl install redis && docker-php-ext-enable redis

# GMP es obligatorio: el codec SGTIN-96 manipula enteros de 96 bits.
# Sin él, los EPC se corrompen silenciosamente. Ver docs/04, §4.

COPY docker/php.ini /usr/local/etc/php/conf.d/traza.ini
WORKDIR /var/www/html

# ---------- Dependencias ----------
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# ---------- Desarrollo ----------
FROM base AS development
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install xdebug && docker-php-ext-enable xdebug
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
CMD ["php-fpm"]

# ---------- Producción ----------
FROM base AS production
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
CMD ["php-fpm"]
```

`docker/php.ini`:

```ini
memory_limit = 512M
max_execution_time = 120
; Los lotes de ingesta llegan con hasta 1000 lecturas
post_max_size = 16M
upload_max_filesize = 16M

opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0   ; producción: invalidar requiere reinicio
opcache.jit = tracing
opcache.jit_buffer_size = 64M
```

---

## 4. CI/CD (GitLab CI)

El equipo ya usa GitLab. Pipeline en `.gitlab-ci.yml`:

```yaml
stages: [lint, test, build, deploy]

variables:
  DOCKER_BUILDKIT: "1"
  POSTGRES_DB: traza_test
  POSTGRES_USER: traza
  POSTGRES_PASSWORD: secret

# ---------------------------------------------------------------- LINT
lint:php:
  stage: lint
  image: php:8.3-cli-alpine
  script:
    - composer install --no-interaction
    - vendor/bin/pint --test
    - vendor/bin/phpstan analyse --memory-limit=1G

lint:ts:
  stage: lint
  image: node:22-alpine
  script:
    - npm ci
    - npm run lint
    - npm run typecheck

# ---------------------------------------------------------------- TEST
test:backend:
  stage: test
  image: php:8.3-cli-alpine
  services:
    - postgres:16-alpine
    - redis:7-alpine
  before_script:
    - apk add --no-cache postgresql-dev gmp-dev
    - docker-php-ext-install pdo_pgsql gmp bcmath
    - composer install --no-interaction
    - php artisan migrate --force
  script:
    - vendor/bin/pest --coverage --min=70
  coverage: '/^\s*Lines:\s*\d+\.\d+\%/'

# El test del codec EPC es bloqueante y se ejecuta aparte para que
# su fallo sea inconfundible en el informe del pipeline.
test:epc-codec:
  stage: test
  image: php:8.3-cli-alpine
  script:
    - vendor/bin/pest --group=epc
  allow_failure: false

test:edge:
  stage: test
  image: node:22-alpine
  script:
    - cd traza-edge && npm ci && npm run test -- --coverage

test:carga:
  stage: test
  image: grafana/k6:latest
  script:
    - k6 run --vus 50 --duration 60s tests/load/ingest.js
  rules:
    - if: $CI_COMMIT_BRANCH == "main"

# ---------------------------------------------------------------- BUILD
build:
  stage: build
  image: docker:27
  services: [docker:27-dind]
  script:
    - docker login -u $CI_REGISTRY_USER -p $CI_REGISTRY_PASSWORD $CI_REGISTRY
    - docker build --target production -t $CI_REGISTRY_IMAGE/traza-api:$CI_COMMIT_SHA ./traza-api
    - docker build --target production -t $CI_REGISTRY_IMAGE/traza-edge:$CI_COMMIT_SHA ./traza-edge
    - docker push $CI_REGISTRY_IMAGE/traza-api:$CI_COMMIT_SHA
    - docker push $CI_REGISTRY_IMAGE/traza-edge:$CI_COMMIT_SHA
  rules:
    - if: $CI_COMMIT_BRANCH == "main"

# ---------------------------------------------------------------- DEPLOY
deploy:central:
  stage: deploy
  script:
    - ssh deploy@$PROD_HOST "cd /opt/traza && TAG=$CI_COMMIT_SHA docker compose pull && docker compose up -d --no-deps api horizon reverb scheduler portal-listener"
    - ssh deploy@$PROD_HOST "cd /opt/traza && docker compose exec -T api php artisan migrate --force"
  environment: { name: production }
  when: manual
  rules:
    - if: $CI_COMMIT_BRANCH == "main"

# El borde se actualiza DESPUÉS del central y de una en una,
# nunca todas las tiendas a la vez.
deploy:edge:
  stage: deploy
  parallel:
    matrix:
      - STORE: [LIM-01, LIM-02, CEN-01]
  script:
    - ssh deploy@edge-$STORE "cd /opt/traza-edge && TAG=$CI_COMMIT_SHA docker compose pull && docker compose up -d"
    - ./scripts/verify-edge-health.sh $STORE
  when: manual
  needs: ["deploy:central"]
```

### Regla de despliegue

> **Nunca desplegar durante horario comercial.** La ventana es de 22:00 a 06:00 hora de Lima. Un despliegue del borde reinicia el contenedor y hay entre 10 y 30 segundos sin captura de lecturas; con la tienda cerrada, no importa.

---

## 5. Migraciones en producción

| Regla | Detalle |
|---|---|
| **Compatibles hacia atrás** | La versión anterior del código debe seguir funcionando con el esquema nuevo durante el despliegue |
| **Sin `DROP COLUMN` inmediato** | Se despliega en dos pasos: dejar de usar la columna, y eliminarla en el siguiente ciclo |
| **Índices con `CONCURRENTLY`** | `CREATE INDEX CONCURRENTLY` no bloquea escrituras. Requiere ejecutarse fuera de transacción |
| **`ALTER TYPE ... ADD VALUE` no es transaccional** | Añadir un valor a un ENUM debe ir en su propia migración |
| **Prueba previa sobre copia** | Toda migración se ejecuta antes sobre una restauración del respaldo de producción, midiendo el tiempo |

```php
// Ejemplo: índice sin bloquear la tabla de lecturas
public function up(): void
{
    DB::statement('SET statement_timeout = 0');
    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS tag_reads_zone_idx ON tag_reads (zone_id, read_at DESC)');
}

public function withinTransaction(): bool
{
    return false;   // CONCURRENTLY no puede ir en transacción
}
```

---

## 6. Copias de seguridad

### Estrategia 3-2-1

| Copia | Dónde | Frecuencia | Retención |
|---|---|---|---|
| Completa lógica (`pg_dump`) | Disco local del servidor | Diaria 03:00 | 7 días |
| Completa lógica | MinIO / S3 externo | Diaria | 30 días |
| Completa lógica | Almacenamiento externo distinto (otro proveedor) | Semanal | 12 semanas |
| WAL continuo | MinIO | Continuo | 7 días (permite PITR) |

```bash
#!/usr/bin/env bash
# scripts/backup.sh
set -euo pipefail

STAMP=$(date +%Y%m%d_%H%M%S)
FILE="/backups/traza_${STAMP}.dump"

while true; do
  echo "[backup] Iniciando respaldo ${STAMP}"

  # --format=custom permite restauración selectiva de tablas
  pg_dump --host=postgres --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" \
          --format=custom --compress=9 --file="$FILE"

  # Verificación: un respaldo no verificado no es un respaldo
  pg_restore --list "$FILE" > /dev/null || { echo "[backup] CORRUPTO"; exit 1; }

  # Copia externa
  aws s3 cp "$FILE" "s3://${BACKUP_BUCKET}/daily/" --endpoint-url "$AWS_ENDPOINT"

  # Rotación local
  find /backups -name 'traza_*.dump' -mtime +7 -delete

  echo "[backup] Completado: $(du -h "$FILE" | cut -f1)"
  sleep 86400
done
```

### Prueba de restauración

> **Un respaldo que nunca se ha restaurado no existe.** Calendario obligatorio: **restauración completa sobre el entorno de staging el primer lunes de cada mes**, midiendo el tiempo total. El objetivo de recuperación (RTO) es de 2 horas; si la restauración tarda más, hay que replantear la estrategia.

### Qué NO hace falta respaldar

- `tag_reads` de más de 30 días: se exporta a Parquet y se puede regenerar el análisis desde ahí. Excluirla del `pg_dump` diario reduce el tamaño drásticamente:

```bash
pg_dump --exclude-table-data='tag_reads_*' ...
```

- Los movimientos de stock **sí** se respaldan siempre, sin excepción. Son la única tabla verdaderamente irremplazable.

---

## 7. Observabilidad

### Métricas (Prometheus)

```yaml
# prometheus/prometheus.yml
global:
  scrape_interval: 15s

rule_files: [/etc/prometheus/alerts.yml]

scrape_configs:
  - job_name: traza-api
    metrics_path: /metrics
    static_configs: [{ targets: ['api:9000'] }]

  - job_name: traza-edge
    metrics_path: /metrics
    static_configs:
      - targets: ['edge-lim01:9100', 'edge-lim02:9100', 'edge-cen01:9100']
        labels: { component: edge }

  - job_name: postgres
    static_configs: [{ targets: ['postgres-exporter:9187'] }]

  - job_name: redis
    static_configs: [{ targets: ['redis-exporter:9121'] }]
```

### Alertas que importan

```yaml
# prometheus/alerts.yml
groups:
  - name: traza-criticas
    rules:

      # El borde caído significa que la tienda no está capturando nada.
      - alert: EdgeSinLatido
        expr: up{component="edge"} == 0
        for: 3m
        labels: { severity: critical }
        annotations:
          summary: "El borde de {{ $labels.instance }} lleva 3 minutos sin responder"
          runbook: "docs/11-infraestructura-docker.md#9-manual-de-incidencias"

      # El buffer creciendo indica que no se está sincronizando.
      - alert: BufferDeBordeCreciendo
        expr: traza_edge_buffer_depth > 50000
        for: 10m
        labels: { severity: warning }
        annotations:
          summary: "{{ $labels.instance }} acumula {{ $value }} lecturas sin enviar"

      # El escucha de portal caído deja la tienda sin antihurto,
      # y nadie lo nota hasta que roban algo.
      - alert: EscuchaPortalCaido
        expr: traza_portal_listener_alive == 0
        for: 2m
        labels: { severity: critical }

      - alert: LectorDesconectado
        expr: traza_edge_reader_connected == 0
        for: 5m
        labels: { severity: warning }

      # Un pico de descartes por máscara suele significar que el vecino
      # instaló RFID, o que entró mercadería sin tarar.
      - alert: PicoDeEpcAjenos
        expr: rate(traza_edge_reads_dropped_total{stage="epc_mask"}[15m]) > 100
        for: 15m
        labels: { severity: info }

      - alert: ColaDeLecturasAtascada
        expr: horizon_queue_wait_seconds{queue="reads"} > 120
        for: 5m
        labels: { severity: warning }

      - alert: ParticionDefaultConDatos
        expr: traza_tag_reads_default_rows > 0
        for: 1h
        labels: { severity: warning }
        annotations:
          summary: "Hay filas en tag_reads_default: la rotación de particiones falló"

      - alert: DiscrepanciaProyeccionMovimientos
        expr: traza_stock_projection_mismatch > 0
        labels: { severity: critical }
        annotations:
          summary: "El stock proyectado no coincide con los movimientos. Ver docs/05, §6"
```

### Registros (Loki)

Todos los servicios emiten JSON estructurado. Campos obligatorios en cada línea:

```json
{
  "ts": "2026-08-10T14:22:11.418Z",
  "level": "info",
  "service": "traza-api",
  "trace_id": "0198f2b4-...",
  "location_code": "LIM-01",
  "device_code": "EDGE-LIM01",
  "event": "read_batch_ingested",
  "accepted": 500,
  "rejected": 3
}
```

> El `trace_id` se propaga desde el borde hasta el job de Horizon. Sin él, diagnosticar por qué una prenda no aparece en un ciclo es imposible: hay que poder seguir una lectura concreta desde la antena hasta la base de datos.

### Tableros de Grafana

| Tablero | Para quién | Paneles |
|---|---|---|
| **Salud operativa** | Equipo técnico | Estado de bordes y lectores, profundidad de buffers, latencia de ingesta, colas |
| **Calidad de lectura** | Responsable técnico | Descartes por etapa del pipeline, distribución de RSSI, tasa por lector |
| **Rendimiento de base** | Backend | Consultas lentas, tamaño de particiones, ratio de aciertos de caché |
| **Negocio** | Gerencia | Ver documento 13 |

---

## 8. Seguridad de la infraestructura

| Capa | Medida |
|---|---|
| Red | Toda comunicación tienda↔central por WireGuard. La API no se expone públicamente salvo el endpoint de la web |
| TLS | Let's Encrypt con renovación automática. TLS 1.3 mínimo. HSTS activado |
| MQTT | Solo puerto 8883 (TLS). Autenticación por usuario/contraseña por dispositivo. ACL por tópico: cada borde solo publica en `traza/{su_tienda}/#` |
| Contenedores | Imágenes sin root. Sin `privileged`. Escaneo de vulnerabilidades en CI (Trivy) |
| Secretos | Nunca en el repositorio. En producción, variables de entorno inyectadas desde el gestor de secretos de GitLab |
| Base de datos | Sin puerto expuesto al exterior. Usuario de aplicación sin `SUPERUSER` |
| SSH | Solo por clave, solo a través de WireGuard, sin acceso root directo |
| Actualizaciones | `unattended-upgrades` para parches de seguridad del sistema base |

---

## 9. Manual de incidencias

### «Una tienda no sincroniza»

```
1. ¿Responde el borde?           ssh edge-LIM01 'docker ps'
2. ¿Está el contenedor sano?     docker inspect --format='{{.State.Health.Status}}' traza-edge
3. ¿Cuánto hay en el buffer?     curl localhost:9100/metrics | grep buffer_depth
4. ¿Hay conectividad?            ping traza.ejemplo.pe ; wg show
5. ¿Es un problema de token?     docker logs traza-edge | grep -i 401

  → Si el buffer crece pero hay red: revisar el token del dispositivo
  → Si no hay red: el buffer aguanta ≥8 h. No hay urgencia inmediata,
    pero avisar a la tienda de que no cierren ciclos hasta recuperar.
```

### «El portal no suena»

```
1. ¿Vive el escucha?             docker compose ps portal-listener
2. ¿Llegan mensajes MQTT?        mosquitto_sub -h host -t 'traza/+/portal' -v
3. ¿Está conectado el lector?    curl edge:9100/metrics | grep reader_connected
4. ¿Está la alarma habilitada?   revisar TRAZA_PORTAL_ALARM
5. ¿Está el umbral demasiado alto? revisar TRAZA_PORTAL_CONFIDENCE

  → Causa más frecuente: alguien subió el umbral de confianza para
    silenciar falsos positivos y lo dejó demasiado alto.
```

### «El inventario da números raros»

```
1. Comparar proyección vs movimientos (docs/05, §6)
2. Revisar cycle_zone_performance(): ¿hay una zona con 0 %?
3. Revisar traza_edge_reads_dropped_total por etapa
4. Revisar la desviación del reloj del borde
5. Revisar si se cambió el perfil de lectura recientemente

  → Causa más frecuente por mucho: una zona que no se barrió.
    Antes de sospechar del software, mirar el desglose por zona.
```

### «La base va lenta»

```
1. pg_stat_statements: ¿qué consulta domina?
2. ¿Ha fallado la rotación de particiones? (tabla enorme sin particionar)
3. ¿Está la vista materializada sin refrescar?
4. ¿Hay bloat? Revisar autovacuum en stock_movements y tag_reads

  → tag_reads sin rotar es la causa número uno. Verificar
    SELECT count(*) FROM tag_reads_default;
```

---

## 10. Plan de recuperación ante desastres

| Escenario | RTO | RPO | Procedimiento |
|---|---|---|---|
| Contenedor caído | 2 min | 0 | `restart: always` lo recupera solo |
| Servidor central caído | 2 h | 24 h (o 5 min con WAL) | Restaurar respaldo en servidor nuevo; los bordes reintentan solos |
| Corrupción de base de datos | 4 h | 24 h | PITR desde WAL al instante anterior al incidente |
| Pérdida del borde de una tienda | 1 h | Lo que hubiera en su buffer | Reinstalar imagen y `.env.edge`; alta de dispositivo nueva |
| Ransomware | 8 h | 7 días | Restaurar desde la copia externa del tercer proveedor |

> **Lo importante de esta tabla**: mientras el central esté caído, **las tiendas siguen operando**. Los handhelds guardan en local, el borde amortigua, la caja vende por código de barras. El diseño offline-first no es un lujo: es lo que evita que un problema de infraestructura cierre una tienda.
