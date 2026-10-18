#!/usr/bin/env bash
# Regera app/tests/ci/schema-de-teste.sql, o ponto de partida do banco de teste do job "Suíte
# completa" (.github/workflows/suite-completa.yml).
#
# Uso:  scripts/gerar-schema-de-teste.sh [banco_de_origem]      (padrão: saas_test)
#
# Por que um snapshot e não migrations ou schema:create: migrations do zero falham
# (Version20260625200952 supõe dados de um dump) e schema:create não cria a extensão unaccent nem os
# índices funcionais de SQL cru das migrations. O snapshot é o que o banco local de teste tem:
#   1. pg_dump --schema-only --no-owner --no-privileges (estrutura inteira, sem dado nenhum);
#   2. dados de SÓ duas tabelas: doctrine_migration_versions (para o CI aplicar apenas as migrations
#      mais novas que o snapshot) e permission (catálogo de permissões semeado por migration; sem
#      ele o PerfisDoEscritorioControllerTest quebra).
# Nenhuma outra tabela tem dado copiado: as demais são dado de cliente, e o repositório é público.
#
# Quando regerar: não é obrigatório a cada migration (o CI roda doctrine:migrations:migrate em cima
# do snapshot). Regere quando a cadeia de migrations ficar muito à frente dele, ou quando uma
# migration nova não rodar em banco sem dado (o mesmo motivo que impede migrar do zero).
#
# Roda no host: o pg_dump é o do container jusprime_db_dev (mesma versão do postgres:15 do CI).
set -euo pipefail

origem="${1:-saas_test}"
if ! [[ "$origem" =~ ^[a-zA-Z0-9_.-]+$ ]]; then
    echo "erro: nome de banco inválido ('$origem')." >&2
    exit 1
fi

banco_container=jusprime_db_dev
raiz=$(git rev-parse --show-toplevel)
destino="$raiz/app/tests/ci/schema-de-teste.sql"
temporario=$(mktemp)
trap 'rm -f "$temporario"' EXIT

pg_dump_no_container() {
    docker exec "$banco_container" pg_dump -U symfony --no-owner --no-privileges -d "$origem" "$@"
}

if [ "$(docker exec "$banco_container" psql -U symfony -d postgres -qAt -c "SELECT 1 FROM pg_database WHERE datname = '$origem'")" != "1" ]; then
    echo "erro: o banco $origem não existe no $banco_container." >&2
    exit 1
fi

echo "==> gerando o snapshot a partir de $origem"
{
    echo "-- Snapshot do banco de teste para o CI (job \"Suíte completa\")."
    echo "-- Gerado por scripts/gerar-schema-de-teste.sh a partir do banco local de teste. Não editar à mão."
    echo "-- Conteúdo: estrutura (schema-only) + dados de doctrine_migration_versions e permission."
    echo
    pg_dump_no_container --schema-only
    pg_dump_no_container --data-only -t public.doctrine_migration_versions -t public.permission
} \
    | sed -E '/^\\(un)?restrict /d' \
    > "$temporario"
# O \restrict/\unrestrict (pg_dump 15.14+) traz uma chave aleatória a cada geração: o diff do
# snapshot ficaria sujo sem mudança nenhuma, e o psql 16 do runner pode não conhecer o comando.

# Conferências: dado só das duas tabelas permitidas, por COPY (nenhum INSERT solto).
copias=$(grep -E '^COPY ' "$temporario" | sed -E 's/^COPY ([^ ]+) .*/\1/' | sort | tr '\n' ' ')
if [ "$copias" != "public.doctrine_migration_versions public.permission " ]; then
    echo "erro: o snapshot traria dado de tabela não permitida: $copias" >&2
    exit 1
fi
if grep -qiE '^INSERT INTO' "$temporario"; then
    echo "erro: o snapshot tem INSERT solto; só as duas tabelas do catálogo podem ter dado." >&2
    exit 1
fi
if grep -qE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "$temporario"; then
    echo "erro: o snapshot contém algo com cara de e-mail; confira antes de versionar:" >&2
    grep -nE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "$temporario" | head -5 >&2
    exit 1
fi

mkdir -p "$(dirname "$destino")"
mv "$temporario" "$destino"
trap - EXIT

versoes=$(awk '/^COPY public.doctrine_migration_versions /{d=1;next} /^\\\.$/{d=0} d' "$destino" | wc -l)
permissoes=$(awk '/^COPY public.permission /{d=1;next} /^\\\.$/{d=0} d' "$destino" | wc -l)
echo "==> $destino ($(du -h "$destino" | cut -f1), $versoes migrations registradas, $permissoes permissões)"
