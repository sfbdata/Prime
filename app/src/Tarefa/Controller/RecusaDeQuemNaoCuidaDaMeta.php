<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Tarefa\Exception\PassagemRecusadaException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * D3 no concluir e no reabrir da aba Metas da pasta: a mesma recusa (403) da passagem de etapa, com o texto em
 * `erro` (como as rotas da passagem) e em `mensagem` (o que o pasta-metas.js lê). Sem XHR, o 403 de sempre.
 */
final class RecusaDeQuemNaoCuidaDaMeta
{
    public static function responder(Request $request): JsonResponse
    {
        $recusa = PassagemRecusadaException::semPermissaoParaPassar()->getMessage();
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedHttpException($recusa);
        }

        return new JsonResponse(['ok' => false, 'erro' => $recusa, 'sucesso' => false, 'mensagem' => $recusa], Response::HTTP_FORBIDDEN);
    }
}
