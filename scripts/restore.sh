#!/bin/bash
# =============================================================================
# BlueJus: restauração segura de backup
# =============================================================================
# Formatos aceitos:
#   jusprime_<TS>.tar.gz              atual: database.sql.gz + backup_info.txt; os uploads,
#                                     quando existem, ficam no irmão uploads_<TS>.tar
#   <qualquer>.tar.gz com uploads/    histórico (até a ed05def3): uploads embutidos
#   jusprime-pre-vX.Y.Z-<data>.dump   dump do deploy automático (pg_dump -Fc); só banco
#
# Uso:
#   restore.sh [--database-only] <backup.tar.gz> [uploads.tar]
#   restore.sh <jusprime-pre-vX.Y.Z-....dump>
#
# Pré-requisitos (o script recusa sem eles): worker parado; php parado quando há uploads;
# nenhum deploy em andamento (mesma trava do scripts/vps/deploy-automatico.sh).
#
# Garantias:
#   - Tudo é conferido ANTES de tocar no que está no ar: nomes do TAR (sem ../, sem caminho
#     absoluto, sem link), gzip íntegro, espaço em disco, dump restaurado por inteiro num banco
#     descartável (com pelo menos uma tabela), uploads extraídos e contados num volume descartável.
#   - Permissões no nível do banco (GRANT ... ON DATABASE, ALTER DATABASE/ROLE ... SET) do banco
#     no ar são replicadas no banco novo, que o pg_dump não leva.
#   - O banco anterior NUNCA é apagado: fica como <banco>_before_restore_<pid>. O script imprime
#     no fim os comandos (já preenchidos) para desfazer e para apagá-lo.
#   - A troca dos nomes do banco é uma transação só (ou troca tudo, ou nada).
#   - Se algo falhar depois de os uploads terem sido trocados e a troca do banco NÃO foi gravada,
#     o trap devolve os uploads anteriores (cópia conferida por checksum).
#
# Runbook: docs/runbooks/restaurar-backup.md
# =============================================================================
set -euo pipefail

usage() {
    echo 'Uso: restore.sh [--database-only] <backup.tar.gz> [uploads.tar]'
    echo '     restore.sh <jusprime-pre-vX.Y.Z-....dump>'
}

