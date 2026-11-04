#!/bin/sh
# Builder dos blogs (spec docs/specs/painel-artigos-blogs.md, seção 6.1, contrato versão 1).
#
# Vigia a pasta dos pedidos que o worker do BlueJus monta. Um pedido por vez:
#   1. a cada 5 s, pega o menor {id} em $PEDIDOS sem resultado.json e sem nome começando com ponto;
#   2. escreve `iniciado` (data ISO) na pasta do pedido;
#   3. git fetch do ramo no clone de $CACHE e cópia limpa numa pasta temporária; guarda o commit;
#   4. para blog e depois blog-gratis: npm ci, npm test (sem ARTIGOS) e ARTIGOS=... npm run build;
#   5. só se tudo passar nos dois: rsync -a --delete dist/ para $PUBLICADOS/{blog,gratis}/;
#   6. escreve resultado.json (via resultado.json.tmp e mv).
# Erro num pedido nunca derruba o laço. `--uma-vez` processa no máximo um pedido e sai (testes).
#
# sh POSIX (ash do Alpine em produção, dash no contêiner de testes). Sem jq nem node para o JSON: o escape
# do log é feito em awk, byte a byte.

PEDIDOS=${PEDIDOS:-/pedidos}
PUBLICADOS=${PUBLICADOS:-/sites-publicados}
CACHE=${CACHE:-/cache}
CHAVE=${CHAVE:-/chave}
REPOSITORIO=${REPOSITORIO:-git@github.com:sfbdata/sites-bluejus.git}
RAMO=${RAMO:-main}
INTERVALO=${INTERVALO:-5}

GIT_SSH_COMMAND="ssh -i $CHAVE -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=$CACHE/known_hosts"
export GIT_SSH_COMMAND
# Os testes do repositório rodam com os artigos de teste dele: ARTIGOS só existe no build.
unset ARTIGOS

# Pedidos cujo resultado.json não pôde ser gravado nesta execução: sem a lista, o laço refaria o mesmo
# build a cada 5 s.
IGNORADOS=' '

agora() {
    date -u +%Y-%m-%dT%H:%M:%SZ
}

# Lê o arquivo do 1º argumento e imprime o conteúdo como string JSON (com as aspas). Tira as cores ANSI do
# npm/Astro (ESC[...m e o "[32m" que sobra quando o ESC já se perdeu), os caracteres de controle (menos tab e
# quebra de linha) e escapa barra invertida, aspas, tab e quebra de linha. LC_ALL=C faz o awk andar por bytes:
# o UTF-8 passa intacto.
string_json() {
    esc=$(printf '\033')
    LC_ALL=C sed -e "s/${esc}\[[0-9;?]*[A-Za-z]//g" -e 's/\[[0-9][0-9;]*m//g' < "$1" \
        | LC_ALL=C tr -d '\000-\010\013-\037\177' | LC_ALL=C awk '
        BEGIN { printf "%s", "\"" }
        {
            if (NR > 1) printf "%s", "\\n"
            n = length($0)
            for (i = 1; i <= n; i++) {
                c = substr($0, i, 1)
                if (c == "\\") printf "%s", "\\\\"
                else if (c == "\"") printf "%s", "\\\""
                else if (c == "\t") printf "%s", "\\t"
                else printf "%s", c
            }
        }
        END { printf "%s", "\"" }'
}

# O menor id pendente: só nomes numéricos (o que começa com ponto, como .12.montando, fica de fora).
proximo_pedido() {
    [ -d "$PEDIDOS" ] || return 0
    ls -1 "$PEDIDOS" 2>/dev/null | grep -E '^[0-9]+$' | sort -n | while read -r id; do
        [ -d "$PEDIDOS/$id" ] || continue
        [ -e "$PEDIDOS/$id/resultado.json" ] && continue
        case "$IGNORADOS" in *" $id "*) continue ;; esac
        echo "$id"
        break
    done
}

# Atualiza o clone de $CACHE (repositório bare, só o ramo) e tira dele uma cópia limpa em "$1/sites".
buscar_codigo() {
    destino=$1
    clone=$CACHE/repositorio.git
    if [ ! -d "$clone" ]; then
        git init --quiet --bare "$clone" || return 1
        git -C "$clone" remote add origin "$REPOSITORIO" || return 1
    fi
    git -C "$clone" remote set-url origin "$REPOSITORIO" || return 1
    git -C "$clone" fetch --quiet --prune origin "+refs/heads/$RAMO:refs/heads/$RAMO" || return 1
    git clone --quiet --shared --branch "$RAMO" "$clone" "$destino/sites" || return 1
}

