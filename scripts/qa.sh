#!/usr/bin/env bash
# Portão de qualidade antes do PR: PHPStan nível 5 + CS-Fixer nos arquivos PHP NOVOS da branch.
# Funciona no checkout principal e em worktree de frente (calcula a lista com o git do host e roda
# o `composer qa` no container, no caminho certo).
#
# Uso:
#   scripts/qa.sh               confere (não altera nada)
#   scripts/qa.sh --corrigir    aplica o CS-Fixer nos arquivos novos; revise o diff (troca == por ===)
set -euo pipefail

container=jusprime_php_dev
topo=$(git rev-parse --show-toplevel)
principal=$(dirname "$(cd "$(git rev-parse --git-common-dir)" && pwd)")
caminho_container="/var/www${topo#"$principal"}/app"

# Atribuição, não `< <(...)`: se a lista não puder ser calculada (sem origin/master, por exemplo),
# o script para aqui em vez de seguir com zero arquivos e sair verde sem ter conferido nada.
lista=$(cd "$topo" && scripts/qa-arquivos-novos.sh --local)
novos=()
[ -n "$lista" ] && mapfile -t novos <<< "$lista"
echo "Arquivos PHP novos desde o master: ${#novos[@]}"
[ "${#novos[@]}" -gt 0 ] && printf '  %s\n' "${novos[@]}"

opcao=()
[ "${1:-}" = "--corrigir" ] && opcao=(--corrigir)

docker exec "$container" bash -c 'cd "$1" && shift && composer qa -- "$@"' _ \
    "$caminho_container" "${opcao[@]}" "${novos[@]}"
