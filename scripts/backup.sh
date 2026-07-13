#!/bin/bash
# =============================================================================
# JusPrime — Script de Backup de Produção
# =============================================================================
# O que faz:
#   1. Dump do PostgreSQL (pg_dump via container Docker) → jusprime_<TS>.tar.gz
#   2. Backup dos uploads (volume Docker) → uploads_<TS>.tar (arquivo SEPARADO,
#      grande; gerado direto do volume, com retenção própria KEEP_UPLOADS)
#   3. Rotação automática independente: KEEP_BACKUPS (banco) e KEEP_UPLOADS (uploads)
#
# Uso:
#   ./scripts/backup.sh
#
# Agendamento recomendado (crontab na VPS):
#   0 2 * * * /caminho/para/jusprime/scripts/backup.sh >> /var/log/jusprime-backup.log 2>&1
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuração — ajuste conforme necessário
# ---------------------------------------------------------------------------

# Diretório raiz do projeto (calculado automaticamente)
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Diretório onde os backups serão salvos
BACKUP_DIR="${BACKUP_DIR:-/var/backups/jusprime}"

# Quantos backups do BANCO manter (rotação — arquivo pequeno)
KEEP_BACKUPS="${KEEP_BACKUPS:-7}"

# Backup de uploads LOCAL: desligado por padrão. Os arquivos já são espelhados no Google Drive
# pelo sync (App\Sync), então a cópia local (~11GB+ e crescendo) é redundante e ENCHE o disco da
# VPS. Ligue com BACKUP_UPLOADS=1 só se tiver disco sobrando. Para backup offsite, prefira mandar
# direto pra um remoto (rclone) sem passar pelo disco.
BACKUP_UPLOADS="${BACKUP_UPLOADS:-0}"

# Quantos backups de UPLOADS manter, se BACKUP_UPLOADS=1 (arquivo grande — cuidado com o disco)
KEEP_UPLOADS="${KEEP_UPLOADS:-1}"

# Nome do container do banco de dados
DB_CONTAINER="jusprime_db_prod"

# Nome do volume Docker com os uploads
UPLOADS_VOLUME="jusprime_uploads_prod"

# Prefixo do arquivo de backup
BACKUP_PREFIX="jusprime"

# ---------------------------------------------------------------------------
# Variáveis internas
# ---------------------------------------------------------------------------

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_NAME="${BACKUP_PREFIX}_${TIMESTAMP}"
TEMP_DIR=$(mktemp -d)
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')] [BACKUP]"

# ---------------------------------------------------------------------------
# Funções auxiliares
# ---------------------------------------------------------------------------

log()  { echo "${LOG_PREFIX} $*"; }
error(){ echo "${LOG_PREFIX} [ERRO] $*" >&2; }

