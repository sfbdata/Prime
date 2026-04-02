#!/bin/bash
# =============================================================================
# JusPrime — Script de Restauração de Backup
# =============================================================================
# Uso:
#   ./scripts/restore.sh <arquivo_de_backup.tar.gz>
#
# Exemplos:
#   ./scripts/restore.sh /var/backups/jusprime/jusprime_20240101_020000.tar.gz
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Argumentos
# ---------------------------------------------------------------------------

if [[ $# -lt 1 ]]; then
    echo "Uso: $0 <arquivo_de_backup.tar.gz>"
    echo ""
    echo "Exemplos de backups disponíveis em /var/backups/jusprime/:"
    ls -1t /var/backups/jusprime/*.tar.gz 2>/dev/null || echo "  (nenhum encontrado)"
    exit 1
fi

BACKUP_FILE="$1"

if [[ ! -f "${BACKUP_FILE}" ]]; then
    echo "[ERRO] Arquivo não encontrado: ${BACKUP_FILE}"
    exit 1
fi

# ---------------------------------------------------------------------------
# Configuração
# ---------------------------------------------------------------------------

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_CONTAINER="jusprime_db_prod"
UPLOADS_VOLUME="jusprime_uploads_prod"
TEMP_DIR=$(mktemp -d)
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')] [RESTORE]"

log()  { echo "${LOG_PREFIX} $*"; }
error(){ echo "${LOG_PREFIX} [ERRO] $*" >&2; }

cleanup() { rm -rf "${TEMP_DIR}"; }
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Confirmação obrigatória
# ---------------------------------------------------------------------------

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║              ATENÇÃO — OPERAÇÃO DESTRUTIVA                   ║"
echo "╠══════════════════════════════════════════════════════════════╣"
echo "║  Isso irá SUBSTITUIR completamente:                          ║"
echo "║    • O banco de dados de produção                            ║"
echo "║    • Todos os arquivos de upload                             ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
echo "  Arquivo de backup: ${BACKUP_FILE}"
echo ""
read -rp "  Digite 'CONFIRMO' para continuar: " CONFIRM

if [[ "${CONFIRM}" != "CONFIRMO" ]]; then
    echo "Operação cancelada."
    exit 0
fi

# ---------------------------------------------------------------------------
# Extrai o backup
# ---------------------------------------------------------------------------

log "Extraindo backup em ${TEMP_DIR}..."
tar -xzf "${BACKUP_FILE}" -C "${TEMP_DIR}"

# Exibe metadados
if [[ -f "${TEMP_DIR}/backup_info.txt" ]]; then
    echo ""
    cat "${TEMP_DIR}/backup_info.txt"
    echo ""
fi

# ---------------------------------------------------------------------------
# Lê credenciais do .env.prod
# ---------------------------------------------------------------------------

POSTGRES_USER=$(grep -E '^POSTGRES_USER=' "${PROJECT_DIR}/.env.prod" | cut -d= -f2- | tr -d '"'"'" | tr -d '[:space:]')
POSTGRES_PASSWORD=$(grep -E '^POSTGRES_PASSWORD=' "${PROJECT_DIR}/.env.prod" | cut -d= -f2- | tr -d '"'"'")
POSTGRES_DB=$(grep -E '^POSTGRES_DB=' "${PROJECT_DIR}/.env.prod" | cut -d= -f2- | tr -d '"'"'" | tr -d '[:space:]')

# ---------------------------------------------------------------------------
# 1. Restaura o banco de dados
# ---------------------------------------------------------------------------

log "Restaurando banco de dados (${POSTGRES_DB})..."

if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
    error "Container '${DB_CONTAINER}' não está em execução."
    exit 1
fi

# Encerra conexões ativas e recria o banco
docker exec "${DB_CONTAINER}" \
    env PGPASSWORD="${POSTGRES_PASSWORD}" \
    psql -U "${POSTGRES_USER}" -d postgres -c \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${POSTGRES_DB}' AND pid <> pg_backend_pid();" \
    > /dev/null

docker exec "${DB_CONTAINER}" \
    env PGPASSWORD="${POSTGRES_PASSWORD}" \
    psql -U "${POSTGRES_USER}" -d postgres -c \
    "DROP DATABASE IF EXISTS \"${POSTGRES_DB}\";" \
    > /dev/null

docker exec "${DB_CONTAINER}" \
    env PGPASSWORD="${POSTGRES_PASSWORD}" \
    psql -U "${POSTGRES_USER}" -d postgres -c \
    "CREATE DATABASE \"${POSTGRES_DB}\" OWNER \"${POSTGRES_USER}\";" \
    > /dev/null

# Restaura o dump
zcat "${TEMP_DIR}/database.sql.gz" | docker exec -i "${DB_CONTAINER}" \
    env PGPASSWORD="${POSTGRES_PASSWORD}" \
    psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" --quiet

log "Banco de dados restaurado."

# ---------------------------------------------------------------------------
# 2. Restaura os uploads
# ---------------------------------------------------------------------------

log "Restaurando uploads no volume ${UPLOADS_VOLUME}..."

if [[ -d "${TEMP_DIR}/uploads" ]]; then
    docker run --rm \
        -v "${UPLOADS_VOLUME}:/dest" \
        -v "${TEMP_DIR}/uploads:/source:ro" \
        alpine sh -c "rm -rf /dest/* /dest/.[!.]* 2>/dev/null; cp -a /source/. /dest/"

    UPLOADS_COUNT=$(find "${TEMP_DIR}/uploads" -type f | wc -l)
    log "Uploads restaurados: ${UPLOADS_COUNT} arquivo(s)."
else
    log "Aviso: diretório de uploads não encontrado no backup, pulando."
fi

# ---------------------------------------------------------------------------
# Concluído
# ---------------------------------------------------------------------------

log "Restauração concluída com sucesso."
echo ""
echo "Recomendação: reinicie os containers para garantir estado limpo:"
echo "  cd ${PROJECT_DIR} && docker compose -f docker-compose.prod.yml restart"