DATABASE_ONLY=0
if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then usage; exit 0; fi
if [[ "${1:-}" == "--database-only" ]]; then DATABASE_ONLY=1; shift; fi
if [[ $# -lt 1 || $# -gt 2 ]]; then usage >&2; exit 2; fi

BACKUP_FILE="$1"
UPLOADS_FILE="${2:-}"
[[ -f "$BACKUP_FILE" ]] || { echo "[RESTORE] [ERRO] Backup não encontrado: $BACKUP_FILE" >&2; exit 1; }

FORMATO=tar
if [[ "$BACKUP_FILE" == *.dump ]]; then
    FORMATO=dump
    DATABASE_ONLY=1
fi
if [[ $DATABASE_ONLY -eq 1 && -n "$UPLOADS_FILE" ]]; then
    echo '[RESTORE] [ERRO] Não informe uploads.tar junto com --database-only (ou com um .dump).' >&2
    exit 2
fi

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_CONTAINER="${DB_CONTAINER:-jusprime_db_prod}"
WORKER_CONTAINER="${WORKER_CONTAINER:-jusprime_worker_prod}"
APP_CONTAINER="${APP_CONTAINER:-jusprime_php_prod}"
UPLOADS_VOLUME="${UPLOADS_VOLUME:-jusprime_uploads_prod}"
ENV_FILE="${ENV_FILE:-${PROJECT_DIR}/.env.prod}"
TRAVA="${JUSPRIME_TRAVA_DEPLOY:-/var/lock/jusprime-deploy.lock}"

log()   { echo "[RESTORE] $*"; }
aviso() { echo "[RESTORE] [AVISO] $*" >&2; }
error() { echo "[RESTORE] [ERRO] $*" >&2; }

# Ganchos só para o teste de integração (scripts/testar-restore.sh). Valem apenas com
# RESTORE_MODO_TESTE=1 e fora do container de produção; em qualquer outro caso são ignorados.
INJETAR_FALHA=""
LIVRE_DOCKER_FORCADO=""
if [[ -n "${RESTORE_INJETAR_FALHA:-}${RESTORE_LIVRE_DOCKER_KB:-}" ]]; then
    if [[ "${RESTORE_MODO_TESTE:-}" == 1 && "$DB_CONTAINER" != jusprime_db_prod ]]; then
        INJETAR_FALHA="${RESTORE_INJETAR_FALHA:-}"
        LIVRE_DOCKER_FORCADO="${RESTORE_LIVRE_DOCKER_KB:-}"
    else
        aviso 'RESTORE_INJETAR_FALHA/RESTORE_LIVRE_DOCKER_KB ignorados (só valem no teste de integração).'
    fi
fi

TEMP_DIR=$(mktemp -d)
RUN_ID="bluejus_restore_${$}_$(date +%s)"
STAGING_VOLUME="${RUN_ID}_staging"
ROLLBACK_VOLUME="${RUN_ID}_rollback"
STAGING_DB=""
OLD_DB=""
POSTGRES_DB=""
STAGING_DB_CREATED=0
STAGING_CREATED=0
ROLLBACK_CREATED=0
ROLLBACK_FINGERPRINT=""
UPLOADS_CUTOVER_STARTED=0
DB_LOCKED=0
DB_SWAP_STARTED=0
RESTORE_OK=0

docker_volume_exists() { docker volume inspect "$1" >/dev/null 2>&1; }
container_running() { docker ps --format '{{.Names}}' | grep -Fxq "$1"; }
count_volume_files() {
    docker run --rm -v "$1:/source:ro" alpine sh -ec 'find /source -type f | wc -l'
}
volume_kb() {
    docker run --rm -v "$1:/source:ro" alpine sh -ec 'du -sk /source | cut -f1'
}
# Checksum agregado (caminho + conteúdo de cada arquivo). Os dois volumes são montados no mesmo
# ponto (/source), então os caminhos são comparáveis.
fingerprint_volume() {
    docker run --rm -v "$1:/source:ro" alpine sh -ec \
        'find /source -type f -exec sha256sum {} + | LC_ALL=C sort | sha256sum | cut -d" " -f1'
}
# Limpa o destino antes de copiar: o resultado é idêntico à origem, sem sobra do conteúdo anterior.
copy_volume() {
    docker run --rm -v "$1:/source:ro" -v "$2:/dest" alpine sh -ec \
        'rm -rf /dest/* /dest/.[!.]* /dest/..?* 2>/dev/null || true; cp -a /source/. /dest/'
}

# psql no container, lendo SQL do stdin. Usuário e senha vêm do próprio container (mesma
# convenção de scripts/vps/deploy-automatico.sh), então a senha não aparece na linha de comando
# do host. Nomes de banco entram como variáveis psql (:'x' literal, :"x" identificador), nunca
# concatenados no SQL.
psql_db() {
    local banco="$1"; shift
    docker exec -i "$DB_CONTAINER" sh -c \
        'b="$1"; shift; PGPASSWORD="$POSTGRES_PASSWORD" exec psql -U "$POSTGRES_USER" -X -q -At -v ON_ERROR_STOP=1 -d "$b" "$@"' \
        sh "$banco" "$@"
}
psql_admin() { psql_db postgres "$@"; }
db_exists() {
    [[ "$(psql_admin -v nome="$1" <<<"SELECT 1 FROM pg_database WHERE datname = :'nome';")" == 1 ]]
}
unlock_db() {
    psql_admin -v alvo="$POSTGRES_DB" <<<'ALTER DATABASE :"alvo" ALLOW_CONNECTIONS true;' >/dev/null
}

cleanup() {
    local rc=$?
    # Nada de interrupção no meio da limpeza: um segundo Ctrl-C deixaria o estado pela metade.
    trap '' INT TERM HUP
    set +e
    if [[ $DB_LOCKED -eq 1 && -n "$POSTGRES_DB" ]]; then
        # Reabre o banco que está no nome original (o antigo, ou o novo se a troca foi gravada).
        unlock_db || error "Não consegui reabrir conexões em '$POSTGRES_DB'. Rode: ALTER DATABASE \"$POSTGRES_DB\" ALLOW_CONNECTIONS true;"
    fi
    if [[ $UPLOADS_CUTOVER_STARTED -eq 1 && $RESTORE_OK -ne 1 && $ROLLBACK_CREATED -eq 1 ]]; then
        # Antes de devolver os uploads, confere o estado REAL do banco: se a troca foi gravada,
        # devolver deixaria banco novo com arquivos antigos.
        # trocado = antigo e alvo existem e o descartável sumiu; nao_trocado = o contrário exato;
        # qualquer outra combinação (ou consulta que falha) = desconhecido.
        local estado=nao_trocado achados
        if [[ $DB_SWAP_STARTED -eq 1 ]]; then
            estado=desconhecido
            if achados=$(psql_admin -v antigo="$OLD_DB" -v alvo="$POSTGRES_DB" -v novo="$STAGING_DB" <<'SQL'
SELECT (SELECT count(*) FROM pg_database WHERE datname = :'antigo') || ','
    || (SELECT count(*) FROM pg_database WHERE datname = :'alvo') || ','
    || (SELECT count(*) FROM pg_database WHERE datname = :'novo');
SQL
); then
                case "$achados" in
                    1,1,0) estado=trocado ;;
                    0,1,1) estado=nao_trocado ;;
                esac
            fi
        fi
        case "$estado" in
            trocado)
                psql_admin -v antigo="$OLD_DB" <<<'ALTER DATABASE :"antigo" ALLOW_CONNECTIONS true;' >/dev/null 2>&1
                error "A troca do banco FOI gravada: banco e uploads novos estão no ar; o anterior está em '$OLD_DB'."
                error "Uploads NÃO devolvidos. A cópia anterior continua no volume $ROLLBACK_VOLUME (apague depois de conferir)."
                ;;
            desconhecido)
                error 'Não consegui conferir se a troca do banco foi gravada; uploads NÃO devolvidos.'
                error "Confira à mão. Os uploads anteriores estão no volume $ROLLBACK_VOLUME: NÃO o apague."
                ;;
            *)
                error 'Falha durante a troca; devolvendo os uploads anteriores.'
                local devolvido=""
                if copy_volume "$ROLLBACK_VOLUME" "$UPLOADS_VOLUME"; then
                    devolvido=$(fingerprint_volume "$UPLOADS_VOLUME")
                fi
                if [[ -n "$devolvido" && "$devolvido" == "$ROLLBACK_FINGERPRINT" ]]; then
                    error 'Uploads anteriores devolvidos e conferidos por checksum. O banco continua o anterior.'
                    docker volume rm "$ROLLBACK_VOLUME" >/dev/null 2>&1
                else
                    error "ROLLBACK DOS UPLOADS FALHOU. NÃO apague o volume $ROLLBACK_VOLUME: ele tem a cópia anterior."
                fi
                ;;
        esac
        ROLLBACK_CREATED=0
    fi
    if [[ $STAGING_DB_CREATED -eq 1 ]]; then
        psql_admin -v nome="$STAGING_DB" <<<'DROP DATABASE IF EXISTS :"nome";' >/dev/null 2>&1
    fi
    [[ $STAGING_CREATED -eq 1 ]] && docker volume rm "$STAGING_VOLUME" >/dev/null 2>&1
    [[ $ROLLBACK_CREATED -eq 1 ]] && docker volume rm "$ROLLBACK_VOLUME" >/dev/null 2>&1
    rm -rf "$TEMP_DIR"
    if [[ $rc -ne 0 && $RESTORE_OK -ne 1 ]]; then
        error "Restauração NÃO concluída (código $rc)."
    fi
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

