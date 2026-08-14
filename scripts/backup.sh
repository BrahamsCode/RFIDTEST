#!/usr/bin/env bash
#
# Respaldo lógico de TRAZA. Ver `docs/11-infraestructura-docker.md` §6.
#
# Se aparta del borrador del documento en tres cosas, y las tres son la
# diferencia entre un respaldo y la ilusión de tenerlo:
#
#   1. Verifica con `pg_restore --list` **y** comprueba que el volcado
#      contiene `stock_movements` con datos. Un `pg_dump` que falla a mitad
#      produce un fichero que `--list` acepta y al que le faltan tablas.
#   2. No excluye `tag_reads` sin más: excluye solo las particiones de más de
#      30 días, que son las que ya están en frío. Excluir el mes en curso
#      dejaría sin respaldo las lecturas que aún no se han exportado.
#   3. Escribe un fichero de estado con la hora y el tamaño del último
#      respaldo correcto, para que Prometheus pueda alertar de su ausencia.
#      Un respaldo que deja de ejecutarse en silencio es lo habitual.
#
# Uso:
#   BACKUP_ONCE=1 scripts/backup.sh     # una vez y sale; es lo que usa CI
#   scripts/backup.sh                   # bucle diario, para el contenedor

set -euo pipefail

: "${POSTGRES_USER:?falta POSTGRES_USER}"
: "${POSTGRES_DB:?falta POSTGRES_DB}"

PGHOST="${PGHOST:-postgres}"
BACKUP_DIR="${BACKUP_DIR:-/backups}"
KEEP_DAYS="${KEEP_DAYS:-7}"
STATE_FILE="${STATE_FILE:-${BACKUP_DIR}/last-backup.prom}"
COLD_AFTER_DAYS="${COLD_AFTER_DAYS:-30}"

log() { echo "[backup] $(date -Iseconds) $*"; }

# Particiones de tag_reads ya exportadas a frío. Solo esas se excluyen.
excluded_partitions() {
  local cutoff
  cutoff=$(date -u -d "-${COLD_AFTER_DAYS} days" +%Y_%m 2>/dev/null || date -u -v-"${COLD_AFTER_DAYS}"d +%Y_%m)

  psql --host="$PGHOST" --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" -tAc "
    SELECT c.relname
      FROM pg_class c
      JOIN pg_inherits i ON i.inhrelid = c.oid
      JOIN pg_class p ON p.oid = i.inhparent
     WHERE p.relname = 'tag_reads'
       AND c.relname ~ '^tag_reads_[0-9]{4}_[0-9]{2}\$'
       AND substring(c.relname from 11) < '${cutoff}'
  "
}

run_backup() {
  local stamp file args=()
  stamp=$(date +%Y%m%d_%H%M%S)
  file="${BACKUP_DIR}/traza_${stamp}.dump"

  mkdir -p "$BACKUP_DIR"

  while IFS= read -r partition; do
    [ -n "$partition" ] && args+=(--exclude-table-data="$partition")
  done < <(excluded_partitions)

  log "iniciando respaldo ${stamp} (${#args[@]} particiones frías excluidas)"

  # --format=custom permite restauración selectiva de tablas.
  pg_dump --host="$PGHOST" --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" \
          --format=custom --compress=9 --file="$file" "${args[@]}"

  # Un respaldo no verificado no es un respaldo.
  if ! pg_restore --list "$file" > /dev/null 2>&1; then
    log "CORRUPTO: pg_restore --list falló sobre ${file}"
    return 1
  fi

  # `--list` pasa aunque el volcado esté truncado justo después de la
  # cabecera. La tabla irremplazable tiene que estar sí o sí.
  if ! pg_restore --list "$file" | grep -q 'TABLE DATA public stock_movements'; then
    log "CORRUPTO: el volcado no contiene datos de stock_movements"
    return 1
  fi

  local size
  size=$(stat -c %s "$file" 2>/dev/null || stat -f %z "$file")

  if [ -n "${BACKUP_BUCKET:-}" ]; then
    aws s3 cp "$file" "s3://${BACKUP_BUCKET}/daily/" \
      ${AWS_ENDPOINT:+--endpoint-url "$AWS_ENDPOINT"}
    log "copia externa subida a s3://${BACKUP_BUCKET}/daily/"
  else
    log "sin BACKUP_BUCKET: solo copia local. La regla 3-2-1 no se cumple."
  fi

  find "$BACKUP_DIR" -name 'traza_*.dump' -mtime "+${KEEP_DAYS}" -delete

  # Para el node_exporter en modo textfile: alerta si deja de actualizarse.
  cat > "$STATE_FILE" <<EOF
# HELP traza_backup_last_success_timestamp Marca de tiempo del último respaldo verificado.
# TYPE traza_backup_last_success_timestamp gauge
traza_backup_last_success_timestamp $(date +%s)
# HELP traza_backup_last_size_bytes Tamaño del último respaldo verificado.
# TYPE traza_backup_last_size_bytes gauge
traza_backup_last_size_bytes ${size}
EOF

  log "completado: $(du -h "$file" | cut -f1)"
}

if [ -n "${BACKUP_ONCE:-}" ]; then
  run_backup
  exit $?
fi

while true; do
  run_backup || log "el respaldo falló; se reintenta en el siguiente ciclo"
  sleep "${BACKUP_INTERVAL:-86400}"
done
