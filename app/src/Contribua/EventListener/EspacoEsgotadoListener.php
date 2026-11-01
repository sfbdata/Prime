<?php

declare(strict_types=1);

namespace App\Contribua\EventListener;

use App\Contribua\Exception\EspacoEsgotado;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Transforma a recusa por espaço em resposta, em qualquer tela que envie arquivo.
 *
 * Dois ramos, no molde do `PastaSomenteLeituraListener`:
 *  - envio por fetch/XHR → JSON 422. Não basta `isXmlHttpRequest()`: o upload com progresso da
 *    pasta, o Peticionar e o Kanban não mandam `X-Requested-With`, mas todo POST same-origin feito
 *    por JS leva o `X-CSRF-Token` que o `base.html.twig` injeta (formulário comum não leva);
 *  - formulário comum → aviso (flash `warning`) e volta para a página de onde veio.
 *
 * Cada tela lê o erro com um nome diferente (`error`, `erro`, `mensagem`, com `success`/`ok`/
 * `sucesso` falsos). O corpo traz todos: assim a mensagem aparece sem mexer no JS de cada tela.
 *
 * Não usa o EntityManager: a recusa pode chegar depois de uma `TransacaoComArquivoNovo` que já o
 * fechou (troca de anexo do ponto).
 */
final class EspacoEsgotadoListener
{
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function aoFalhar(ExceptionEvent $evento): void
    {
        $recusa = self::recusaNaCadeia($evento->getThrowable());
        if ($recusa === null) {
            return;
        }

        $request = $evento->getRequest();
        $evento->setResponse(self::esperaJson($request) ? self::json($recusa) : self::voltar($request));
    }

    private static function recusaNaCadeia(\Throwable $erro): ?EspacoEsgotado
    {
        for ($atual = $erro; $atual !== null; $atual = $atual->getPrevious()) {
            if ($atual instanceof EspacoEsgotado) {
                return $atual;
            }
        }

        return null;
    }

    private static function esperaJson(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || $request->headers->has('X-CSRF-Token')
            || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    private static function json(EspacoEsgotado $recusa): JsonResponse
    {
        $mensagem = $recusa->getMessage();

        return new JsonResponse([
            'success'  => false,
            'ok'       => false,
            'sucesso'  => false,
            'status'   => 'erro',
            'codigo'   => 'espaco_esgotado',
            'error'    => $mensagem,
            'erro'     => $mensagem,
            'mensagem' => $mensagem,
            'message'  => $mensagem,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function voltar(Request $request): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', EspacoEsgotado::MENSAGEM);
        }

        return new RedirectResponse(self::origemSegura($request));
    }

    /** Volta só para a mesma origem: um Referer de fora não vira redirecionamento aberto. */
    private static function origemSegura(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        $origem = $request->getSchemeAndHttpHost();

        // Sem Referer, o início do sistema (rota homepage): a raiz (/) é o site institucional estático.
        return str_starts_with($referer, $origem . '/') ? $referer : $origem . '/inicio';
    }
}