# Lista o TAR e recusa travessia, caminho absoluto, link e tipo especial. Deixa a lista de nomes
# em ARCHIVE_LISTING e a lista detalhada (tipo, dono, tamanho, ...) em ARCHIVE_VERBOSE.
validate_archive_names() {
    local archive="$1" compression="$2" listing verbose
    if [[ "$compression" == gz ]]; then
        listing=$(tar -tPzf "$archive") || { error "TAR ilegível ou corrompido: $archive"; return 1; }
        verbose=$(tar -tvPzf "$archive") || { error "TAR ilegível ou corrompido: $archive"; return 1; }
    else
        listing=$(tar -tPf "$archive") || { error "TAR ilegível ou corrompido: $archive"; return 1; }
        verbose=$(tar -tvPf "$archive") || { error "TAR ilegível ou corrompido: $archive"; return 1; }
    fi
    if awk 'BEGIN { bad=0 } /(^|\/)\.\.($|\/)|^\// { bad=1 } END { exit bad ? 0 : 1 }' <<<"$listing"; then
        error "TAR contém caminho absoluto ou travessia (..): $archive"
        return 1
    fi
    if awk 'substr($1, 1, 1) != "-" && substr($1, 1, 1) != "d" { bad=1 } END { exit bad ? 0 : 1 }' <<<"$verbose"; then
        error "TAR contém link ou tipo especial não permitido: $archive"
        return 1
    fi
    ARCHIVE_LISTING="$listing"
    ARCHIVE_VERBOSE="$verbose"
}

read_env_var() {
    # Mesmo tratamento do scripts/backup.sh: tira aspas simples e duplas.
    { grep -E "^$1=" "$ENV_FILE" || true; } | head -n1 | cut -d= -f2- | tr -d '"'"'"
}

livre_kb() { df -Pk "$1" | awk 'NR == 2 { print $4 }'; }
# Espaço livre no disco onde o docker guarda volumes (medido de dentro de um volume anônimo).
livre_docker_kb() {
    if [[ -n "$LIVRE_DOCKER_FORCADO" ]]; then echo "$LIVRE_DOCKER_FORCADO"; return; fi
    docker run --rm -v /medida alpine sh -ec 'df -Pk /medida | awk "NR == 2 { print \$4 }"'
}

# "tabelas|linhas aproximadas|linhas em \"user\"|linhas em tenant" de um banco.
resumo_banco() {
    psql_db "$1" <<'SQL'
SELECT
  (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE c.relkind IN ('r', 'p') AND n.nspname NOT IN ('pg_catalog', 'information_schema')
      AND n.nspname NOT LIKE 'pg\_toast%')
  || '|' ||
  (SELECT coalesce(sum(greatest(c.reltuples, 0)), 0)::bigint FROM pg_class c
     JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog', 'information_schema')
      AND n.nspname NOT LIKE 'pg\_toast%')
  || '|' ||
  coalesce(CASE WHEN to_regclass('public."user"') IS NOT NULL THEN
    (xpath('/row/c/text()', query_to_xml('SELECT count(*) AS c FROM public."user"', false, true, '')))[1]::text END, '-')
  || '|' ||
  coalesce(CASE WHEN to_regclass('public.tenant') IS NOT NULL THEN
    (xpath('/row/c/text()', query_to_xml('SELECT count(*) AS c FROM public.tenant', false, true, '')))[1]::text END, '-');
SQL
}

# Permissões do banco em forma comparável: ACL ordenada, dono e configurações por banco/role.
permissoes_banco() {
    psql_admin -v nome="$1" <<'SQL'
SELECT coalesce((SELECT string_agg(x::text, ',' ORDER BY x::text) FROM unnest(d.datacl) x), '<padrão>')
       || ' dono=' || pg_get_userbyid(d.datdba)
       || ' config=' || coalesce((SELECT string_agg(coalesce(r.rolname, '*') || ':' || s.setconfig::text, ';'
                                   ORDER BY coalesce(r.rolname, '*'))
                                  FROM pg_db_role_setting s LEFT JOIN pg_roles r ON r.oid = s.setrole
                                 WHERE s.setdatabase = d.oid), '')
  FROM pg_database d WHERE d.datname = :'nome';
SQL
}

checar_containers_parados() {
    if container_running "$WORKER_CONTAINER"; then
        error "O worker está rodando. Pare antes: docker stop $WORKER_CONTAINER"
        exit 1
    fi
    if [[ $DATABASE_ONLY -eq 0 ]] && container_running "$APP_CONTAINER"; then
        error "A aplicação está rodando e os uploads serão trocados. Pare antes: docker stop $APP_CONTAINER"
        exit 1
    fi
}

