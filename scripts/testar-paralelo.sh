#!/usr/bin/env bash
# Roda a suíte com o ParaTest: N processos, cada um com banco, uploads, logs e temporários próprios.
#
# A execução serial (`php bin/phpunit`, `scripts/frente-testar.sh`) continua sendo a referência e
# não muda. Este script é o atalho para a máquina do desenvolvedor; o CI não o usa.
#
# Uso:  scripts/testar-paralelo.sh [frente] [-p N] [--recriar] [-- args extras do paratest]
#       scripts/testar-paralelo.sh [frente] --limpar
#
#   frente      nome da frente (.claude/worktrees/<frente>); sem nome, vale a frente de onde o
#               script foi chamado (o .frente da worktree) ou, fora de uma, o checkout principal;
#               "principal" força o checkout principal
#   -p N        número de processos (padrão: 6)
#   --recriar   recria os bancos dos processos mesmo que pareçam em dia
#   --limpar    apaga os bancos e temporários dos processos desta frente e sai
#
# Como cada processo se isola: o ParaTest numera os processos em TEST_TOKEN (1..N) e o
# tests/bootstrap.php (ProcessoDeTesteParalelo) junta esse número com TEST_FRENTE, que este script
# exporta, num token só: <frente>_P<n>. O banco do processo é saas_test<frente>_P<n>, clonado do
# banco da frente (o mesmo TEMPLATE do frente-abrir.sh: migrations do zero não montam um banco certo).
# O "P" maiúsculo é o que impede colisão: nome de frente só tem minúsculas, então nenhum banco de
# processo é o banco serial de outra frente (a frente "a_p1" pode existir; "a_P1" não).
set -euo pipefail

frente=""
processos=6
recriar=0
limpar=0
extras=()

while [ $# -gt 0 ]; do
    case "$1" in
        -p)
            if [ $# -lt 2 ]; then
                echo "erro: -p precisa do número de processos." >&2
                exit 1
            fi
            processos="$2"
            shift 2
            ;;
        --recriar)
            recriar=1
            shift
            ;;
        --limpar)
            limpar=1
            shift
            ;;
        --)
            shift
            extras=("$@")
            break
            ;;
        -*)
            echo "erro: opção desconhecida '$1'. Argumentos do paratest vão depois de '--'." >&2
            exit 1
            ;;
        *)
            if [ -n "$frente" ]; then
                echo "erro: mais de uma frente informada ('$frente' e '$1')." >&2
                exit 1
            fi
            frente="$1"
            shift
            ;;
    esac
done

# O repositório principal, mesmo quando o script é chamado de dentro de uma worktree.
raiz=$(cd "$(git rev-parse --git-common-dir)/.." && pwd)
source "$(dirname "${BASH_SOURCE[0]}")/lib/opcache-de-teste.sh"

if [ -z "$frente" ]; then
    # Chamado de dentro de uma frente sem dizer qual: é ela, não o checkout principal (o verde falso
    # que o frente-testar.sh existe para evitar).
    aqui=$(git rev-parse --show-toplevel)
    if [ "$aqui" != "$raiz" ] && [ -f "$aqui/.frente" ]; then
        frente=$(cat "$aqui/.frente")
    fi
elif [ "$frente" = "principal" ] || [ "$frente" = "master" ]; then
    frente=""
fi
if ! [[ "$processos" =~ ^[1-9][0-9]*$ ]]; then
    echo "erro: -p precisa de um número inteiro positivo." >&2
    exit 1
fi
if [ -n "$frente" ] && ! [[ "$frente" =~ ^[a-z0-9][a-z0-9._/-]*$ ]]; then
    echo "erro: nome de frente inválido ('$frente')." >&2
    exit 1
fi

container=jusprime_php_dev
banco_container=jusprime_db_dev

if [ -z "$frente" ]; then
    caminho_host="$raiz/app"
    caminho_container="/var/www/app"
else
    caminho_host="$raiz/.claude/worktrees/$frente/app"
    caminho_container="/var/www/.claude/worktrees/$frente/app"
fi

template="saas_test${frente}"
prefixo="${template}_P"
# Mesma regra do ProcessoDeTesteParalelo: o temporário do PHP (TMPDIR do container, ou /tmp), e a "/"
# da frente vira "+", que nenhuma frente contém.
raiz_temporaria=$(docker exec "$container" bash -c 'echo "${TMPDIR:-/tmp}"')
temporario_container="${raiz_temporaria%/}/jusprime-teste-${frente//\//+}_P"

psql_postgres() {
    docker exec "$banco_container" psql -U symfony -d postgres -v ON_ERROR_STOP=1 -qAt "$@"
}

