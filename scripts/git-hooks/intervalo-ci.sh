#!/usr/bin/env bash
# Imprime o intervalo de commits (A..B) que a guarda de segredos do CI deve varrer.
#
# Só os commits NOVOS do evento — nunca o histórico inteiro. É isso que deixa o segredo antigo
# conhecido (3ee0082a, tratado por rotação e não por reescrita) fora do caminho dos PRs, sem
# afrouxar nada para segredo novo.
#
# Uso: intervalo-ci.sh <evento> <pr_base> <pr_head> <push_antes> <push_depois>
#   pull_request → <pr_base>..<pr_head>
#   push         → <push_antes>..<push_depois>; se o "antes" não existe (branch nova, push forçado),
#                  cai para merge-base com origin/master..<push_depois>
set -euo pipefail

evento="${1:-}"; pr_base="${2:-}"; pr_head="${3:-}"; antes="${4:-}"; depois="${5:-}"
zeros=0000000000000000000000000000000000000000

existe() { [ -n "$1" ] && git cat-file -e "$1^{commit}" 2>/dev/null; }

if [ "$evento" = "pull_request" ]; then
    existe "$pr_base" && existe "$pr_head" || { echo "intervalo-ci: SHA do PR ausente" >&2; exit 2; }
    echo "$pr_base..$pr_head"
    exit 0
fi

existe "$depois" || { echo "intervalo-ci: commit do push ausente" >&2; exit 2; }

if [ "$antes" != "$zeros" ] && existe "$antes"; then
    echo "$antes..$depois"
    exit 0
fi

if git rev-parse --verify --quiet origin/master >/dev/null; then
    echo "$(git merge-base origin/master "$depois")..$depois"
else
    echo "$depois^!"   # sem referência de comparação: só o próprio commit
fi