# ---------------------------------------------------------------------------
# 0. Trava do deploy e serviços parados
# ---------------------------------------------------------------------------
exec 9>"$TRAVA" || { error "Não consegui abrir a trava do deploy: $TRAVA"; exit 1; }
flock -n 9 || { error "Um deploy está em andamento (trava $TRAVA ocupada). Espere ele terminar."; exit 1; }
checar_containers_parados

# ---------------------------------------------------------------------------
# 1. Pré-verificação dos artefatos (nada no ar é tocado aqui)
# ---------------------------------------------------------------------------
UPLOAD_SOURCE=""
UPLOADS_PREFIX=""
NOVOS_UPLOADS_KB=0
if [[ "$FORMATO" == tar ]]; then
    validate_archive_names "$BACKUP_FILE" gz
    db_member=$(grep -E '^(\./)?database\.sql\.gz$' <<<"$ARCHIVE_LISTING" | head -n1 || true)
    [[ -n "$db_member" ]] || { error 'database.sql.gz ausente no backup.'; exit 1; }
    UPLOADS_PREFIX=$(grep -oE '^(\./)?uploads/' <<<"$ARCHIVE_LISTING" | head -n1 || true)
    if [[ -n "$UPLOADS_PREFIX" ]]; then
        # Formato histórico: só pode ter o banco, os metadados e a pasta uploads/.
        inesperado=$(grep -vE '^(\./)?(database\.sql\.gz|backup_info\.txt|uploads/.*)$|^\./$' <<<"$ARCHIVE_LISTING" | head -n3 || true)
        [[ -z "$inesperado" ]] || { error "Conteúdo inesperado no backup: $inesperado"; exit 1; }
    fi
    db_gz_bytes=$(awk -v m="$db_member" '$NF == m { print $3; exit }' <<<"$ARCHIVE_VERBOSE")
    precisa_tmp=$(( db_gz_bytes * 11 / 10240 + 1024 ))
    livre_tmp=$(livre_kb "$TEMP_DIR")
    (( livre_tmp >= precisa_tmp )) || {
        error "Espaço insuficiente em $TEMP_DIR: precisa de ~${precisa_tmp} KB, há ${livre_tmp} KB. Use TMPDIR=/outro/disco."; exit 1;
    }
    # Só o banco vai para o disco do host; os uploads históricos são extraídos dentro do container.
    tar -xzf "$BACKUP_FILE" -C "$TEMP_DIR" "$db_member"
    gzip -t "${TEMP_DIR}/${db_member}" || { error 'database.sql.gz corrompido.'; exit 1; }
    DB_SQL_GZ="${TEMP_DIR}/${db_member}"

    if [[ $DATABASE_ONLY -eq 0 ]]; then
        if [[ -n "$UPLOADS_PREFIX" ]]; then
            UPLOAD_SOURCE=embedded
            EXPECTED_UPLOADS=$(awk -v p="$UPLOADS_PREFIX" 'index($0, p) == 1 && !/\/$/ { n++ } END { print n+0 }' <<<"$ARCHIVE_LISTING")
            NOVOS_UPLOADS_KB=$(awk -v m="$db_member" 'substr($1, 1, 1) == "-" && $NF != m { s += $3 } END { printf "%d", s / 1024 }' <<<"$ARCHIVE_VERBOSE")
        else
            if [[ -z "$UPLOADS_FILE" ]]; then
                backup_name=$(basename "$BACKUP_FILE")
                if [[ "$backup_name" =~ ^jusprime_([0-9]{8}_[0-9]{6})\.tar\.gz$ ]]; then
                    UPLOADS_FILE="$(dirname "$BACKUP_FILE")/uploads_${BASH_REMATCH[1]}.tar"
                fi
            fi
            if [[ -z "$UPLOADS_FILE" || ! -f "$UPLOADS_FILE" ]]; then
                error 'Uploads esperados, mas ausentes. Informe uploads.tar ou use --database-only.'
                error '(Em produção o backup local de uploads vem desligado: BACKUP_UPLOADS=0.)'
                exit 1
            fi
            validate_archive_names "$UPLOADS_FILE" plain
            UPLOAD_SOURCE=separate
            EXPECTED_UPLOADS=$(awk '!/\/$/ { n++ } END { print n+0 }' <<<"$ARCHIVE_LISTING")
            NOVOS_UPLOADS_KB=$(( $(stat -c %s "$UPLOADS_FILE") / 1024 ))
        fi
    fi
fi

[[ -f "$ENV_FILE" ]] || { error "Arquivo de ambiente ausente: $ENV_FILE"; exit 1; }
POSTGRES_USER=$(read_env_var POSTGRES_USER | tr -d '[:space:]')
POSTGRES_PASSWORD=$(read_env_var POSTGRES_PASSWORD)
db_lido=$(read_env_var POSTGRES_DB | tr -d '[:space:]')
if [[ -z "$POSTGRES_USER" || -z "$POSTGRES_PASSWORD" || -z "$db_lido" ]]; then
    error "Variáveis PostgreSQL obrigatórias ausentes em $ENV_FILE"; exit 1