cleanup() {
    log "Limpando arquivos temporários..."
    rm -rf "${TEMP_DIR}"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Pré-verificações
# ---------------------------------------------------------------------------

log "Iniciando backup do JusPrime..."
log "Projeto: ${PROJECT_DIR}"
log "Destino: ${BACKUP_DIR}"

# Garante que o diretório de backup existe
mkdir -p "${BACKUP_DIR}"

# Carrega variáveis do .env.prod para ter POSTGRES_USER, POSTGRES_PASSWORD, POSTGRES_DB
if [[ ! -f "${PROJECT_DIR}/.env.prod" ]]; then
    error "Arquivo .env.prod não encontrado em ${PROJECT_DIR}"
    exit 1
fi

# Extrai apenas as variáveis necessárias sem exportar o .env inteiro
POSTGRES_USER=$(grep -E '^POSTGRES_USER=' "${PROJECT_DIR}/.env.prod" | cut -d= -f2- | tr -d '"'"'" | tr -d '[:space:]')
POSTGRES_PASSWORD=$(grep -E '^POSTGRES_PASSWORD=' "${PROJECT_DIR}/.env.prod" | cut -d= -f2- | tr -d '"'"'")
POSTGRES_DB=$(grep -E '^POSTGRES_DB=' "${PROJECT_DIR}/.env.prod" | cut -d= -f2- | tr -d '"'"'" | tr -d '[:space:]')

if [[ -z "${POSTGRES_USER}" || -z "${POSTGRES_PASSWORD}" || -z "${POSTGRES_DB}" ]]; then
    error "Não foi possível ler as variáveis do banco no .env.prod"
    exit 1
fi

# Verifica se o container do banco está rodando
if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
    error "Container '${DB_CONTAINER}' não está em execução."
    exit 1
fi

# ---------------------------------------------------------------------------
# 1. Backup do banco de dados (pg_dump)
# ---------------------------------------------------------------------------

log "Fazendo dump do PostgreSQL (banco: ${POSTGRES_DB})..."

DB_DUMP_FILE="${TEMP_DIR}/database.sql.gz"

docker exec "${DB_CONTAINER}" \
    env PGPASSWORD="${POSTGRES_PASSWORD}" \
    pg_dump -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" --no-password \
    | gzip -9 > "${DB_DUMP_FILE}"

DB_SIZE=$(du -sh "${DB_DUMP_FILE}" | cut -f1)
log "Dump concluído (${DB_SIZE} comprimido)."

# ---------------------------------------------------------------------------
# 2. Backup dos uploads — arquivo SEPARADO do banco (uploads_<TS>.tar)
# ---------------------------------------------------------------------------
# Uploads é grande (~11GB) e cresce: NÃO cabe no tarball do banco rotacionado
# em 7x (7 x 11GB estouraria o disco). Fica num tar próprio, gerado DIRETO do
# volume montado read-only (sem cópia intermediária no /tmp, que gastaria +11GB),
# escrito no BACKUP_DIR e com retenção própria (KEEP_UPLOADS). Sem gzip: o acervo
# é PDF/imagem (já comprimido) — gzip só gastaria CPU sem reduzir tamanho.

if [[ "${BACKUP_UPLOADS}" == "1" ]]; then
    UPLOADS_BACKUP="${BACKUP_DIR}/uploads_${TIMESTAMP}.tar"
    log "Arquivando uploads do volume '${UPLOADS_VOLUME}' direto para ${UPLOADS_BACKUP} ..."

    # tar direto do volume (read-only) para o diretório de backup (montado no container).
    docker run --rm \
        -v "${UPLOADS_VOLUME}:/source:ro" \
        -v "${BACKUP_DIR}:/backup" \
        alpine sh -c "tar -cf '/backup/uploads_${TIMESTAMP}.tar' -C /source ."

    UPLOADS_COUNT=$(docker run --rm -v "${UPLOADS_VOLUME}:/source:ro" alpine sh -c 'find /source -type f | wc -l')
    UPLOADS_SIZE=$(du -sh "${UPLOADS_BACKUP}" | cut -f1)
    log "Uploads arquivados: ${UPLOADS_COUNT} arquivo(s), ${UPLOADS_SIZE}."

    # Rotação própria dos backups de uploads (retenção KEEP_UPLOADS).
    log "Aplicando rotação dos uploads (mantendo os últimos ${KEEP_UPLOADS})..."
    find "${BACKUP_DIR}" -maxdepth 1 -name "uploads_*.tar" \
        | sort \
        | head -n "-${KEEP_UPLOADS}" \
        | xargs -r rm -v
else
    UPLOADS_COUNT="n/d"
    UPLOADS_SIZE="desligado"
    log "Backup de uploads LOCAL desligado (BACKUP_UPLOADS=0) — arquivos protegidos pelo espelho no Google Drive."
fi

# ---------------------------------------------------------------------------
# 3. Cria o arquivo de backup final
# ---------------------------------------------------------------------------

log "Comprimindo backup em ${BACKUP_DIR}/${BACKUP_NAME}.tar.gz ..."

# Salva metadados do backup
cat > "${TEMP_DIR}/backup_info.txt" <<EOF
JusPrime Backup
===============
Data/Hora : ${TIMESTAMP}
Banco     : ${POSTGRES_DB}
Usuário DB: ${POSTGRES_USER}
Uploads   : arquivo separado uploads_${TIMESTAMP}.tar (${UPLOADS_COUNT} arquivo(s), ${UPLOADS_SIZE})
Gerado por: $(hostname)
EOF

# Este tarball leva SÓ o banco + metadados (pequeno, rotação de 7). Os uploads vão
# no arquivo próprio uploads_<TS>.tar (seção 2), com retenção separada.
tar -czf "${BACKUP_DIR}/${BACKUP_NAME}.tar.gz" \
    -C "${TEMP_DIR}" \
    database.sql.gz \
    backup_info.txt

FINAL_SIZE=$(du -sh "${BACKUP_DIR}/${BACKUP_NAME}.tar.gz" | cut -f1)
log "Backup criado: ${BACKUP_DIR}/${BACKUP_NAME}.tar.gz (${FINAL_SIZE})"

# ---------------------------------------------------------------------------
# 4. Rotação — remove backups antigos
# ---------------------------------------------------------------------------

log "Aplicando rotação (mantendo os últimos ${KEEP_BACKUPS} backups)..."

TOTAL_BEFORE=$(find "${BACKUP_DIR}" -maxdepth 1 -name "${BACKUP_PREFIX}_*.tar.gz" | wc -l)

find "${BACKUP_DIR}" -maxdepth 1 -name "${BACKUP_PREFIX}_*.tar.gz" \
    | sort \
    | head -n "-${KEEP_BACKUPS}" \
    | xargs -r rm -v

TOTAL_AFTER=$(find "${BACKUP_DIR}" -maxdepth 1 -name "${BACKUP_PREFIX}_*.tar.gz" | wc -l)
REMOVED=$(( TOTAL_BEFORE - TOTAL_AFTER ))

if [[ ${REMOVED} -gt 0 ]]; then
    log "${REMOVED} backup(s) antigo(s) removido(s). Total atual: ${TOTAL_AFTER}."
else
    log "Nenhum backup antigo para remover. Total: ${TOTAL_AFTER}."
fi

# ---------------------------------------------------------------------------
# Concluído
# ---------------------------------------------------------------------------

log "Backup finalizado com sucesso."
