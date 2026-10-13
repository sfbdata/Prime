#!/usr/bin/env bash
# Imprime o intervalo de commits (A..B) que a guarda de segredos do CI deve varrer.
#
# Só os commits NOVOS do evento — nunca o histórico inteiro. É isso que deixa o segredo antigo
# conhecido (3ee0082a, tratado por rotação e não por reescrita) fora do caminho dos PRs, sem
# afrouxar nada para segredo novo.
#
# Uso: intervalo-ci.sh <evento> <pr_base> <pr_head> <push_antes> <push_depois>
#   pull_request → <pr_base>..<pr_head>
#   push         → <push_antes>..<push_depois>
#
# FALHA FECHADO (saída 2) quando não há como saber o que é novo: push sem "antes" (primeiro push,
# push forçado que reescreveu o master) ou intervalo vazio. A 1ª versão caía para o merge-base com
# origin/master — que, num push para o próprio master, é o commit do topo: intervalo vazio, 0
# commits varridos e CI verde. Push forçado no master é exceção; o CI vermelho é o alarme certo,
# e a resposta é a auditoria completa do runbook (docs/runbooks/guarda-de-segredos.md).
set -euo pipefail

evento="${1:-}"; pr_base="${2:-}"; pr_head="${3:-}"; antes="${4:-}"; depois="${5:-}"
zeros=0000000000000000000000000000000000000000

existe() { [ -n "$1" ] && git cat-file -e "$1^{commit}" 2>/dev/null; }
recusa() { echo "intervalo-ci: $1 — varredura recusada (rode a auditoria completa do runbook)." >&2; exit 2; }

case "$evento" in
    pull_request)
        existe "$pr_base" && existe "$pr_head" || recusa "SHA do PR ausente"
        intervalo="$pr_base..$pr_head"
        ;;
    push)
        existe "$depois" || recusa "commit do push ausente"
        [ "$antes" != "$zeros" ] && existe "$antes" || recusa "push sem commit anterior conhecido (primeiro push ou push forçado)"
        intervalo="$antes..$depois"
        ;;
    *)
        recusa "evento não suportado: '$evento'"
        ;;
esac

[ "$(git rev-list --count "$intervalo")" -gt 0 ] || recusa "intervalo $intervalo vazio"
echo "$intervalo"
