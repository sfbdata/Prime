#!/usr/bin/env bash
# Monta o banco de teste do job "Suíte completa" (.github/workflows/suite-completa.yml) a partir do
# snapshot versionado, e um clone dele por processo do ParaTest.
#
# Uso:  scripts/ci-montar-banco-de-teste.sh <banco_de_teste> <processos>
#
#   banco_de_teste  o banco que o Symfony usa em APP_ENV=test: o dbname do DATABASE_URL + "_test"
#                   (doctrine.yaml, when@test). Ex.: DATABASE_URL .../saas -> saas_test.
#   processos       quantos clones criar: <banco_de_teste>_P1..N, o nome que o
#                   tests/bootstrap.php (ProcessoDeTesteParalelo) dá ao banco de cada processo do
#                   ParaTest quando não há TEST_FRENTE.
#
# Passos:
#   1. recria <banco_de_teste> vazio e carrega app/tests/ci/schema-de-teste.sql (estrutura do banco
#      local de teste + catálogo permission + versões de migration já aplicadas);
#   2. doctrine:migrations:migrate: aplica só as migrations mais novas que o snapshot (o snapshot
#      não precisa ser regerado a cada migration; ver scripts/gerar-schema-de-teste.sh);
#   3. clona o banco migrado para cada processo (CREATE DATABASE ... TEMPLATE).
#
# Variáveis (os padrões servem ao CI; localmente, aponte para os containers):
#   PSQL      comando do psql, sem banco (padrão: "psql"; conexão por PGHOST/PGUSER/PGPASSWORD).
#             Ex. local: PSQL="docker exec -i jusprime_db_dev psql -U symfony"
#   CONSOLE   comando do bin/console em APP_ENV=test (padrão: "php <repo>/app/bin/console").
#   SNAPSHOT  caminho do snapshot (padrão: <repo>/app/tests/ci/schema-de-teste.sql).
set -euo pipefail

if [ $# -ne 2 ]; then
    echo "uso: $0 <banco_de_teste> <processos>" >&2
    exit 1
fi
banco="$1"
processos="$2"
if ! [[ "$banco" =~ ^[a-zA-Z0-9_]+$ ]]; then
    echo "erro: nome de banco inválido ('$banco')." >&2
    exit 1
fi
# O script APAGA e recria o banco (e os clones _P<n>). Só aceita nome de banco de teste, para um
# engano local (PSQL apontando para o jusprime_db_dev) nunca derrubar o saas ou o saas_ux.
if ! [[ "$banco" =~ _test$ || "$banco" =~ ^saas_ci_ ]]; then
    echo "erro: '$banco' não parece banco de teste (precisa terminar em _test). Nada foi apagado." >&2
    exit 1
fi
if ! [[ "$processos" =~ ^[1-9][0-9]*$ ]]; then
    echo "erro: o número de processos precisa ser um inteiro positivo." >&2
    exit 1
fi

raiz=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
read -r -a psql_cmd <<< "${PSQL:-psql}"
read -r -a console_cmd <<< "${CONSOLE:-php $raiz/app/bin/console}"
snapshot="${SNAPSHOT:-$raiz/app/tests/ci/schema-de-teste.sql}"

if [ ! -f "$snapshot" ]; then
    echo "erro: snapshot não encontrado: $snapshot" >&2
    exit 1
fi

psql_em() {
    local alvo="$1"
    shift
    "${psql_cmd[@]}" -d "$alvo" -v ON_ERROR_STOP=1 -q -X "$@"
}

recriar() {
    psql_em postgres -c "DROP DATABASE IF EXISTS \"$1\"" -c "CREATE DATABASE \"$1\" ${2:+TEMPLATE \"$2\"}"
}

echo "==> recriando $banco a partir de ${snapshot#"$raiz"/}"
# template0: o template1 de uma máquina local pode ter extensão ou objeto a mais.
recriar "$banco" template0
psql_em "$banco" < "$snapshot" > /dev/null

echo "==> aplicando as migrations mais novas que o snapshot"
"${console_cmd[@]}" doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

versoes=$(psql_em "$banco" -At -c "SELECT count(*) FROM doctrine_migration_versions")
permissoes=$(psql_em "$banco" -At -c "SELECT count(*) FROM permission")
echo "==> $banco: $versoes migrations registradas, $permissoes permissões"

for i in $(seq 1 "$processos"); do
    recriar "${banco}_P${i}" "$banco"
done
echo "==> clones: ${banco}_P1..${banco}_P${processos}"