fi
[[ "$db_lido" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { error 'POSTGRES_DB inválido.'; exit 1; }
POSTGRES_DB="$db_lido"
STAGING_DB="${POSTGRES_DB}_restore_${$}"
OLD_DB="${POSTGRES_DB}_before_restore_${$}"
DISCARD_DB="${POSTGRES_DB}_restaurado_${$}"
# O PostgreSQL trunca nomes acima de 63 bytes sem avisar: dois nomes poderiam colidir.
(( ${#OLD_DB} <= 63 && ${#DISCARD_DB} <= 63 )) || { error "POSTGRES_DB longo demais para o nome de preservação: $OLD_DB"; exit 1; }

container_running "$DB_CONTAINER" || { error "Container inativo: $DB_CONTAINER"; exit 1; }
for var in POSTGRES_DB POSTGRES_USER; do
    no_container=$(docker exec "$DB_CONTAINER" printenv "$var" 2>/dev/null || true)
    if [[ -n "$no_container" && "$no_container" != "${!var}" ]]; then
        error "$var diverge: $ENV_FILE diz '${!var}', o container $DB_CONTAINER diz '$no_container'."; exit 1
    fi
done
db_exists "$POSTGRES_DB" || { error "Banco '$POSTGRES_DB' não existe no container $DB_CONTAINER."; exit 1; }
for nome in "$STAGING_DB" "$OLD_DB" "$DISCARD_DB"; do
    if db_exists "$nome"; then error "Já existe um banco chamado '$nome'; não vou sobrescrever."; exit 1; fi
done
if [[ $DATABASE_ONLY -eq 0 ]]; then
    docker_volume_exists "$UPLOADS_VOLUME" || { error "Volume ausente: $UPLOADS_VOLUME"; exit 1; }
fi

if [[ "$FORMATO" == dump ]]; then
    tabelas=$(docker exec -i "$DB_CONTAINER" pg_restore --list <"$BACKUP_FILE" | grep -c ' TABLE ' || true)
    [[ "$tabelas" -gt 0 ]] || { error "Dump ilegível ou sem tabelas (pg_restore --list): $BACKUP_FILE"; exit 1; }
    # Formato custom é comprimido; o banco restaurado ocupa várias vezes o arquivo.
    banco_kb=$(( $(stat -c %s "$BACKUP_FILE") * 4 / 1024 ))
else
    banco_kb=$(( $(zcat "$DB_SQL_GZ" | wc -c) / 1024 ))
fi

# Espaço no disco do docker: banco descartável + uploads (preparado, cópia de volta e folga).
# Pico: banco descartável + volume preparado (uploads novos) + volume de volta (uploads atuais).
atuais_kb=0
if [[ $DATABASE_ONLY -eq 0 ]]; then
    atuais_kb=$(volume_kb "$UPLOADS_VOLUME")
fi
precisa_docker=$(( (banco_kb + NOVOS_UPLOADS_KB + atuais_kb) * 11 / 10 + 10240 ))
livre_docker=$(livre_docker_kb)
(( livre_docker >= precisa_docker )) || {
    error "Espaço insuficiente no disco do docker: precisa de ~${precisa_docker} KB (banco ${banco_kb} KB + uploads novos ${NOVOS_UPLOADS_KB} KB + cópia de volta ${atuais_kb} KB), há ${livre_docker} KB."
    exit 1
}

# ---------------------------------------------------------------------------
# 2. Uploads preparados num volume descartável (o volume no ar fica intacto)
# ---------------------------------------------------------------------------
if [[ $DATABASE_ONLY -eq 0 ]]; then
    log 'Preparando uploads num volume descartável...'
    docker volume create "$STAGING_VOLUME" >/dev/null; STAGING_CREATED=1
    if [[ "$UPLOAD_SOURCE" == embedded ]]; then
        backup_dir=$(cd "$(dirname "$BACKUP_FILE")" && pwd)
        strip=$(( $(tr -cd '/' <<<"$UPLOADS_PREFIX" | wc -c) ))
        # Extraído dentro do container (como no formato atual) para preservar donos numéricos.
        # Com --strip-components, o banco e os metadados (fora de uploads/) ficam de fora.
        docker run --rm -v "$STAGING_VOLUME:/dest" -v "${backup_dir}:/backup:ro" \
            alpine sh -ec 'tar -xzf "/backup/$1" -C /dest --strip-components="$2"' \
            sh "$(basename "$BACKUP_FILE")" "$strip"
    else
        uploads_dir=$(cd "$(dirname "$UPLOADS_FILE")" && pwd)
        docker run --rm -v "$STAGING_VOLUME:/dest" -v "${uploads_dir}:/backup:ro" \
            alpine sh -ec 'tar -xf "/backup/$1" -C /dest' sh "$(basename "$UPLOADS_FILE")"
    fi
    STAGED_UPLOADS=$(count_volume_files "$STAGING_VOLUME")
    STAGED_FINGERPRINT=$(fingerprint_volume "$STAGING_VOLUME")
    [[ "$STAGED_UPLOADS" -eq "$EXPECTED_UPLOADS" ]] || {
        error "Uploads preparados divergem: esperado=$EXPECTED_UPLOADS obtido=$STAGED_UPLOADS"; exit 1;
    }
fi

# ---------------------------------------------------------------------------
# 3. Dump restaurado por inteiro num banco descartável
# ---------------------------------------------------------------------------
log "Restaurando o dump no banco descartável '$STAGING_DB'..."
psql_admin -v nome="$STAGING_DB" -v dono="$POSTGRES_USER" \
    <<<'CREATE DATABASE :"nome" OWNER :"dono";' >/dev/null
STAGING_DB_CREATED=1
if [[ "$FORMATO" == dump ]]; then
    docker exec -i "$DB_CONTAINER" sh -c \
        'PGPASSWORD="$POSTGRES_PASSWORD" exec pg_restore -U "$POSTGRES_USER" -d "$1" --exit-on-error --single-transaction' \
        sh "$STAGING_DB" <"$BACKUP_FILE"
else
    zcat "$DB_SQL_GZ" | docker exec -i "$DB_CONTAINER" sh -c \
        'PGPASSWORD="$POSTGRES_PASSWORD" exec psql -X -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" >/dev/null' \
        sh "$STAGING_DB"
fi
psql_db "$STAGING_DB" <<<'ANALYZE;' >/dev/null
resumo_novo=$(resumo_banco "$STAGING_DB")
resumo_ar=$(resumo_banco "$POSTGRES_DB")
IFS='|' read -r novo_tab novo_lin novo_user novo_tenant <<<"$resumo_novo"
IFS='|' read -r ar_tab ar_lin ar_user ar_tenant <<<"$resumo_ar"
[[ "$novo_tab" -gt 0 ]] || { error 'O backup restaurou 0 tabelas: não parece um backup do sistema. Nada foi alterado.'; exit 1; }

# Replica no banco novo as permissões de banco do banco no ar (o pg_dump não leva):
# dono, GRANT ... ON DATABASE e ALTER DATABASE/ROLE ... IN DATABASE ... SET.
log 'Replicando permissões e configurações do banco no ar para o banco novo...'
psql_admin -v alvo="$POSTGRES_DB" -v novo="$STAGING_DB" >/dev/null <<'SQL'
SELECT format('ALTER DATABASE %I OWNER TO %I', :'novo', pg_get_userbyid(d.datdba))
  FROM pg_database d WHERE d.datname = :'alvo'
\gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'novo')
  FROM pg_database d WHERE d.datname = :'alvo' AND d.datacl IS NOT NULL
\gexec
SELECT format('GRANT %s ON DATABASE %I TO %s%s', a.privilege_type, :'novo',
              CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(a.grantee)) END,
              CASE WHEN a.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END)
  FROM pg_database d CROSS JOIN LATERAL aclexplode(d.datacl) a
 WHERE d.datname = :'alvo' AND d.datacl IS NOT NULL
\gexec
-- O valor guardado em setconfig é a forma "achatada" do PostgreSQL: listas (search_path,
-- DateStyle...) vêm como "a, b", com identificadores entre aspas duplas quando precisam. Cada item
-- vira um literal separado (aspas duplas desfeitas), que o PostgreSQL volta a achatar igual.
-- A conferência logo abaixo recusa o restore se o resultado não bater.
SELECT CASE WHEN s.setrole = 0
            THEN format('ALTER DATABASE %I SET %I = %s', :'novo', split_part(c, '=', 1), v.lista)
            ELSE format('ALTER ROLE %I IN DATABASE %I SET %I = %s', pg_get_userbyid(s.setrole), :'novo',
                        split_part(c, '=', 1), v.lista)
       END
  FROM pg_database d JOIN pg_db_role_setting s ON s.setdatabase = d.oid
 CROSS JOIN LATERAL unnest(s.setconfig) c
 CROSS JOIN LATERAL (
       SELECT string_agg(quote_literal(CASE WHEN e ~ '^".*"$'
                                            THEN replace(substr(e, 2, length(e) - 2), '""', '"')
                                            ELSE e END), ', ' ORDER BY n) AS lista
         FROM regexp_split_to_table(substr(c, strpos(c, '=') + 1), ', ') WITH ORDINALITY AS x(e, n)
       ) v
 WHERE d.datname = :'alvo'
\gexec
SQL
perm_ar=$(permissoes_banco "$POSTGRES_DB")
perm_novo=$(permissoes_banco "$STAGING_DB")
[[ "$perm_ar" == "$perm_novo" ]] || {
    error "Permissões do banco novo não conferem com as do banco no ar; nada foi alterado."
    error "  no ar: $perm_ar"
    error "  novo : $perm_novo"
    exit 1
}

# ---------------------------------------------------------------------------
# 4. Confirmação
# ---------------------------------------------------------------------------
echo
echo 'Compare o backup com o banco no ar antes de confirmar:'
printf '  %-24s %15s %15s\n' '' 'no ar' 'backup'
printf '  %-24s %15s %15s\n' 'tabelas' "$ar_tab" "$novo_tab"
printf '  %-24s %15s %15s\n' 'linhas (aproximado)' "$ar_lin" "$novo_lin"
printf '  %-24s %15s %15s\n' 'usuários ("user")' "$ar_user" "$novo_user"
printf '  %-24s %15s %15s\n' 'escritórios (tenant)' "$ar_tenant" "$novo_tenant"
echo
echo "ATENÇÃO: o banco '$POSTGRES_DB' será substituído (o atual fica preservado como '$OLD_DB')."
echo "As conexões abertas em '$POSTGRES_DB' serão derrubadas durante a troca."
if [[ $DATABASE_ONLY -eq 0 ]]; then
    echo "O volume '$UPLOADS_VOLUME' será substituído por $STAGED_UPLOADS arquivo(s)."
else
    echo 'Modo somente banco: uploads não serão tocados.'
fi
CONFIRM=""
read -rp "Digite 'CONFIRMO' para continuar: " CONFIRM || true
if [[ "$CONFIRM" != CONFIRMO ]]; then
    echo 'Operação cancelada; nenhum dado existente foi alterado.'
    exit 0
fi
# Alguém pode ter religado algo enquanto o operador lia a tabela.
checar_containers_parados

# ---------------------------------------------------------------------------
# 5. Troca dos uploads (com cópia de volta conferida)
# ---------------------------------------------------------------------------
if [[ $DATABASE_ONLY -eq 0 ]]; then
    docker volume create "$ROLLBACK_VOLUME" >/dev/null; ROLLBACK_CREATED=1
    log "Copiando os uploads atuais para o volume de volta: $ROLLBACK_VOLUME"
    copy_volume "$UPLOADS_VOLUME" "$ROLLBACK_VOLUME"
    ROLLBACK_FINGERPRINT=$(fingerprint_volume "$ROLLBACK_VOLUME")
    [[ "$ROLLBACK_FINGERPRINT" == "$(fingerprint_volume "$UPLOADS_VOLUME")" ]] || {
        error 'A cópia de volta dos uploads não confere com o volume atual; nada foi trocado.'; exit 1;
    }
    log 'Trocando os uploads...'
    UPLOADS_CUTOVER_STARTED=1
    copy_volume "$STAGING_VOLUME" "$UPLOADS_VOLUME"
    FINAL_UPLOADS=$(count_volume_files "$UPLOADS_VOLUME")
    FINAL_FINGERPRINT=$(fingerprint_volume "$UPLOADS_VOLUME")
    [[ "$FINAL_UPLOADS" -eq "$STAGED_UPLOADS" ]] || {
        error "Destino diverge: preparado=$STAGED_UPLOADS destino=$FINAL_UPLOADS"; exit 1;
    }
    [[ "$FINAL_FINGERPRINT" == "$STAGED_FINGERPRINT" ]] || {
        error 'Checksum agregado dos uploads diverge após a substituição.'; exit 1;
    }
    [[ "$INJETAR_FALHA" != apos_uploads ]] || { error 'Falha injetada (teste): apos_uploads.'; exit 1; }
fi

# ---------------------------------------------------------------------------
# 6. Troca do banco: bloqueia conexões novas, derruba as abertas, troca os nomes numa transação
# ---------------------------------------------------------------------------
log "Bloqueando conexões novas em '$POSTGRES_DB'..."
DB_LOCKED=1
psql_admin -v alvo="$POSTGRES_DB" <<<'ALTER DATABASE :"alvo" ALLOW_CONNECTIONS false;' >/dev/null
conexoes=-1
for _ in $(seq 1 30); do
    conexoes=$(psql_admin -v alvo="$POSTGRES_DB" <<'SQL'
SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity
 WHERE datname = :'alvo' AND pid <> pg_backend_pid();
SQL
)
    [[ "$conexoes" -eq 0 ]] && break
    sleep 1
done
[[ "$conexoes" -eq 0 ]] || { error "Ainda há conexões em '$POSTGRES_DB' após 30s; nada foi trocado."; exit 1; }

falha_sql=""
[[ "$INJETAR_FALHA" == durante_banco ]] && falha_sql='SELECT 1/0;'
log "Trocando o banco ('$POSTGRES_DB' -> '$OLD_DB', '$STAGING_DB' -> '$POSTGRES_DB')..."
DB_SWAP_STARTED=1
if ! psql_admin -v alvo="$POSTGRES_DB" -v antigo="$OLD_DB" -v novo="$STAGING_DB" >/dev/null <<SQL
BEGIN;
ALTER DATABASE :"alvo" RENAME TO :"antigo";
${falha_sql}
ALTER DATABASE :"novo" RENAME TO :"alvo";
COMMIT;
SQL
then
    # A transação é atômica, mas confere o estado real antes de decidir (ex.: o docker exec
    # caiu depois do COMMIT).
    if db_exists "$OLD_DB" && db_exists "$POSTGRES_DB" && ! db_exists "$STAGING_DB"; then
        log 'O comando de troca falhou, mas a troca foi efetivada no banco; seguindo.'
    else
        if ! db_exists "$POSTGRES_DB" && db_exists "$OLD_DB"; then
            # Defesa: nunca deixar o sistema sem banco no nome original.
            psql_admin -v alvo="$POSTGRES_DB" -v antigo="$OLD_DB" >/dev/null \
                <<<'ALTER DATABASE :"antigo" RENAME TO :"alvo";' \
                || error "URGENTE: '$POSTGRES_DB' não existe; o banco anterior está em '$OLD_DB'. Renomeie-o de volta."
        fi
        error 'Troca do banco falhou; o banco anterior continua no nome original.'
        exit 1
    fi
fi
STAGING_DB_CREATED=0
[[ "$INJETAR_FALHA" != apos_commit ]] || { error 'Falha injetada (teste): apos_commit.'; exit 1; }
# Daqui em diante banco e uploads novos estão no ar: nenhuma falha pode disparar o rollback dos
# uploads (deixaria banco novo com arquivos antigos).
RESTORE_OK=1
# O banco novo herdou ALLOW_CONNECTIONS true do descartável; o antigo é reaberto para consulta.
psql_admin -v alvo="$POSTGRES_DB" -v antigo="$OLD_DB" >/dev/null <<'SQL' \
    || error "Não consegui reabrir conexões; rode ALTER DATABASE ... ALLOW_CONNECTIONS true em '$POSTGRES_DB' e '$OLD_DB'."
ALTER DATABASE :"alvo" ALLOW_CONNECTIONS true;
ALTER DATABASE :"antigo" ALLOW_CONNECTIONS true;
SQL
DB_LOCKED=0

log 'Restauração concluída com sucesso.'
echo
echo "Banco anterior preservado como '$OLD_DB' (não foi apagado)."
if [[ $DATABASE_ONLY -eq 0 ]]; then
    echo 'Os uploads anteriores NÃO foram guardados (o volume de volta é apagado no sucesso).'
fi
echo "Religue os serviços: docker start $APP_CONTAINER $WORKER_CONTAINER"
echo
echo 'Para DESFAZER (voltar ao banco anterior), com php e worker parados, cole no terminal:'
PSQL_CMD="docker exec -i $DB_CONTAINER sh -c 'PGPASSWORD=\"\$POSTGRES_PASSWORD\" exec psql -X -q -At -U \"\$POSTGRES_USER\" -d postgres -v ON_ERROR_STOP=1 \"\$@\"' sh"
# Estado lido no fim: (anterior existe, anterior aceita conexão, banco no ar aceita conexão).
# O bloco tranca e esvazia os DOIS bancos (o anterior foi reaberto acima), troca os nomes numa
# transação e reabre o que fica no nome original. Se falhar, reabre os dois. Nenhum comando do
# bloco termina em falha (quem cola pode ter set -e no terminal); o resultado sai no fim, lido do
# estado real dos bancos. pg_stat_activity é uma foto por transação: o laço limpa a foto a cada
# volta, senão nunca enxergaria as conexões saindo.
# shellcheck disable=SC2016  # o bloco é texto para o operador colar; expande no terminal dele
printf '%s\n' \
    '# --- desfazer: início ---' \
    "desfazer_reabrir() { $PSQL_CMD -v ON_ERROR_STOP=0 <<'SQL' >/dev/null 2>&1 || true" \
    "ALTER DATABASE \"$POSTGRES_DB\" ALLOW_CONNECTIONS true;" \
    "ALTER DATABASE \"$OLD_DB\" ALLOW_CONNECTIONS true;" \
    'SQL' \
    '}' \
    'desfazer_rc=0' \
    "$PSQL_CMD <<'SQL' || desfazer_rc=1" \
    "ALTER DATABASE \"$POSTGRES_DB\" ALLOW_CONNECTIONS false;" \
    "ALTER DATABASE \"$OLD_DB\" ALLOW_CONNECTIONS false;" \
    'DO $$' \
    'BEGIN' \
    '  FOR i IN 1..60 LOOP' \
    '    PERFORM pg_stat_clear_snapshot();' \
    "    EXIT WHEN NOT EXISTS (SELECT 1 FROM pg_stat_activity WHERE datname IN ('$POSTGRES_DB', '$OLD_DB') AND pid <> pg_backend_pid());" \
    "    PERFORM pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IN ('$POSTGRES_DB', '$OLD_DB') AND pid <> pg_backend_pid();" \
    '    PERFORM pg_sleep(0.5);' \
    '  END LOOP;' \
    'END $$;' \
    'BEGIN;' \
    "ALTER DATABASE \"$POSTGRES_DB\" RENAME TO \"$DISCARD_DB\";" \
    "ALTER DATABASE \"$OLD_DB\" RENAME TO \"$POSTGRES_DB\";" \
    'COMMIT;' \
    "ALTER DATABASE \"$POSTGRES_DB\" ALLOW_CONNECTIONS true;" \
    'SQL' \
    'if [ "$desfazer_rc" -ne 0 ]; then desfazer_reabrir; fi' \
    "desfazer_estado=\$($PSQL_CMD <<<\"SELECT (SELECT count(*) FROM pg_database WHERE datname = '$OLD_DB') || ',' || (SELECT count(*) FROM pg_database WHERE datname = '$OLD_DB' AND datallowconn) || ',' || (SELECT count(*) FROM pg_database WHERE datname = '$POSTGRES_DB' AND datallowconn)\") || desfazer_estado=erro" \
    'case "$desfazer_estado" in' \
    "  0,0,1) echo 'DESFAZER OK: o banco anterior voltou ao nome $POSTGRES_DB.' ;;" \
    "  1,1,1) echo 'DESFAZER FALHOU: nada foi trocado; os dois bancos foram reabertos.' >&2 ;;" \
    "  *) echo \"DESFAZER FALHOU: estado inesperado (\$desfazer_estado); confira os bancos $POSTGRES_DB, $OLD_DB e $DISCARD_DB.\" >&2 ;;" \
    'esac' \
    '# --- desfazer: fim ---'
echo "Depois do desfazer, '$DISCARD_DB' fica trancado; apague-o com dropdb quando quiser."
echo 'Se o desfazer falhar e alguma conexão continuar recusada, reabra os dois bancos com:'
printf '  %s <<<'"'"'ALTER DATABASE "%s" ALLOW_CONNECTIONS true; ALTER DATABASE "%s" ALLOW_CONNECTIONS true;'"'"'\n' \
    "$PSQL_CMD" "$POSTGRES_DB" "$OLD_DB"
echo
echo 'Depois de conferir o sistema, apague o banco anterior manualmente com:'
# shellcheck disable=SC2016  # idem: expande dentro do container
printf '  docker exec %s sh -c '"'"'PGPASSWORD="$POSTGRES_PASSWORD" dropdb -U "$POSTGRES_USER" %s'"'"'\n' \
    "$DB_CONTAINER" "$OLD_DB"
echo 'Runbook: docs/runbooks/restaurar-backup.md'
