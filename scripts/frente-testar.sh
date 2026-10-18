#!/usr/bin/env bash
# Roda a suite DA FRENTE, no banco DA FRENTE.
#
# Existe por causa de um verde falso: o comando padrao do projeto
#   docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'
# faz `cd app` no REPOSITORIO PRINCIPAL. Rodado enquanto se trabalha numa worktree,
# ele testa o codigo errado e devolve um verde que nao vale nada — o mesmo modo de
# falha do "falso sucesso" ja registrado nas regras de git do projeto.
#
# Uso:  scripts/frente-testar.sh <nome-da-frente> [args extras do phpunit]
#       scripts/frente-testar.sh minha-frente --filter CobrancaTest
set -euo pipefail

nome="${1:-}"
if [ -z "$nome" ]; then
    echo "uso: scripts/frente-testar.sh <nome-da-frente> [args do phpunit]" >&2
    exit 1
fi
shift || true

# O repositório principal, mesmo quando o script é chamado de dentro de uma worktree (o
# --show-toplevel devolvia a própria worktree e o caminho saía dobrado).
raiz=$(cd "$(git rev-parse --git-common-dir)/.." && pwd)
source "$(dirname "${BASH_SOURCE[0]}")/lib/opcache-de-teste.sh"
container=jusprime_php_dev

if [ "$nome" = "master" ] || [ "$nome" = "principal" ]; then
    caminho_host="$raiz/app"
    caminho_container="/var/www/app"
    token=""            # repo principal usa o saas_test de sempre
else
    caminho_host="$raiz/.claude/worktrees/$nome/app"
    caminho_container="/var/www/.claude/worktrees/$nome/app"
    token="$nome"
fi

if [ ! -d "$caminho_host" ]; then
    echo "erro: frente '$nome' nao encontrada em $caminho_host" >&2
    echo "      abra com: scripts/frente-abrir.sh $nome" >&2
    exit 1
fi
if [ ! -d "$caminho_host/vendor" ]; then
    echo "erro: $caminho_host/vendor nao existe — a suite falharia por falta de autoload." >&2
    echo "      rode: docker exec $container bash -c 'cd $caminho_container && composer install'" >&2
    exit 1
fi

banco="saas_test${token}"
echo "==> frente:  $nome"
echo "==> codigo:  $caminho_container"
echo "==> banco:   $banco"
echo

# Argumentos citados um a um: um --filter 'A|B' chega inteiro ao phpunit, não vira pipe.
extras_citados=""
if [ $# -gt 0 ]; then
    printf -v extras_citados '%q ' "$@"
fi

docker exec -e "TEST_TOKEN=$token" "$container" \
    bash -c "cd '$caminho_container' && php $OPCACHE_DE_TESTE bin/phpunit $extras_citados"
