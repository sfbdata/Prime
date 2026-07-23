#!/usr/bin/env bash
# Abre uma frente de trabalho isolada: worktree + banco de teste proprio + tudo que
# o gitignore NAO leva junto (vendor, dirs de upload).
#
# Uso:  scripts/frente-abrir.sh <nome-da-frente> [branch-base]
#
# A base padrao e origin/master. Passar outra branch e o caso do "empilhamento
# declarado": quando a frente depende de outra que ainda nao foi publicada (porque
# esta travada num portao de decisao, por exemplo). Empilhar assim e legitimo — o
# que nao pode e empilhar por inercia, sem ninguem ter decidido.
set -euo pipefail

nome="${1:-}"
base="${2:-origin/master}"

if [ -z "$nome" ]; then
    echo "uso: scripts/frente-abrir.sh <nome-da-frente> [branch-base]" >&2
    exit 1
fi
if ! [[ "$nome" =~ ^[a-z0-9][a-z0-9._/-]*$ ]]; then
    echo "erro: nome de frente invalido ('$nome'). Use minusculas, numeros, . _ - /" >&2
    exit 1
fi

raiz=$(git rev-parse --show-toplevel)
comum=$(git rev-parse --git-common-dir)
# Rodar de dentro de outra worktree criaria a frente nova aninhada na anterior.
if [ "$(cd "$comum" && pwd)" != "$raiz/.git" ]; then
    echo "erro: rode a partir do repositorio principal, nao de dentro de uma worktree." >&2
    exit 1
fi

destino="$raiz/.claude/worktrees/$nome"
container=jusprime_php_dev
caminho_container="/var/www/.claude/worktrees/$nome/app"

if [ -e "$destino" ]; then
    echo "erro: ja existe $destino" >&2
    exit 1
fi
if git show-ref --verify --quiet "refs/heads/$nome"; then
    echo "erro: a branch '$nome' ja existe. Escolha outro nome ou apague a antiga." >&2
    exit 1
fi
if ! docker ps --format '{{.Names}}' | grep -qx "$container"; then
    echo "erro: container $container nao esta rodando. Suba o ambiente primeiro." >&2
    exit 1
fi

echo "==> atualizando refs remotas"
git fetch origin --quiet

if ! git rev-parse --verify --quiet "$base^{commit}" >/dev/null; then
    echo "erro: base '$base' nao existe." >&2
    exit 1
fi

echo "==> criando worktree em .claude/worktrees/$nome (base: $base)"
git worktree add "$destino" -b "$nome" "$base"

echo "==> marcando a frente (lido pelo hook pre-commit)"
echo "$nome" > "$destino/.frente"

# vendor/ e gitignored (299M): a worktree nasce SEM ele e o phpunit falha seco.
echo "==> composer install (a worktree nasce sem vendor/)"
docker exec "$container" bash -c "cd '$caminho_container' && composer install --no-interaction"

# public/uploads/ tambem e gitignored: sem os dirs, upload quebra no smoke por
# permissao, nao por codigo.
echo "==> criando dirs de upload e alinhando o dono"
docker exec "$container" bash -c \
    "mkdir -p '$caminho_container'/public/uploads/{chamados,clientes,cobrancas,justificativas,pastas,perfil,tarefas}"
docker exec -u 0 "$container" bash -c \
    "chown -R 1000:1000 '$caminho_container'/public/uploads" || \
    echo "    aviso: nao consegui alinhar o dono dos uploads (siga e confira no smoke)"

# O banco da frente e um CLONE do saas_test. As duas alternativas obvias foram testadas
# e as duas produzem banco errado:
#   - `migrations:migrate` num banco vazio nem completa (parte das migrations supoe dados
#     que so existem em banco vindo de dump; ex.: Version20260508170000 vincula o usuario
#     E2E ao tenant 1 fixo);
#   - `schema:create` completa, mas produz um banco INCOMPLETO: sai sem a extensao
#     `unaccent`, sem as 4 funcoes do schema public e com 2 indices a menos, porque nada
#     disso vem do mapeamento das entidades — vem de SQL cru das migrations. Medido: a
#     suite dava 12 erros + ~10 falhas, quase todas de busca livre/acento, contra 2464/2464
#     verde no repo principal. TEMPLATE copia extensoes, funcoes e indices junto.
echo "==> clonando banco de teste (saas_test -> saas_test$nome)"
if ! docker exec jusprime_db_dev psql -U symfony -d postgres -tAc \
        "SELECT 1 FROM pg_database WHERE datname='saas_test';" | grep -q 1; then
    echo "erro: banco modelo 'saas_test' nao existe. Prepare-o antes de abrir frentes." >&2
    exit 1
fi
docker exec jusprime_db_dev psql -U symfony -d postgres \
    -c "DROP DATABASE IF EXISTS \"saas_test$nome\";" >/dev/null
if ! docker exec jusprime_db_dev psql -U symfony -d postgres \
        -c "CREATE DATABASE \"saas_test$nome\" TEMPLATE \"saas_test\";" >/dev/null; then
    echo "erro: clone falhou. TEMPLATE exige que ninguem esteja conectado ao saas_test —" >&2
    echo "      espere a suite do repositorio principal terminar e repita." >&2
    exit 1
fi

cat <<FIM

Frente '$nome' aberta.

  pasta:  $destino
  base:   $base
  banco:  saas_test$nome  (TEST_TOKEN=$nome)

  testar:  scripts/frente-testar.sh $nome
  fechar:  scripts/frente-fechar.sh $nome

Registre a frente em docs/frentes-ativas.md — dominio, se mexe em migration e
quais arquivos compartilhados toca. Sem esse registro duas sessoes nao tem como
saber uma da outra.
FIM