existe_banco() {
    [ "$(psql_postgres -c "SELECT 1 FROM pg_database WHERE datname = '$1'")" = "1" ]
}

# Versões de migration aplicadas + forma das colunas + índices: muda quando o banco da frente ganha
# migration, ou um schema:update ou índice sem migration. Barato (milissegundos). Não olha dado: carga
# feita à mão no banco da frente só chega aos clones com --recriar.
impressao_digital() {
    docker exec "$banco_container" psql -U symfony -d "$1" -qAt -c "
        SELECT md5(
            coalesce((SELECT string_agg(version, ',' ORDER BY version) FROM doctrine_migration_versions), '')
            || '|' ||
            coalesce((SELECT string_agg(table_name || '.' || column_name || ':' || data_type, ',' ORDER BY table_name, column_name)
                      FROM information_schema.columns WHERE table_schema = 'public'), '')
            || '|' ||
            coalesce((SELECT string_agg(indexdef, ',' ORDER BY indexname) FROM pg_indexes WHERE schemaname = 'public'), '')
        )"
}

bancos_dos_processos() {
    # O "_" é curinga no LIKE: escapado. O LIKE diferencia maiúscula, então o "P" não pega o banco
    # serial de nenhuma frente.
    psql_postgres -c "SELECT datname FROM pg_database WHERE datname LIKE '$(printf '%s' "$prefixo" | sed 's/_/\\_/g')%' ORDER BY datname"
}

if [ "$limpar" = "1" ]; then
    for banco in $(bancos_dos_processos); do
        echo "==> apagando $banco"
        psql_postgres -c "DROP DATABASE IF EXISTS \"$banco\""
    done
    docker exec "$container" bash -c "rm -rf '$temporario_container'*"
    docker exec "$container" bash -c "rm -rf '$caminho_container'/var/uploads-test-p* '$caminho_container'/var/log/*-p[0-9]*.log"
    echo "==> limpo: bancos ${prefixo}*, temporários ${temporario_container}* e uploads/logs dos processos"
    exit 0
fi

if [ ! -d "$caminho_host/vendor" ]; then
    echo "erro: $caminho_host/vendor não existe." >&2
    exit 1
fi
if [ ! -f "$caminho_host/vendor/bin/paratest" ]; then
    echo "erro: o ParaTest não está instalado em $caminho_host/vendor (rode composer install)." >&2
    exit 1
fi
if ! existe_banco "$template"; then
    echo "erro: o banco $template não existe (abra a frente com scripts/frente-abrir.sh)." >&2
    exit 1
fi

echo "==> frente:     ${frente:-principal}"
echo "==> código:     $caminho_container"
echo "==> processos:  $processos (bancos ${prefixo}1..$processos, clonados de $template)"

digital_template=$(impressao_digital "$template")
for i in $(seq 1 "$processos"); do
    banco="${prefixo}${i}"
    if [ "$recriar" = "0" ] && existe_banco "$banco" && [ "$(impressao_digital "$banco")" = "$digital_template" ]; then
        continue
    fi
    echo "==> clonando $template -> $banco"
    psql_postgres -c "DROP DATABASE IF EXISTS \"$banco\""
    if ! psql_postgres -c "CREATE DATABASE \"$banco\" TEMPLATE \"$template\""; then
        echo "erro: o clone falhou. TEMPLATE exige que ninguém esteja conectado a $template:" >&2
        echo "      espere a suíte serial desta frente terminar e repita." >&2
        exit 1
    fi
done

# Os processos dividem o var/cache/test: compilado antes, nenhum deles disputa a compilação.
echo "==> aquecendo o cache de teste"
docker exec "$container" bash -c "cd '$caminho_container' && php -d memory_limit=512M bin/console cache:warmup --env=test >/dev/null"

# Cada argumento extra chega intacto ao paratest (um --filter 'A|B' não pode virar pipe no bash -c).
extras_citados=""
if [ ${#extras[@]} -gt 0 ]; then
    printf -v extras_citados '%q ' "${extras[@]}"
fi

echo
status=0
docker exec -e "TEST_FRENTE=$frente" "$container" \
    bash -c "cd '$caminho_container' && php $OPCACHE_DE_TESTE vendor/bin/paratest --processes=$processos --passthru-php='$OPCACHE_DE_TESTE' $extras_citados" || status=$?

# Os temporários de cada processo são deste script; o que um teste deixou para trás sai aqui.
docker exec "$container" bash -c "rm -rf '$temporario_container'*" || true

exit "$status"
