<?php

declare(strict_types=1);

namespace App\Shared\Trait;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Valida o token CSRF de requisições AJAX/JSON enviado no header `X-CSRF-Token` (token único
 * 'ajax', injetado automaticamente pelo interceptador global em base.html.twig).
 *
 * Use em endpoints mutantes que respondem JSON e NÃO passam por um form Symfony (que já traz
 * CSRF embutido). A classe precisa estender AbstractController (que provê isCsrfTokenValid()).
 */
trait ValidaCsrfAjaxTrait
{
    private function validarCsrfAjax(Request $request): void
    {
        $token = (string) $request->headers->get('X-CSRF-Token', '');

        if (!$this->isCsrfTokenValid('ajax', $token)) {
            throw new AccessDeniedHttpException('Token CSRF inválido.');
        }
    }
}
