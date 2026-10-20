<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diz se um pedido sem sessão é navegação de documento (a pessoa abriu a página ou enviou um formulário) ou
 * pedido de dados (o `fetch` do sino, XHR, polling). Só a navegação vai ao login e pode virar destino; o pedido
 * de dados recebe 401 em JSON. Regra num lugar só: usam o `SessaoVencidaEmPedidoDeDadosListener` e o
 * `UserAuthenticator::start()`.
 *
 * Incidente da v2.5.0: um `fetch('/notificacoes/count')` com a sessão vencida virou destino do login e o dono
 * entrou e caiu no JSON do contador.
 */
final class PedidoDeNavegacao
{
    public const MENSAGEM_SESSAO_VENCIDA = 'Sua sessão terminou. Entre de novo.';

    /**
     * Na ordem:
     * - XHR (`X-Requested-With`) não é navegação;
     * - `Sec-Fetch-Mode` presente e diferente de `navigate` não é (cors, no-cors, same-origin);
     * - `Sec-Fetch-Dest` presente e diferente de `document` não é (iframe, empty, image...);
     * - `Sec-Fetch-Mode: navigate` com `Sec-Fetch-Dest: document` é (inclusive o POST de formulário);
     * - sem esses cabeçalhos (navegador antigo), só é navegação quem tem `text/html` no `Accept`.
     *   `application/json` puro, `* / *` puro (o `fetch` de navegador antigo) e `Accept` vazio não são.
     * - sem `Accept` nenhum: navegação. Navegador sempre manda `Accept` (o `fetch` manda `* / *`); quem não manda é
     *   o `KernelBrowser` dos testes, cuja navegação padrão continua indo ao login.
     */
    public static function eNavegacao(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return false;
        }

        $modo = $request->headers->get('Sec-Fetch-Mode');
        $destino = $request->headers->get('Sec-Fetch-Dest');

        if ($modo !== null && strtolower($modo) !== 'navigate') {
            return false;
        }

        if ($destino !== null && strtolower($destino) !== 'document') {
            return false;
        }

        if ($modo !== null && $destino !== null) {
            return true;
        }

        if (!$request->headers->has('Accept')) {
            return true;
        }

        return str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/html');
    }

    /** A resposta do pedido de dados sem sessão: o JS mostra o aviso e para de sondar. */
    public static function respostaDeSessaoVencida(): JsonResponse
    {
        return new JsonResponse(['erro' => self::MENSAGEM_SESSAO_VENCIDA], Response::HTTP_UNAUTHORIZED);
    }
}
