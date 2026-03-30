<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * Intercepta AccessDeniedException e exibe a tela padrão de acesso restrito
 * em vez da página de erro genérica do Symfony.
 */
class AccessDeniedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 2],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if (!$throwable instanceof AccessDeniedException) {
            return;
        }

        $html = $this->twig->render('access_request/access_denied.html.twig');

        $event->setResponse(new Response($html, Response::HTTP_FORBIDDEN));
    }
}
