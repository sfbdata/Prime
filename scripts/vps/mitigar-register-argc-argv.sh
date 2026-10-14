#!/usr/bin/env bash
# Mitigação A QUENTE do register_argc_argv no PHP-FPM de produção, sem rebuild nem deploy.
#
# É a mesma linha que o Dockerfile passa a gravar no stage base (commit 179f360e): enquanto o
# deploy dela não sai, este script a aplica no container que está rodando. Vale até o container
# ser RECRIADO — um deploy de um master SEM a correção volta ao estado vulnerável: rode
# `aplicar` de novo depois de qualquer deploy, até o PR da correção estar publicado.
#
# Roda NO HOST da VPS (precisa do docker). Copie o arquivo e rode lá — NÃO por `bash -s < script`:
# aí o script chega pelo stdin, e um `docker exec -i` lá dentro poderia engolir o resto dele.
# `ssh -t` porque aplicar/reverter pedem confirmação no terminal:
#   scp scripts/vps/mitigar-register-argc-argv.sh bluejus:/root/
#   ssh -t bluejus 'bash /root/mitigar-register-argc-argv.sh pericia'
#   ssh -t bluejus 'bash /root/mitigar-register-argc-argv.sh aplicar'
#   ssh -t bluejus 'bash /root/mitigar-register-argc-argv.sh verificar'
#   ssh -t bluejus 'bash /root/mitigar-register-argc-argv.sh reverter'
#
# Etapas:
#   pericia   — só LÊ: guarda var/cache, var/log, logs do php e do nginx, config do FPM e estado do
#               container em $DESTINO. Rode ANTES de aplicar e antes de qualquer deploy (o recreate
#               apaga a camada gravável e o `docker logs` do php — a prova de exploração).
#   aplicar   — pré-condições, mostra o tráfego e PEDE CONFIRMAÇÃO antes de gravar; grava o
#               arquivo do pool, valida com `php-fpm -t` (se falhar, tira o arquivo e NÃO
#               recarrega), recarrega com USR2 e prova o efeito.
#   verificar — prova, por FastCGI direto no FPM, se a query string ainda vira argv.
#   reverter  — pede confirmação, desativa o arquivo (renomeia, não apaga), valida, recarrega e
#               prova a volta. ⚠️ Depois do deploy da correção o arquivo vem da IMAGEM: reverter
#               então desliga a correção definitiva até o próximo recreate.
#
# ⚠️ O reload NÃO preserva requisição em andamento: a imagem tem process_control_timeout = 0, e
# o USR2 corta na hora o que estiver no meio (medido: requisição de 6 s morreu em 1,1 s; nginx
# devolve 502). É o mesmo efeito de um deploy, que recria o container. Escolha uma janela de
# pouco uso — o script mostra as requisições do último minuto e só segue com "SIM".
#
# Prova sem atacar a aplicação: uma sonda própria em /tmp do container é chamada por FastCGI em
# 127.0.0.1:9000 com a query `=+--env=sonda`. Nenhuma requisição chega ao Symfony, nenhum
# var/cache novo é criado. O nginx não alcança /tmp (SCRIPT_FILENAME = $document_root...).
set -euo pipefail

CONTAINER="${CONTAINER:-jusprime_php_prod}"
NGINX="${NGINX:-jusprime_nginx_prod}"
DESTINO="${DESTINO:-/root/pericia-argv}"
CONF=/usr/local/etc/php-fpm.d/zz-seguranca.conf
CONTEUDO=$'[www]\nphp_admin_flag[register_argc_argv] = off'
# Só para o teste local provar o caminho de recusa com uma config inválida. Recusado em produção.
if [[ -n "${CONTEUDO_TESTE:-}" ]]; then
    if [[ "$CONTAINER" == jusprime_php_prod ]]; then
        echo "ERRO: CONTEUDO_TESTE não é aceito contra $CONTAINER." >&2
        exit 64
    fi
    CONTEUDO="$CONTEUDO_TESTE"
