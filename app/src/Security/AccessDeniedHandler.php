<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

class AccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        $html = $this->twig->render('access_request/access_denied.html.twig');

        return new Response($html, Response::HTTP_FORBIDDEN);
    }
}
