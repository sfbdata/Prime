#!/usr/bin/env bash
# Prova que o PHP-FPM da imagem NÃO monta $_SERVER['argv'] a partir da query string, e que a
# CLI (bin/console, worker do messenger, entrypoint) continua recebendo argumentos.
#
# Por que existe: sem php.ini, o PHP sobe com register_argc_argv=On também no FPM. Aí uma
# requisição como `GET /?=+--env=xyz` chega ao SymfonyRuntime com $_GET VAZIO e
# argv = ['=', '--env=xyz'] — o atalho que a correção de 2024 (CVE-2024-50340) não fecha
# (CVE-2026-47767 / CVE-2026-46626). O runtime lê `--env`/`--no-debug` desse argv antes do
# roteamento e do login: qualquer visitante anônimo troca o APP_ENV da requisição.
#
# O teste usa o PHP-FPM DE VERDADE: constrói o stage `base` do Dockerfile (o mesmo de que
# dev, prod e worker herdam), sobe o php-fpm num container descartável e fala FastCGI com
# ele, sem nginx. Nada de produção nem do ambiente de dev é tocado.
#
# Uso: bash scripts/testar-register-argc-argv.sh
set -uo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
IMAGEM="jusprime-teste-argv:local"
ok=0; falhou=0

verde()   { printf '  \033[32m✓\033[0m %s\n' "$1"; ok=$((ok+1)); }
vermelho(){ printf '  \033[31m✗ %s\033[0m\n' "$1"; falhou=$((falhou+1)); }

echo "── Construindo o stage base do Dockerfile ──"
if ! docker build -q --target base -t "$IMAGEM" "$RAIZ" >/dev/null; then
    echo "erro: build do stage base falhou" >&2
    exit 2
fi

dir="$(mktemp -d)"
container=""
limpar() {
    [[ -n "$container" ]] && docker stop "$container" >/dev/null 2>&1
    rm -rf "$dir"
}
trap limpar EXIT

# Sonda servida pelo FPM: o que o PHP montou para esta requisição.
cat > "$dir/sonda.php" <<'PHP'
<?php
echo json_encode([
    'sapi' => PHP_SAPI,
    'register_argc_argv' => ini_get('register_argc_argv'),
    'tem_argv' => isset($_SERVER['argv']) && $_SERVER['argv'] !== [],
    'argv' => $_SERVER['argv'] ?? null,
    'get' => $_GET,
]);
PHP

# Cliente FastCGI mínimo (sem cgi-fcgi na imagem): uma requisição GET, devolve o corpo.
cat > "$dir/fcgi.php" <<'PHP'
<?php
[, $script, $query] = $argv;
$registro = fn (int $tipo, string $conteudo) => pack('CCnnCx', 1, $tipo, 1, strlen($conteudo), 0) . $conteudo;
$par = function (string $k, string $v): string {
    $tam = fn (string $s) => strlen($s) < 128 ? chr(strlen($s)) : pack('N', strlen($s) | 0x80000000);
    return $tam($k) . $tam($v) . $k . $v;
};
$params = '';
foreach ([
    'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REQUEST_METHOD' => 'GET',
    'SCRIPT_FILENAME' => $script, 'SCRIPT_NAME' => '/index.php', 'QUERY_STRING' => $query,
    'REQUEST_URI' => '/index.php?' . $query,
] as $k => $v) {
    $params .= $par($k, $v);
}
$s = stream_socket_client('tcp://127.0.0.1:9000', $errno, $erro, 5) or exit("sem conexão: $erro\n");
fwrite($s, $registro(1, pack('nCx5', 1, 0)) . $registro(4, $params) . $registro(4, '') . $registro(5, ''));
$saida = '';
while (strlen($cab = fread($s, 8)) === 8) {
    $r = unpack('Cver/Ctipo/nid/ntam/Cpad/Cres', $cab);
    $conteudo = $r['tam'] ? stream_get_contents($s, $r['tam']) : '';
    if ($r['pad']) { fread($s, $r['pad']); }
    if ($r['tipo'] === 6) { $saida .= $conteudo; }
    if ($r['tipo'] === 3) { break; }
}
echo substr($saida, strpos($saida, "\r\n\r\n") + 4);
PHP
chmod 644 "$dir"/*.php
chmod 755 "$dir"

container="$(docker run -d --rm -v "$dir:/sonda:ro" "$IMAGEM" php-fpm)"
for _ in $(seq 1 50); do
    docker exec "$container" php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);' 2>/dev/null && break
    sleep 0.2
done

requisicao() { docker exec "$container" php /sonda/fcgi.php /sonda/sonda.php "$1"; }

echo "── PHP-FPM: a query string não pode virar argv ──"
for query in '=+--env=xyz' '=+--env=xyz+--no-debug' '=+-e+xyz'; do
    resposta="$(requisicao "$query")"
    if [[ "$resposta" == *'"sapi":"fpm-fcgi"'* && "$resposta" == *'"tem_argv":false'* ]]; then
        verde "FPM sem argv para ?$query"
    else
        vermelho "FPM montou argv para ?$query → $resposta"
    fi
done

resposta="$(requisicao '')"
if [[ "$resposta" == *'"register_argc_argv":"0"'* ]]; then
    verde "FPM com register_argc_argv=0"
else
    vermelho "FPM com register_argc_argv ligado → $resposta"
fi

resposta="$(requisicao 'pagina=2&ordem=nome')"
if [[ "$resposta" == *'"get":{"pagina":"2","ordem":"nome"}'* ]]; then
    verde "query string comum continua chegando em \$_GET"
else
    vermelho "\$_GET quebrou → $resposta"
fi

echo "── CLI: argumentos continuam chegando (bin/console, worker, entrypoint) ──"
saida="$(docker exec "$container" php -r 'echo json_encode([ini_get("register_argc_argv"), $argv, $_SERVER["argv"] ?? null]);' -- messenger:consume async --time-limit=3600)"
if [[ "$saida" == '["1",["Standard input code","messenger:consume","async","--time-limit=3600"],["Standard input code","messenger:consume","async","--time-limit=3600"]]' ]]; then
    verde "CLI recebe \$argv e \$_SERVER['argv']"
else
    vermelho "CLI perdeu argumentos → $saida"
fi

echo
echo "Resultado: $ok ok, $falhou falha(s)"
[[ "$falhou" -eq 0 ]]
