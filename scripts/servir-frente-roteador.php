<?php
// Roteador do php -S de scripts/servir-frente.sh. Com index.php como roteador, o CSS, o JS e as imagens também passariam
// pelo Symfony (404, tela em HTML cru). Aqui o arquivo que existe em public/ é servido pelo próprio php -S; o resto vai ao index.php.
$publico = __DIR__ . '/../app/public';
$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$arquivo = realpath($publico . $caminho);

if ($arquivo !== false && is_file($arquivo) && str_starts_with($arquivo, (string) realpath($publico) . '/') && !str_ends_with($arquivo, '.php')) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publico . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $publico . '/index.php';
