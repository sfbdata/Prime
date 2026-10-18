#!/usr/bin/env bash
# Lista os arquivos PHP NOVOS (relativos a app/) que o CS-Fixer deve conferir.
#
# Uso:
#   qa-arquivos-novos.sh <base>             CI: o que o PR criou (<base>...HEAD)
#   qa-arquivos-novos.sh --local [<base>]   host: criados desde o merge-base com <base>
#                                           (padrão origin/master) até a árvore de trabalho,
#                                           incluindo os ainda não rastreados
#
# "Novo" é --diff-filter=A com detecção de renomeação (-M): um `git mv` de legado para o domínio
# NÃO é novo e não é reformatado. Arquivo movido e muito editado (menos de 50% igual) aparece como
# novo; é o limite da detecção do git.
#
# Escopo: app/src, app/tests e app/migrations. config/, bin/ e public/ ficam de fora (gerados pelo
# Symfony Flex, sem strict_types).
set -euo pipefail

escopo=('app/src/*.php' 'app/tests/*.php' 'app/migrations/*.php')
# Sem isto o git devolve nome com acento entre aspas e com escapes octais ("A\303\247..."), e o
# arquivo some do filtro sem aviso.
git() { command git -c core.quotePath=false "$@"; }

if [ "${1:-}" = "--local" ]; then
    base=$(git merge-base "${2:-origin/master}" HEAD)
    {
        git diff --name-only --diff-filter=A -M "$base" -- "${escopo[@]}"
        git ls-files --others --exclude-standard -- "${escopo[@]}"
    } | sed 's#^app/##' | sort -u
    exit 0
fi

base="${1:-}"
if [ -z "$base" ] || ! git cat-file -e "$base^{commit}" 2>/dev/null; then
    echo "qa-arquivos-novos: base '$base' desconhecida; sem ela não há como saber o que é novo." >&2
    exit 2
fi
git diff --name-only --diff-filter=A -M "$base...HEAD" -- "${escopo[@]}" | sed 's#^app/##' | sort -u
