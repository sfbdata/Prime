<?php
// Router de DEMONSTRAÇÃO da Central (servidor embutido do PHP, APP_ENV=dev). Só para avaliação visual com o dono;
// nunca é usado pelo nginx, pelos testes PHPUnit nem em produção. Sobe com scripts/central-demo.sh.
//
// O que ele muda, e só neste servidor:
//   1. Tira do HTML a barra de depuração do Symfony (web profiler). Ela fica no rodapé e cobre o lançador da Central;
//      em produção ela não existe. O profiler continua coletando (o /_profiler segue acessível).
//   2. Com DEMO_SEM_ALERTA_PONTO=1 (padrão do central-demo.sh), o lembrete "Você ainda não registrou sua entrada"
//      (GET /ponto/alerta-horario) responde "sem alerta". Nenhuma regra de ponto muda: bater ponto, jornada e
//      relatórios seguem iguais. Para ver o lembrete: DEMO_SEM_ALERTA_PONTO=0 scripts/central-demo.sh ...
//
// Arquivo estático existente DENTRO de public/ devolve `false` (o servidor o serve com o MIME certo); o resto, inclusive
// /index.php pedido direto, cai na aplicação pelo filtro. A resposta inteira fica no buffer antes de sair (é preciso
// para tirar a barra): serve para a demonstração, não para baixar arquivo grande.
$publico = realpath(dirname(__DIR__) . '/app/public');
$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$arquivo = realpath($publico . $caminho);

if ($arquivo !== false && is_file($arquivo) && str_starts_with($arquivo, $publico . '/') && $arquivo !== $publico . '/index.php') {
    return false;
}

if (getenv('DEMO_SEM_ALERTA_PONTO') === '1' && $caminho === '/ponto/alerta-horario') {
    header('Content-Type: application/json');
    echo '{"alertar":false}';

    return true;
}

ob_start(static function (string $html): string {
    $inicio = strpos($html, '<!-- START of Symfony Web Debug Toolbar -->');
    $fim    = strpos($html, '<!-- END of Symfony Web Debug Toolbar -->');
    if ($inicio === false || $fim === false || $fim < $inicio) {
        return $html;
    }

    return substr($html, 0, $inicio) . substr($html, $fim + strlen('<!-- END of Symfony Web Debug Toolbar -->'));
});

// O front controller espera ser /index.php (SCRIPT_NAME/SCRIPT_FILENAME vêm do servidor embutido).
return require $publico . '/index.php';
