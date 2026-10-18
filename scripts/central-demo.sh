#!/usr/bin/env bash
# Sobe (ou para) a DEMONSTRAÇÃO da Central de uma worktree, para avaliação visual com o dono.
#
# Uso:  scripts/central-demo.sh <frente> <porta>          # sobe em http://127.0.0.1:<porta>
#       scripts/central-demo.sh <frente> <porta> parar    # derruba o servidor e o proxy DESTA frente nessa porta
#
# Servidor embutido do PHP dentro do jusprime_php_dev (APP_ENV=dev) com o router
# scripts/central-demo-router.php: sem a barra de depuração do Symfony e, por padrão, sem o lembrete do ponto (só
# neste servidor; ver o comentário do router). Para manter o lembrete: DEMO_SEM_ALERTA_PONTO=0 scripts/central-demo.sh ...
# O nginx do dev serve o checkout principal na 8080; este servidor serve a WORKTREE. Cai a cada reinício da máquina.
#
# Banco: a worktree não tem app/.env.local (é gitignored), então o demo usa o DATABASE_URL do app/.env, o `saas`.
# NÃO é o banco do 8080 (o `saas_ux`, do .env.local da pasta principal), e não deve ser: a migration de uma frente
# sem merge quebraria o 8080, que roda o código do master. Depois do merge, a migration vai para o banco do 8080
# com scripts/migrar-dev.sh <Version...>.
#
# Rede: a do próprio jusprime_php_dev (o proxy precisa enxergá-lo). O nome muda com a pasta do projeto, porque o
# compose prefixa a rede com o nome do projeto (bluejus_saas_net, jusprime_saas_net...): por isso é descoberto.
#
# Outras sessões usam o mesmo container com servidores próprios (8000, 8001...): este script RECUSA uma porta
# ocupada por servidor ou proxy de outra frente, e só derruba o servidor cujo caminho é o desta worktree.
set -euo pipefail

frente="${1:-}"
porta="${2:-}"
acao="${3:-subir}"
if [ -z "$frente" ] || [ -z "$porta" ]; then
    echo "uso: scripts/central-demo.sh <frente> <porta> [parar]" >&2
    exit 1
fi
if ! [[ "$frente" =~ ^[a-z0-9][a-z0-9-]*$ ]] || ! [[ "$porta" =~ ^[0-9]{4,5}$ ]]; then
    echo "erro: frente só com [a-z0-9-] e porta numérica" >&2
    exit 1
fi

raiz=$(git rev-parse --show-toplevel)
# A partir de uma worktree, o toplevel é a própria worktree; o checkout principal é o pai de .claude/worktrees.
case "$raiz" in
    */.claude/worktrees/*) principal="${raiz%%/.claude/worktrees/*}" ;;
    *) principal="$raiz" ;;
esac
if [ ! -d "$principal/.claude/worktrees/$frente/app" ]; then
    echo "erro: worktree '$frente' não encontrada em $principal/.claude/worktrees/" >&2
    exit 1
fi

container=jusprime_php_dev
proxy="smoke-demo-${frente}-${porta}"
dir_container="/var/www/.claude/worktrees/$frente"
assinatura="-S 0.0.0.0:$porta -t public $dir_container/scripts/central-demo-router.php"


# Servidores PHP nessa porta: os desta frente (pela assinatura) e os de qualquer outra.
servidores=$(docker top "$container" -eo pid,args | grep -F -- "-S 0.0.0.0:$porta " | grep -v grep || true)
alheios=$(printf '%s\n' "$servidores" | grep -vF -- "$assinatura" | grep -F "php" || true)
if [ -n "$alheios" ]; then
    echo "erro: a porta $porta já tem servidor de outra frente; escolha outra porta:" >&2
    printf '%s\n' "$alheios" | cut -c1-160 >&2
    exit 1
fi
dono_porta=$(docker ps --filter "publish=$porta" --format '{{.Names}}' | grep -vx "$proxy" || true)
if [ -n "$dono_porta" ]; then
    echo "erro: a porta $porta já está publicada pelo container '$dono_porta' (outra sessão); escolha outra porta" >&2
    exit 1
fi

# Derruba só o servidor desta frente nessa porta (o container não tem pkill/pgrep).
docker rm -f "$proxy" >/dev/null 2>&1 || true
docker exec "$container" bash -c 'for p in /proc/[0-9]*; do c=$(tr "\0" " " 2>/dev/null < "$p/cmdline") || continue; case "$c" in "php "*"$1"*) kill "${p#/proc/}" 2>/dev/null ;; esac; done; true' _ "$assinatura"

if [ "$acao" = "parar" ]; then
    echo "==> demo $frente:$porta parada"
    exit 0
fi

# A rede vem antes de subir o servidor (e depois do "parar", que não precisa dela): se falhasse depois, sobraria
# um servidor PHP órfão na porta.
if ! redes=$(docker inspect "$container" --format '{{range $nome, $_ := .NetworkSettings.Networks}}{{$nome}}{{"\n"}}{{end}}' 2>/dev/null); then
    echo "erro: o container $container não existe ou não responde; suba o ambiente (docker compose up -d) e repita" >&2
    exit 1
fi
redes=$(printf '%s\n' "$redes" | sed '/^$/d')
if [ -z "$redes" ] || [ "$(printf '%s\n' "$redes" | wc -l)" -ne 1 ]; then
    echo "erro: esperava o $container em exatamente uma rede, achei:" >&2
    printf '%s\n' "${redes:-(nenhuma)}" >&2
    exit 1
fi
rede="$redes"

docker exec -d -e APP_ENV=dev -e PHP_CLI_SERVER_WORKERS=4 -e DEMO_SEM_ALERTA_PONTO="${DEMO_SEM_ALERTA_PONTO:-1}" "$container" \
    bash -c 'cd "$1/app" && php -d memory_limit=512M '"$assinatura"' > "/tmp/demo-$2-$3.log" 2>&1' _ "$dir_container" "$frente" "$porta"
docker run -d --rm --name "$proxy" --network "$rede" -p "127.0.0.1:$porta:$porta" \
    alpine/socat tcp-listen:"$porta",fork,reuseaddr tcp-connect:"$container":"$porta" >/dev/null

echo "==> demo $frente em http://127.0.0.1:$porta (log no container: /tmp/demo-$frente-$porta.log)"
