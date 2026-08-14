#!/usr/bin/env bash
#
# Restauración de un respaldo de TRAZA. Ver `docs/11` §6.
#
# **Un respaldo que nunca se ha restaurado no existe.** El calendario del
# documento es obligatorio: restauración completa sobre staging el primer
# lunes de cada mes, cronometrada. El objetivo (RTO) son 2 horas.
#
# Este guion cronometra solo. No decide si el tiempo es aceptable ni lo
# oculta: imprime el total y quien lo ejecuta lo apunta.
#
# Uso:
#   scripts/restore.sh /backups/traza_20260814_030000.dump traza_staging
#
# ⚠️ La base de destino se BORRA Y SE VUELVE A CREAR. El guion se niega a
#    tocar una base cuyo nombre no contenga «staging» o «test» salvo que se
#    pase --force, porque el error de teclear el nombre de producción aquí es
#    fácil de cometer y no tiene vuelta atrás.

set -euo pipefail

DUMP="${1:?uso: restore.sh <fichero.dump> <base_destino> [--force]}"
TARGET="${2:?uso: restore.sh <fichero.dump> <base_destino> [--force]}"
FORCE="${3:-}"

PGHOST="${PGHOST:-postgres}"
: "${POSTGRES_USER:?falta POSTGRES_USER}"

log() { echo "[restore] $(date -Iseconds) $*"; }

if [ ! -f "$DUMP" ]; then
  log "no existe $DUMP"
  exit 1
fi

if [[ "$TARGET" != *staging* && "$TARGET" != *test* && "$FORCE" != "--force" ]]; then
  log "«$TARGET» no parece una base de pruebas. Si de verdad quieres restaurar ahí, pasa --force."
  exit 1
fi

# Antes de borrar nada: comprobar que el volcado sirve. Restaurar sobre la
# base de staging destruye lo que hubiera, y hacerlo con un dump corrupto
# deja sin las dos cosas.
log "verificando $DUMP"
pg_restore --list "$DUMP" > /dev/null
pg_restore --list "$DUMP" | grep -q 'TABLE DATA public stock_movements' \
  || { log "el volcado no contiene datos de stock_movements"; exit 1; }

STARTED=$(date +%s)

log "recreando $TARGET"
dropdb --host="$PGHOST" --username="$POSTGRES_USER" --if-exists "$TARGET"
createdb --host="$PGHOST" --username="$POSTGRES_USER" "$TARGET"

log "restaurando"
# `--jobs` acelera mucho en un dump grande. `--exit-on-error` no: un error a
# mitad deja la base incompleta y en silencio.
pg_restore --host="$PGHOST" --username="$POSTGRES_USER" --dbname="$TARGET" \
           --jobs="${RESTORE_JOBS:-4}" --exit-on-error "$DUMP"

ELAPSED=$(( $(date +%s) - STARTED ))

log "comprobando integridad de lo restaurado"
psql --host="$PGHOST" --username="$POSTGRES_USER" --dbname="$TARGET" -tAc "
  SELECT 'movimientos=' || count(*) FROM stock_movements;
"
psql --host="$PGHOST" --username="$POSTGRES_USER" --dbname="$TARGET" -tAc "
  SELECT 'tags=' || count(*) FROM tags;
"

printf '[restore] TIEMPO TOTAL: %02d:%02d:%02d (RTO objetivo 02:00:00)\n' \
  $((ELAPSED/3600)) $((ELAPSED%3600/60)) $((ELAPSED%60))

if [ "$ELAPSED" -gt 7200 ]; then
  log "por encima del RTO de 2 h: hay que replantear la estrategia de respaldo"
  exit 2
fi