# Roda npm ci, npm test e o build com os artigos do pedido, na pasta do blog ($1) do pedido ($2).
gerar_blog() {
    pasta=$1
    pedido=$2
    echo "== $pasta: npm ci"
    (cd "$trabalho/sites/$pasta" && npm ci) || return 1
    echo "== $pasta: npm test"
    (cd "$trabalho/sites/$pasta" && npm test) || return 1
    echo "== $pasta: npm run build (ARTIGOS=$pedido/artigos/$pasta)"
    (cd "$trabalho/sites/$pasta" && ARTIGOS="$pedido/artigos/$pasta" npm run build) || return 1
}

# Os passos 3 a 5. Saída 0 = no ar. Grava o commit em $trabalho/commit.
gerar_e_publicar() {
    pedido=$1
    echo "== git fetch $RAMO de $REPOSITORIO"
    buscar_codigo "$trabalho" || { echo "falhou: git fetch ou cópia do código"; return 1; }
    git -C "$trabalho/sites" rev-parse --short=7 HEAD > "$trabalho/commit" || return 1
    echo "== commit $(cat "$trabalho/commit")"
    gerar_blog blog "$pedido" || { echo "falhou: blog"; return 1; }
    gerar_blog blog-gratis "$pedido" || { echo "falhou: blog-gratis"; return 1; }
    # O BlueJus apaga a pasta do pedido que deu como falho (30 min sem resultado): esse não vai ao ar.
    [ -d "$pedido" ] || { echo "falhou: a pasta do pedido sumiu durante a geração"; return 1; }
    echo "== rsync blog"
    rsync -a --delete "$trabalho/sites/blog/dist/" "$PUBLICADOS/blog/" || { echo "falhou: rsync blog"; return 1; }
    echo "== rsync gratis"
    rsync -a --delete "$trabalho/sites/blog-gratis/dist/" "$PUBLICADOS/gratis/" || { echo "falhou: rsync gratis"; return 1; }
    echo "== no ar"
}

processar() {
    id=$1
    pedido=$PEDIDOS/$id
    iniciada=$(agora)
    printf '%s\n' "$iniciada" > "$pedido/iniciado" || return 1
    trabalho=$(mktemp -d) || return 1
    : > "$trabalho/commit"

    if gerar_e_publicar "$pedido" > "$trabalho/log" 2>&1; then
        situacao=no_ar
    else
        situacao=falhou
    fi
    terminada=$(agora)

    commit=$(cat "$trabalho/commit")
    case "$commit" in
        ''|*[!0-9a-f]*) commit_json=null ;;
        *) commit_json="\"$commit\"" ;;
    esac
    tail -n 200 "$trabalho/log" > "$trabalho/log200"
    {
        printf '{"versao":1,"id":%s,"situacao":"%s","iniciadaEm":"%s","terminadaEm":"%s","commit":%s,"log":' \
            "$id" "$situacao" "$iniciada" "$terminada" "$commit_json"
        string_json "$trabalho/log200"
        printf '}\n'
    } > "$pedido/resultado.json.tmp" && mv "$pedido/resultado.json.tmp" "$pedido/resultado.json"
    gravou=$?

    echo "pedido $id: $situacao (commit ${commit:-nenhum})"
    rm -rf "$trabalho"
    return $gravou
}

uma_vez=0
[ "${1:-}" = "--uma-vez" ] && uma_vez=1

# Um builder por vez: dois laços (um `docker compose run` manual ao lado do serviço, por exemplo) gerariam o
# mesmo pedido e brigariam pelo rsync. A trava fica em $PEDIDOS/.trava (nome com ponto: o laço a ignora) e vale
# enquanto este processo viver; o descritor 9 fica aberto até o fim.
if ! exec 9>>"$PEDIDOS/.trava"; then
    echo "builder: não consegui abrir a trava $PEDIDOS/.trava; saindo" >&2
    exit 1
fi
if ! flock -n 9; then
    echo "builder: outro builder já está rodando (trava $PEDIDOS/.trava ocupada); saindo sem processar nada" >&2
    exit 1
fi

while :; do
    id=$(proximo_pedido)
    if [ -n "$id" ]; then
        # Subshell: erro (ou exit) dentro do pedido não derruba o laço.
        ( processar "$id" ) || echo "pedido $id: erro ao processar" >&2
        [ -e "$PEDIDOS/$id/resultado.json" ] || [ ! -d "$PEDIDOS/$id" ] || IGNORADOS="$IGNORADOS$id "
    fi
    [ "$uma_vez" = 1 ] && exit 0
    [ -n "$id" ] || sleep "$INTERVALO"
done
