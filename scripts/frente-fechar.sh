#!/usr/bin/env bash
# Prepara a frente para integracao e PARA antes do merge.
#
# merge/push/deploy sao do humano — o script monta os comandos e entrega.
# O que ele faz de util e o que costuma ser pulado: trazer o master atual para
# DENTRO da frente e provar a combinacao, porque "verde na branch" so prova
# master+A, e nunca master+A+B.
#
# Uso:  scripts/frente-fechar.sh <nome-da-frente>
set -euo pipefail

nome="${1:-}"
if [ -z "$nome" ]; then
    echo "uso: scripts/frente-fechar.sh <nome-da-frente>" >&2
    exit 1
fi

raiz=$(git rev-parse --show-toplevel)
worktree="$raiz/.claude/worktrees/$nome"
container=jusprime_php_dev
caminho_container="/var/www/.claude/worktrees/$nome/app"

[ -d "$worktree" ] || { echo "erro: frente '$nome' nao encontrada em $worktree" >&2; exit 1; }

sujo=$(git -C "$worktree" status --porcelain)
if [ -n "$sujo" ]; then
    echo "erro: a frente tem alteracoes nao commitadas. Commite antes de fechar." >&2
    echo "$sujo" >&2
    exit 1
fi

echo "==> atualizando refs remotas"
git fetch origin --quiet

echo
echo "=============================================================="
echo " 1. A frente esta atras do master?"
echo "=============================================================="
atras=$(git -C "$worktree" rev-list --count "$nome..origin/master")
echo "commits do origin/master que a frente ainda nao tem: $atras"
if [ "$atras" -gt 0 ]; then
    cat <<FIM

A frente precisa absorver o master ANTES de ser integrada, senao a suite dela
prova uma combinacao que nao e a que vai para producao:

  git -C "$worktree" merge origin/master

(merge e do humano — rode manualmente e resolva o que conflitar.)
FIM
fi

echo
echo "=============================================================="
echo " 2. Migrations: a ordem do arquivo bate com a ordem do merge?"
echo "=============================================================="
# O Doctrine executa por ordem de VERSAO, nao por ordem de merge. Enquanto as duas
# coincidem nada acontece; quando divergem, o schema de producao passa a diferir do
# que qualquer ambiente novo produz — e isso so aparece quando alguem reconstroi.
dir_mig="app/migrations"
minhas=$(git -C "$worktree" diff --name-only --diff-filter=A "origin/master...$nome" -- "$dir_mig" || true)
if [ -z "$minhas" ]; then
    echo "a frente nao acrescenta migration. Nada a coordenar aqui."
else
    echo "migrations da frente:"; echo "$minhas" | sed 's/^/  /'
    ultima_master=$(git -C "$worktree" ls-tree --name-only origin/master "$dir_mig/" | sort | tail -1)
    echo "ultima migration ja no origin/master:"; echo "  ${ultima_master:-(nenhuma)}"
    echo
    echo "Confira: o timestamp de cada migration da frente e POSTERIOR ao do master?"
    echo "Se nao for, renomeie arquivo E classe para um posterior antes de integrar."
    echo
    echo "E prove o par recriando o banco do zero — so isso executa as duas na ordem"
    echo "canonica (rodar migrate num banco que ja tem a sua apenas empilha a outra"
    echo "por cima e reproduz a ordem de producao, nao a canonica):"
    cat <<FIM

  docker exec -e TEST_TOKEN=$nome $container bash -c '
    cd $caminho_container &&
    php bin/console doctrine:database:drop --env=test --force --if-exists &&
    php bin/console doctrine:database:create --env=test &&
    php bin/console doctrine:migrations:migrate --env=test -n'
FIM
    echo
    echo "Se a sua migration e a do master tocam a MESMA tabela, leia as duas antes"
    echo "de integrar: ordem correta nao salva de alteracoes incompativeis."
fi

echo
echo "=============================================================="
echo " 3. Suite da frente"
echo "=============================================================="
"$raiz/scripts/frente-testar.sh" "$nome"

cat <<FIM

=============================================================="
 4. Integracao — SUA, nao do script
==============================================================

# Execute manualmente no terminal externo
git -C "$raiz" switch master
git -C "$raiz" merge --no-ff $nome
scripts/frente-testar.sh master        # a prova que vale: a suite DEPOIS do merge
# só entao: push e deploy

Encerrar a frente depois de integrada:

  git worktree remove "$worktree"
  git branch -d $nome                  # -d recusa branch nao integrada; nunca use -D
  # e tire a linha dela de docs/frentes-ativas.md
FIM
