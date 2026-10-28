#!/usr/bin/env bash
# Sobe o servidor embutido do PHP (php -S) DENTRO do container jusprime_php_dev, apontando para a worktree da
# frente, numa porta própria. Serve para o dono olhar uma frente na tela SEM mexer na 8080 (que é do master).
#
# Uso:  scripts/servir-frente.sh <worktree> <porta>
#       scripts/servir-frente.sh ia-chat-pagina 8091
#
# Roda em PRIMEIRO PLANO. Para parar: Ctrl+C (o trap encerra o php -S no container). Ninguém deixa o servidor rodando.
# Guia: docs/guias/chat-ia-dev.md.
set -euo pipefail

container=jusprime_php_dev

uso() {
    echo "uso: scripts/servir-frente.sh <worktree> <porta>" >&2
    echo "     ex.: scripts/servir-frente.sh ia-chat-pagina 8091" >&2
}

if [ $# -ne 2 ]; then
    uso
    exit 1
fi

# Aceita o nome da frente ou um caminho que termine nele (…/.claude/worktrees/ia-chat-pagina).
nome=$(basename "${1%/}")
porta="$2"

if ! [[ "$nome" =~ ^[a-z0-9][a-z0-9._-]*$ ]]; then
    echo "erro: nome de worktree invalido ('$nome'). Use minusculas, numeros, . _ -" >&2
    exit 1
fi
if ! [[ "$porta" =~ ^[0-9]+$ ]] || [ "$porta" -lt 1024 ] || [ "$porta" -gt 65535 ]; then
    echo "erro: porta invalida ('$porta'). Use um numero entre 1024 e 65535." >&2
    exit 1
fi
if [ "$porta" -eq 8080 ]; then
    echo "erro: a 8080 e do master. Escolha outra porta." >&2
    exit 1
fi

# O caminho DENTRO do container: o repositorio principal e montado em /var/www.
caminho="/var/www/.claude/worktrees/$nome"

if ! docker exec "$container" test -f "$caminho/app/public/index.php"; then
    echo "erro: nao achei $caminho/app/public/index.php dentro do container $container." >&2
    echo "      A worktree existe? O container esta de pe? (scripts/frente-abrir.sh $nome)" >&2
    exit 1
fi
if ! docker exec "$container" test -d "$caminho/app/vendor"; then
    echo "erro: $caminho/app/vendor nao existe (rode composer install na worktree, dentro do container)." >&2
    exit 1
fi

ip=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' "$container" | awk '{print $1}')
if [ -z "$ip" ]; then
    echo "erro: nao consegui descobrir o IP do container $container." >&2
    exit 1
fi

echo "==> frente:   $nome"
echo "==> codigo:   $caminho/app"
echo "==> abra no navegador:  http://$ip:$porta/"
echo "==> parar:    Ctrl+C neste terminal (o servidor roda em primeiro plano)."
echo "==> banco:    o de DESENVOLVIMENTO do container (o mesmo da 8080); entre com um usuario de dev."
echo

# O php -S de processo unico trava durante o SSE do chat: PHP_CLI_SERVER_WORKERS=4 cria 4 processos filhos.
# O `docker exec` NAO encerra o processo do container quando o terminal fecha, entao o trap derruba o php -S desta porta
# (Ctrl+C, terminal fechado ou kill do script). O container nao tem pkill nem pgrep: varre o /proc.
parar_servidor() {
    docker exec "$container" sh -c '
        for p in /proc/[0-9]*; do
            c=$(tr "\0" " " < "$p/cmdline" 2>/dev/null)
            case "$c" in "php -S 0.0.0.0:'"$porta"' "*) kill "${p#/proc/}" 2>/dev/null ;; esac
        done' >/dev/null 2>&1 || true
}
trap parar_servidor EXIT INT TERM HUP

docker exec -it -e APP_ENV=dev -e PHP_CLI_SERVER_WORKERS=4 "$container" \
    php -S "0.0.0.0:$porta" -t "$caminho/app/public" "$caminho/app/public/index.php" || true
