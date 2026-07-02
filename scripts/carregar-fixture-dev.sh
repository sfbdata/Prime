#!/bin/bash
# =============================================================================
# JusPrime — Carrega um snapshot de PRODUÇÃO como fixture do ambiente DEV
# =============================================================================
# O que faz:
#   1. Extrai o dump do banco de um backup gerado por scripts/backup.sh
#      (tarball jusprime_*.tar.gz contendo database.sql.gz), ou aceita
#      direto um .sql.gz / .sql.
#   2. Derruba conexões, DROP + CREATE do banco de dev (destrutivo NO DEV).
#   3. Carrega o dump no container jusprime_db_dev.
#   4. Roda as migrations pendentes (caso o dev esteja à frente de prod).
#   5. Limpa o cache do Symfony.
#
# Uso:
#   ./scripts/carregar-fixture-dev.sh [caminho-do-backup]
#
# Se o caminho for omitido, usa o snapshot fixo em:
#   fixtures/prod-snapshot.tar.gz   (gitignored — nunca versionar; contém PII)
#
# Exemplos:
#   ./scripts/carregar-fixture-dev.sh
#   ./scripts/carregar-fixture-dev.sh ~/Downloads/jusprime_20260702_020000.tar.gz
#
# ATENÇÃO — cópia CRUA de produção: os dados de cliente (PII, hashes de senha)
# ficam no seu banco de dev local. Não é anonimizado. Trate o disco como
# sensível e nunca suba o dump pro git.
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuração — ambiente DEV
# ---------------------------------------------------------------------------

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DB_CONTAINER="jusprime_db_dev"
PHP_CONTAINER="jusprime_php_dev"
DB_USER="${POSTGRES_USER:-symfony}"
DB_NAME="${POSTGRES_DB:-saas}"

DEFAULT_FIXTURE="${PROJECT_DIR}/fixtures/prod-snapshot.tar.gz"
BACKUP_FILE="${1:-${DEFAULT_FIXTURE}}"

TEMP_DIR=$(mktemp -d)
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')] [FIXTURE-DEV]"

log()   { echo "${LOG_PREFIX} $*"; }
error() { echo "${LOG_PREFIX} [ERRO] $*" >&2; }
cleanup() { rm -rf "${TEMP_DIR}"; }
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Pré-verificações
# ---------------------------------------------------------------------------

if [[ ! -f "${BACKUP_FILE}" ]]; then
    error "Arquivo não encontrado: ${BACKUP_FILE}"
    echo "  Passe o caminho do backup ou coloque o snapshot em ${DEFAULT_FIXTURE}"
    exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
    error "Container '${DB_CONTAINER}' não está em execução. Suba o ambiente dev primeiro."
    exit 1
fi

# ---------------------------------------------------------------------------
# Confirmação obrigatória (destrutivo no DEV)
# ---------------------------------------------------------------------------

echo ""
echo "  Isso vai APAGAR e recriar o banco '${DB_NAME}' do DEV (${DB_CONTAINER})"
echo "  e carregar o snapshot de produção:"
echo "    ${BACKUP_FILE}"
echo ""
read -rp "  Digite 'CONFIRMO' para continuar: " CONFIRM
if [[ "${CONFIRM}" != "CONFIRMO" ]]; then
    echo "Operação cancelada."
    exit 0
fi

# ---------------------------------------------------------------------------
# 1. Localiza o dump SQL (aceita .tar.gz do backup.sh, .sql.gz ou .sql)
# ---------------------------------------------------------------------------

# Role dono dos objetos no dump de prod (ex.: 'jusprime'). O dev usa outro
# (ex.: 'symfony'), então precisamos criar esse role antes de carregar e
# depois reatribuir tudo para o usuário do dev.
PROD_ROLE=""

case "${BACKUP_FILE}" in
    *.tar.gz)
        log "Extraindo dump do tarball..."
        tar -xzf "${BACKUP_FILE}" -C "${TEMP_DIR}"
        if [[ -f "${TEMP_DIR}/backup_info.txt" ]]; then
            echo ""; cat "${TEMP_DIR}/backup_info.txt"; echo ""
            PROD_ROLE=$(grep -E '^Usuário DB:' "${TEMP_DIR}/backup_info.txt" | cut -d: -f2- | tr -d '[:space:]')
        fi
        if [[ -f "${TEMP_DIR}/database.sql.gz" ]]; then
            SQL_READER="zcat ${TEMP_DIR}/database.sql.gz"
        elif [[ -f "${TEMP_DIR}/database.sql" ]]; then
            SQL_READER="cat ${TEMP_DIR}/database.sql"
        else
            error "Não encontrei database.sql(.gz) dentro do tarball."
            exit 1
        fi
        ;;
    *.sql.gz)
        SQL_READER="zcat ${BACKUP_FILE}"
        ;;
    *.sql)
        SQL_READER="cat ${BACKUP_FILE}"
        ;;
    *)
        error "Formato não reconhecido: ${BACKUP_FILE} (esperado .tar.gz, .sql.gz ou .sql)"
        exit 1
        ;;
