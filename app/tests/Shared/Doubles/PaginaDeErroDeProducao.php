<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * Na suíte, a página de erro HTML é a de PRODUÇÃO, mais as mensagens da exceção num comentário.
 *
 * Por quê: a página de depuração do Symfony 7.4.21 passou a mostrar trechos de código de todos os
 * frames da pilha, inclusive do próprio arquivo de teste. Um `assertStringNotContainsString(
 * 'NUP-RES-X', ...)` dos testes de isolamento achava o 'NUP-RES-X' na linha do próprio assert e
 * falhava sem haver vazamento nenhum.
 *
 * Por que o comentário: outra família de testes distingue QUAL erro aconteceu pela mensagem
 * ("disco cheio", "Arquivo da peça não encontrado.") e a página de produção não mostra mensagem.
 * O comentário leva só as mensagens da cadeia de exceções, sem pilha e sem código-fonte, como o
 * `<!-- mensagem (status) -->` que a página de depuração já punha no topo.
 *
 * Estende (em vez de embrulhar) porque o TwigErrorRenderer exige o tipo HtmlErrorRenderer.
 * Registrado só em `when@test` (config/services.yaml), decorando error_handler.error_renderer.html.
 */
final class PaginaDeErroDeProducao extends HtmlErrorRenderer
{
    public function __construct()
    {
        parent::__construct(false);
    }

    public function render(\Throwable $exception): FlattenException
    {
        $pagina = parent::render($exception);

        $mensagens = [];
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $mensagens[] = $e::class . ': ' . $e->getMessage();
        }
        $comentario = str_replace('--', '- -', implode(' | ', $mensagens));

        return $pagina->setAsString('<!-- ' . $comentario . " -->\n" . $pagina->getAsString());
    }
}