fi

etapa="${1:-}"
agora="$(date +%Y%m%d-%H%M%S)"

info() { printf '==> %s\n' "$*"; }
erro() { printf 'ERRO: %s\n' "$*" >&2; }

no_container()      { docker exec "$CONTAINER" "$@"; }
no_container_root() { docker exec -u 0 "$CONTAINER" "$@"; }
# Só onde há entrada a repassar: sem -i o docker exec descarta o stdin e o `cat >` grava VAZIO.
root_com_entrada()  { docker exec -i -u 0 "$CONTAINER" "$@"; }

rodando() { [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" == "true" ]]; }

exigir_container() {
    if ! rodando; then
        erro "container $CONTAINER não está rodando. Nada foi alterado."
        exit 1
    fi
}

# ─── Sonda FastCGI ────────────────────────────────────────────────────────────
remover_sonda() { no_container_root rm -f /tmp/argv-sonda.php /tmp/argv-fcgi.php >/dev/null 2>&1 || true; }

instalar_sonda() {
    trap remover_sonda EXIT
    root_com_entrada sh -c 'cat > /tmp/argv-sonda.php' <<'PHP'
<?php
echo json_encode([
    'sapi' => PHP_SAPI,
    'register_argc_argv' => ini_get('register_argc_argv'),
    'tem_argv' => isset($_SERVER['argv']) && $_SERVER['argv'] !== [],
]);
PHP
    root_com_entrada sh -c 'cat > /tmp/argv-fcgi.php' <<'PHP'
<?php
[, $script, $query] = $argv;
$registro = fn (int $tipo, string $c) => pack('CCnnCx', 1, $tipo, 1, strlen($c), 0) . $c;
$par = function (string $k, string $v): string {
    $tam = fn (string $s) => strlen($s) < 128 ? chr(strlen($s)) : pack('N', strlen($s) | 0x80000000);
    return $tam($k) . $tam($v) . $k . $v;
};
$params = '';
foreach ([
    'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REQUEST_METHOD' => 'GET',
    'SCRIPT_FILENAME' => $script, 'SCRIPT_NAME' => '/sonda.php', 'QUERY_STRING' => $query,
    'REQUEST_URI' => '/sonda.php?' . $query,
] as $k => $v) {
    $params .= $par($k, $v);
}
$s = @stream_socket_client('tcp://127.0.0.1:9000', $errno, $msg, 5);
if (!$s) { fwrite(STDERR, "sem conexão com o FPM: $msg\n"); exit(2); }
stream_set_timeout($s, 10);
fwrite($s, $registro(1, pack('nCx5', 1, 0)) . $registro(4, $params) . $registro(4, '') . $registro(5, ''));
$saida = '';
while (strlen($cab = (string) fread($s, 8)) === 8) {
    $r = unpack('Cver/Ctipo/nid/ntam/Cpad/Cres', $cab);
    $c = $r['tam'] ? stream_get_contents($s, $r['tam']) : '';
    if ($r['pad']) { fread($s, $r['pad']); }
    if ($r['tipo'] === 6) { $saida .= $c; }
    if ($r['tipo'] === 3) { break; }
}
echo substr($saida, (int) strpos($saida, "\r\n\r\n") + 4);
PHP
    no_container_root chmod 644 /tmp/argv-sonda.php /tmp/argv-fcgi.php
}

# Imprime "vulneravel", "mitigado" ou "indefinido" (FPM fora, resposta estranha).
sondar() {
    local resposta
    resposta="$(no_container php /tmp/argv-fcgi.php /tmp/argv-sonda.php '=+--env=sonda' 2>&1)" || true
    if [[ "$resposta" == *'"sapi":"fpm-fcgi"'*'"tem_argv":true'* ]]; then
        echo vulneravel
    elif [[ "$resposta" == *'"sapi":"fpm-fcgi"'*'"register_argc_argv":"0"'*'"tem_argv":false'* ]]; then
        echo mitigado
    else
        echo "indefinido: $resposta" >&2
        echo indefinido
    fi
}

aguardar_fpm() {
    local i
    for i in $(seq 1 40); do
        if no_container php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);' 2>/dev/null; then
            return 0
        fi
        sleep 0.5
    done
    return 1
}

# Valida a config com o MESMO usuário do master (a imagem de prod roda como www-data) e mostra o
# motivo quando recusa.
validar_config() {
    local saida
    if saida="$(no_container php-fpm -t 2>&1)"; then
        return 0
    fi
    printf '%s\n' "$saida" | sed 's/^/    php-fpm -t: /' >&2
    return 1
}

# Mostra o tráfego e só segue com "SIM" digitado no terminal (ou CONFIRMAR=SIM, para o teste).
confirmar_reload() {
    if docker inspect "$NGINX" >/dev/null 2>&1; then
        info "requisições no nginx no último minuto (inclui os outros sites do mesmo nginx): $(docker logs --since 1m "$NGINX" 2>/dev/null | wc -l)"
    fi
    info "o reload CORTA as requisições em andamento (como um deploy)."
    local resposta="${CONFIRMAR:-}"
    if [[ -z "$resposta" ]]; then
        if ! { : < /dev/tty; } 2>/dev/null; then
            erro "sem terminal para confirmar (use ssh -t). Nada foi alterado."
            exit 1
        fi
        read -r -p "Recarregar o PHP-FPM agora? Digite SIM para seguir: " resposta < /dev/tty
    fi
    if [[ "$resposta" != SIM ]]; then
        info "cancelado. Nada foi alterado."
        exit 0
    fi
}

recarregar() {
    # USR2 = reload do master (PID 1, via `exec "$@"` do entrypoint). A imagem não tem o binário
    # `kill`: vai o builtin do sh.
    no_container_root sh -c 'kill -USR2 1'
    sleep 1
    aguardar_fpm
}

# ─── Etapas ───────────────────────────────────────────────────────────────────
pericia() {
    if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
        erro "container $CONTAINER não existe."
        exit 1
    fi
    mkdir -p "$DESTINO"
    chmod 700 "$DESTINO"
    local dir="$DESTINO/$agora"
    mkdir -m 700 "$dir"
    info "guardando evidências em $dir (somente leitura no container)"
    info "⚠️  contém IPs e URLs de clientes (logs) — não anexe nem compartilhe fora da VPS"
    info "espaço livre em $DESTINO: $(df -h --output=avail "$DESTINO" | tail -1 | tr -d ' ')"

    # Inspect SEM .Config.Env: o env_file traz o .env.prod inteiro (senha do banco, APP_SECRET...).
    docker inspect -f 'nome={{.Name}}
imagem={{.Config.Image}} {{.Image}}
criado={{.Created}}
iniciado={{.State.StartedAt}} rodando={{.State.Running}} reinicios={{.RestartCount}}
user={{.Config.User}} cmd={{json .Config.Cmd}} entrypoint={{json .Config.Entrypoint}}' "$CONTAINER" \
        > "$dir/container.txt"

    # Camada gravável: docker cp funciona até com o container parado.
    docker cp "$CONTAINER:/var/www/app/var/log" "$dir/var-log" </dev/null 2>/dev/null \
        || info "sem var/log para copiar"
    local envs=""
    if rodando; then
        no_container sh -c 'ls -la --time-style=full-iso /var/www/app/var/cache; du -sh /var/www/app/var/cache/* 2>/dev/null' \
            > "$dir/var-cache.txt" 2>&1 || true
        envs="$(no_container sh -c 'ls -1 /var/www/app/var/cache 2>/dev/null' || true)"
        no_container php-fpm -tt > "$dir/php-fpm-tt.txt" 2>&1 || true
        no_container sh -c 'ls -la /usr/local/etc/php-fpm.d/; php -i | grep -iE "register_argc_argv|Loaded Configuration"' \
            > "$dir/php-config.txt" 2>&1 || true
    else
        info "⚠️  $CONTAINER parado: copiando var/cache inteiro em vez de listar"
        docker cp "$CONTAINER:/var/www/app/var/cache" "$dir/var-cache" </dev/null 2>/dev/null || true
        envs="$(ls -1 "$dir/var-cache" 2>/dev/null || true)"
    fi

    # Qualquer env além de "prod" em var/cache é sinal de exploração: copia inteiro.
    local suspeitos
    suspeitos="$(printf '%s\n' "$envs" | grep -vxE 'prod|pools|' || true)"
    if [[ -n "$suspeitos" ]]; then
        info "⚠️  ambientes inesperados em var/cache: $(echo "$suspeitos" | tr '\n' ' ')"
        if rodando; then
            while IFS= read -r env; do
                docker cp "$CONTAINER:/var/www/app/var/cache/$env" "$dir/var-cache-$env" </dev/null
            done <<< "$suspeitos"
        fi
    else
        info "var/cache só tem o esperado"
    fi

    # Logs: o do php (stderr do monolog em prod) MORRE no recreate; o do nginx tem os acessos.
    local nome caminho tamanho livre
    for nome in "$CONTAINER" "$NGINX"; do
        if ! docker inspect "$nome" >/dev/null 2>&1; then
            info "container $nome não encontrado — sem log"
            continue
        fi
        caminho="$(docker inspect -f '{{.LogPath}}' "$nome")"
        tamanho="$(du -b "$caminho" 2>/dev/null | cut -f1 || true)"
        livre="$(df -B1 --output=avail "$DESTINO" | tail -1 | tr -d ' ')"
        info "log de $nome: ${tamanho:-tamanho desconhecido} bytes"
        if [[ -n "$tamanho" && "$tamanho" -gt $(( livre / 2 )) ]]; then
            erro "log de $nome maior que metade do espaço livre — não copiado. Libere espaço e repita."
            continue
        fi
        docker logs --timestamps "$nome" 2>&1 | gzip -9 > "$dir/log-$nome.gz"
    done

    if [[ -f "$dir/log-$NGINX.gz" ]]; then
        # Assinaturas: `+--env`, `+--no-debug`, `+-e` (separado ou grudado: -edev), e codificadas.
        zgrep -iE '[?&][^ "]*\+(--env|--no-debug|-e)|%2d%2d(env|no-debug)' "$dir/log-$NGINX.gz" \
            > "$dir/nginx-suspeitos.log" || true
        info "linhas suspeitas no log do nginx: $(wc -l < "$dir/nginx-suspeitos.log") (indício; var/cache é a prova)"
        info "log do nginx cobre desde: $(zcat "$dir/log-$NGINX.gz" | head -1 | cut -c1-30)"
    fi

    (cd "$dir" && find . -type f ! -name SHA256SUMS -exec sha256sum {} + > SHA256SUMS)
    info "pronto: $(ls "$dir" | tr '\n' ' ')"
}

aplicar() {
    exigir_container
    info "pré-condições"
    if ! no_container sh -c 'grep -lx "\[www\]" /usr/local/etc/php-fpm.d/*.conf' >/dev/null; then
        erro "pool [www] não encontrado em php-fpm.d. Nada foi alterado."
        exit 1
    fi
    if ! validar_config; then
        erro "a config ATUAL do FPM já falha em php-fpm -t. Nada foi alterado."
        exit 1
    fi

    instalar_sonda
    local antes; antes="$(sondar)"
    info "antes: $antes"
    if [[ "$antes" == indefinido ]]; then
        erro "não consegui sondar o FPM. Nada foi alterado."
        exit 1
    fi

    if no_container test -e "$CONF"; then
        if [[ "$(no_container cat "$CONF")" != "$CONTEUDO" ]]; then
            erro "$CONF já existe com OUTRO conteúdo. Nada foi alterado; confira à mão."
            exit 1
        fi
        if [[ "$antes" == mitigado ]]; then
            info "✅ já mitigado — nada a fazer"
            return
        fi
        # Arquivo certo no disco mas inativo (ex.: a sessão caiu entre gravar e recarregar).
        info "$CONF já está gravado mas ainda não foi carregado — falta só o reload"
        confirmar_reload
    else
        if [[ "$antes" == mitigado ]]; then
            info "✅ já mitigado por outro caminho — nada a fazer"
            return
        fi
        confirmar_reload
        info "gravando $CONF"
        if ! printf '%s\n' "$CONTEUDO" | root_com_entrada sh -c "cat > '$CONF' && chmod 644 '$CONF'" \
            || [[ "$(no_container cat "$CONF" 2>/dev/null)" != "$CONTEUDO" ]]; then
            no_container_root mv "$CONF" "$CONF.rejeitado-$agora" 2>/dev/null || true
            erro "falha ao gravar ou conteúdo diferente do esperado. Arquivo afastado; nada recarregado."
            exit 1
        fi
        if ! validar_config; then
            no_container_root mv "$CONF" "$CONF.rejeitado-$agora"
            erro "php-fpm -t RECUSOU a config. Arquivo movido para $CONF.rejeitado-$agora; FPM NÃO foi recarregado."
            exit 1
        fi
        info "config válida"
    fi

    info "recarregando o FPM (USR2)"
    if ! recarregar; then
        erro "o FPM não voltou a aceitar conexões em 20 s. Tentando reverter."
        no_container_root mv "$CONF" "$CONF.desativado-$agora" 2>/dev/null || true
        no_container_root sh -c 'kill -USR2 1' 2>/dev/null || true
        aguardar_fpm || erro "FPM continua fora. O container tem restart automático; acompanhe: docker ps; docker logs --tail 50 $CONTAINER"
        exit 1
    fi

    local depois; depois="$(sondar)"
    info "depois: $depois"
    if [[ "$depois" != mitigado ]]; then
        erro "o reload não produziu o efeito esperado. O arquivo ficou (é inofensivo); investigue antes de seguir."
        exit 1
    fi
    info "✅ mitigado. Lembre: some se o container for recriado."
}

verificar() {
    exigir_container
    instalar_sonda
    local estado; estado="$(sondar)"
    info "estado: $estado"
    no_container sh -c "ls -la /usr/local/etc/php-fpm.d/ | grep zz-seguranca || echo '(sem zz-seguranca.conf)'"
    if [[ "$estado" == vulneravel ]] && no_container test -e "$CONF"; then
        info "o arquivo existe mas não está carregado: rode 'aplicar' (ele só recarrega)"
    fi
    [[ "$estado" == mitigado ]]
}

reverter() {
    exigir_container
    if ! no_container test -e "$CONF"; then
        info "$CONF não existe — nada a reverter"
        return
    fi
    info "⚠️  se o deploy da correção já saiu, isto desliga a correção da IMAGEM até o próximo recreate"
    confirmar_reload
    no_container_root mv "$CONF" "$CONF.desativado-$agora"
    if ! validar_config; then
        no_container_root mv "$CONF.desativado-$agora" "$CONF"
        erro "php-fpm -t falhou SEM o arquivo — devolvido ao lugar, nada recarregado."
        exit 1
    fi
    recarregar || { erro "FPM não voltou em 20 s. Acompanhe: docker ps; docker logs --tail 50 $CONTAINER"; exit 1; }
    instalar_sonda
    info "estado após reverter: $(sondar) (esperado: vulneravel)"
    info "arquivo preservado em $CONF.desativado-$agora"
}

case "$etapa" in
    pericia|aplicar|verificar|reverter) "$etapa" ;;
    *) echo "uso: $0 {pericia|aplicar|verificar|reverter}" >&2; exit 64 ;;
esac
