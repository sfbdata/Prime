#!/usr/bin/env bash
# Aplica migrations JÁ MERGEADAS nos bancos que o localhost:8080 usa: o de dev e o de teste da pasta principal.
#
# Uso:  scripts/migrar-dev.sh Version20261018150000 [Version... ]
#
# Existe por causa de um engano repetido: rodar o console de uma WORKTREE aplica no `saas` (a worktree não tem
# app/.env.local, cai no app/.env), mas o 8080 serve a pasta principal, que usa o banco do app/.env.local dela
# (o `saas_ux` neste PC). Em 18/10 a Central quebrou no 8080 ("coluna limpa_ate_id não existe") por isso.
#
# O que o script garante:
#  - roda o console da PASTA PRINCIPAL (/var/www/app): o banco é o do .env.local dela, sem adivinhar DATABASE_URL;
#  - só aplica migration que está no origin/master e na pasta principal: migration de frente sem merge quebraria
#    o 8080, que roda o código do master;
#  - só as versões passadas, uma a uma (`migrations:execute --up`), nunca `migrations:migrate`: o ledger dos bancos
#    de dev não tem duas migrations antigas cujas tabelas já existem (só não quebram por `skipIf`);
#  - aplica no `dev` e no `test` (o saas_test da suíte da pasta principal) e pula a que já está no ledger.
#
# Antes: master local atualizado (o atualizador do início da sessão ou `gh merge-atualiza <PR>`).
set -euo pipefail

if [ "$#" -eq 0 ]; then
    echo "uso: scripts/migrar-dev.sh Version<14 dígitos> [Version... ]" >&2
    exit 1
fi

raiz=$(git rev-parse --show-toplevel)
case "$raiz" in
    */.claude/worktrees/*) principal="${raiz%%/.claude/worktrees/*}" ;;
    *) principal="$raiz" ;;
esac
container=jusprime_php_dev
console='cd /var/www/app && php -d memory_limit=1G bin/console'

if ! docker inspect "$container" >/dev/null 2>&1; then
    echo "erro: o container $container não existe ou não responde; suba o ambiente (docker compose up -d) e repita" >&2
    exit 1
fi
git -C "$principal" fetch -q origin master

# Confere TODAS antes de aplicar qualquer uma (git, arquivo e a situação no ledger dos dois bancos): ou a lista
# inteira é válida, ou nada muda.
versoes=()
for arg in $(printf '%s\n' "$@" | awk '!visto[$0]++'); do   # repetida conta uma vez
    v="${arg#DoctrineMigrations\\}"
    if ! [[ "$v" =~ ^Version[0-9]{14}$ ]]; then
        echo "erro: '$arg' não é uma versão (esperava Version + 14 dígitos)" >&2
        exit 1
    fi
    if ! git -C "$principal" cat-file -e "origin/master:app/migrations/$v.php" 2>/dev/null; then
        echo "erro: $v não está no origin/master. Migration de frente sem merge não vai para o banco do 8080." >&2
        exit 1
    fi
    if [ ! -f "$principal/app/migrations/$v.php" ]; then
        echo "erro: $v está no origin/master, mas não na pasta principal: atualize o master local e repita" >&2
        exit 1
    fi
    versoes+=("$v")
done

situacao() {   # $1 = tabela do doctrine:migrations:list, $2 = versão → migrated | not migrated | (vazio)
    printf '%s\n' "$1" | awk -F'|' -v alvo="DoctrineMigrations\\\\$2" '{ gsub(/^ +| +$/, "", $2); gsub(/^ +| +$/, "", $3) } $2 == alvo { print $3 }'
}

declare -A banco lista
for env in dev test; do
    banco[$env]=$(docker exec "$container" bash -c "$console dbal:run-sql 'SELECT current_database()' --env=$env" | sed -n '4p' | tr -d ' ')
    lista[$env]=$(docker exec "$container" bash -c "$console doctrine:migrations:list --env=$env")
    for v in "${versoes[@]}"; do
        case "$(situacao "${lista[$env]}" "$v")" in
            migrated|"not migrated") ;;
            *) echo "erro: o console de $env (${banco[$env]}) não conhece $v; limpe o cache e repita. Nada foi aplicado." >&2
               exit 1 ;;
        esac
    done
done

for env in dev test; do
    echo "==> $env: banco ${banco[$env]}"
    for v in "${versoes[@]}"; do
        if [ "$(situacao "${lista[$env]}" "$v")" = migrated ]; then
            echo "    $v: já aplicada"
            continue
        fi
        saida=$(docker exec "$container" bash -c "$console doctrine:migrations:execute --up 'DoctrineMigrations\\$v' --no-interaction --env=$env" 2>&1) || {
            printf '%s\n' "$saida" >&2
            echo "erro: $v falhou em $env (${banco[$env]}); o que já saiu acima ficou aplicado, rodar de novo pula o feito" >&2
            exit 1
        }
        # Uma migration com skipIf verdadeiro entra no ledger sem rodar SQL: dizer "aplicada" seria mentir.
        if printf '%s\n' "$saida" | grep -qi 'skip'; then
            echo "    $v: PULADA pelo skipIf (nenhum SQL rodou):"
            printf '%s\n' "$saida" | grep -i 'skip' | sed 's/^/      /'
        else
            echo "    $v: aplicada"
        fi
    done
done
