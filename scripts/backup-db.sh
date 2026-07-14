#!/bin/bash
# =============================================================================
# JusPrime — Backup diário do BANCO de produção (cron ex.: 30 2 * * *)
# =============================================================================
# Só o banco (pequeno, ~alguns MB). O backup de ARQUIVOS/uploads fica
# DESLIGADO por decisão: é redundante — os arquivos já estão no sistema E no
# Google Drive (sync bidirecional). Um tar diário dos uploads (~12GB+ e
# crescendo) enchia o disco da VPS (ver docs/specs/...drive... §21, incidente
# 2026-07-13). Se um dia quiser backup de arquivos, mande OFFSITE (rclone),
# nunca um tar local rotacionado.
#
# Usa as env vars do próprio container do Postgres (POSTGRES_*), então não
# precisa saber a senha aqui.
# =============================================================================
set -uo pipefail

BACKUP_DIR="/var/backups/jusprime"
KEEP=7
LOG="/var/log/jusprime-dbbackup.log"
CONTAINER="jusprime_db_prod"

TS=$(date +%Y%m%d_%H%M%S)
OUT="$BACKUP_DIR/db_${TS}.sql.gz"
mkdir -p "$BACKUP_DIR"

docker exec "$CONTAINER" sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' | gzip -9 > "$OUT"

# Rotação: mantém os KEEP mais recentes.
ls -1t "$BACKUP_DIR"/db_*.sql.gz 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f

echo "$(date '+%F %T') db backup -> $OUT ($(du -h "$OUT" | cut -f1))" >> "$LOG"