esac

# ---------------------------------------------------------------------------
# 2. Derruba conexões e recria o banco do DEV
# ---------------------------------------------------------------------------

log "Encerrando conexões ativas ao banco '${DB_NAME}'..."
docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d postgres -c \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${DB_NAME}' AND pid <> pg_backend_pid();" \
    > /dev/null

log "Recriando o banco '${DB_NAME}'..."
docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d postgres -c \
    "DROP DATABASE IF EXISTS \"${DB_NAME}\";" > /dev/null
docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d postgres -c \
    "CREATE DATABASE \"${DB_NAME}\" OWNER \"${DB_USER}\";" > /dev/null

# ---------------------------------------------------------------------------
# 3. Garante que o role dono do dump exista no DEV
# ---------------------------------------------------------------------------

# Fallback: se não veio no backup_info, detecta o dono pelo próprio dump.
if [[ -z "${PROD_ROLE}" ]]; then
    PROD_ROLE=$(${SQL_READER} 2>/dev/null | grep -m1 -oE 'OWNER TO [A-Za-z0-9_]+' | awk '{print $3}' || true)
fi

if [[ -n "${PROD_ROLE}" && "${PROD_ROLE}" != "${DB_USER}" ]]; then
    log "Criando role '${PROD_ROLE}' (dono dos objetos no dump de prod)..."
    docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d "${DB_NAME}" -c \
        "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='${PROD_ROLE}') THEN CREATE ROLE \"${PROD_ROLE}\"; END IF; END \$\$;" \
        > /dev/null
fi

# ---------------------------------------------------------------------------
# 4. Carrega o dump
# ---------------------------------------------------------------------------

log "Carregando o snapshot no DEV (pode demorar conforme o tamanho)..."
${SQL_READER} | docker exec -i "${DB_CONTAINER}" \
    psql -U "${DB_USER}" -d "${DB_NAME}" --quiet --set ON_ERROR_STOP=on

log "Snapshot carregado."

# ---------------------------------------------------------------------------
# 4b. Reatribui a posse ao usuário do DEV e remove o role de prod
# ---------------------------------------------------------------------------

if [[ -n "${PROD_ROLE}" && "${PROD_ROLE}" != "${DB_USER}" ]]; then
    log "Reatribuindo posse de '${PROD_ROLE}' para '${DB_USER}' e removendo o role..."
    docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d "${DB_NAME}" -c \
        "REASSIGN OWNED BY \"${PROD_ROLE}\" TO \"${DB_USER}\";" > /dev/null
    docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d "${DB_NAME}" -c \
        "DROP OWNED BY \"${PROD_ROLE}\";" > /dev/null
    docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d postgres -c \
        "DROP ROLE IF EXISTS \"${PROD_ROLE}\";" > /dev/null
fi

# ---------------------------------------------------------------------------
# 5. Migrations pendentes (dev pode estar à frente de prod)
# ---------------------------------------------------------------------------

log "Aplicando migrations pendentes (se houver)..."
docker exec "${PHP_CONTAINER}" bash -c \
    'cd app && php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'

# ---------------------------------------------------------------------------
# 6. Limpa o cache
# ---------------------------------------------------------------------------

log "Limpando cache do Symfony..."
docker exec "${PHP_CONTAINER}" bash -c 'cd app && php bin/console cache:clear' > /dev/null

# ---------------------------------------------------------------------------
# Concluído
# ---------------------------------------------------------------------------

log "Fixture de produção carregado no DEV com sucesso."
echo ""
echo "  Observações:"
echo "   • Uploads (fotos, documentos) NÃO vêm no dump — linhas do banco podem"
echo "     apontar para arquivos inexistentes em app/public/uploads/."
echo "   • Cópia crua: os hashes de senha são os de produção. Para entrar com"
echo "     uma senha conhecida, crie/atualize um super-admin no dev:"
echo "       docker exec -it ${PHP_CONTAINER} bash -c \\"
echo "         'cd app && php bin/console app:create-super-admin admin@prime.com Prime123!'"
