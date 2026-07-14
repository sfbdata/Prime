#!/bin/bash
# =============================================================================
# JusPrime — Sincronização periódica Google Drive <-> sistema (Fase 1b)
# =============================================================================
# Chamado pelo cron da VPS (ex.: */15 * * * *). Roda o reconcile bidirecional
# de pastas+arquivos do tenant 1 dentro do container de produção.
#
# - Credenciais OAuth ficam em /opt/jusprime/.sync-oauth.env (FORA do git,
#   chmod 600). Ver .sync-oauth.env.example.
# - flock: se já houver uma rodada em andamento, esta sai sem sobrepor
#   (o reconcile também tem trava interna; esta evita até spawnar o docker exec).
# - Idempotente por drive_file_id/drive_folder_id: pode rodar repetido sem
#   duplicar. Se cair (ex.: timeout transitório da API), a próxima rodada retoma.
#
# Detalhes e histórico: docs/specs/sincronizacao-drive-bidirecional.md §21.
# =============================================================================
set -uo pipefail

CREDS="/opt/jusprime/.sync-oauth.env"
LOG="/var/log/jusprime-sync.log"
CONTAINER="jusprime_php_prod"

exec 9>/tmp/jusprime-sync-cron.lock
flock -n 9 || { echo "$(date '+%F %T') ja rodando, pulando" >> "$LOG"; exit 0; }

if [[ ! -r "$CREDS" ]]; then
    echo "$(date '+%F %T') ERRO: credenciais nao encontradas em $CREDS" >> "$LOG"
    exit 1
fi
set -a; . "$CREDS"; set +a

echo "$(date '+%F %T') === iniciando reconcile ===" >> "$LOG"
docker exec \
    -e GOOGLE_DRIVE_OAUTH_CLIENT_ID -e GOOGLE_DRIVE_OAUTH_CLIENT_SECRET \
    -e GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN -e GOOGLE_DRIVE_SHARED_DRIVE_ID \
    -w /var/www/app "$CONTAINER" \
    php bin/console app:sync:reconciliar --tenant-id=1 --usuario-id=1 >> "$LOG" 2>&1
rc=$?
echo "$(date '+%F %T') === fim (exit $rc) ===" >> "$LOG"
exit "$rc"
