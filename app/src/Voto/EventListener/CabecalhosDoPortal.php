<?php

declare(strict_types=1);

namespace App\Voto\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Inclui erros e bloqueios das portas públicas; nunca guarda página com identidade/código no cache. */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -255)]
final class CabecalhosDoPortal
{
    public function __invoke(ResponseEvent $evento): void
    {
        if (!in_array($evento->getRequest()->attributes->get('_route'), CedulaSemTenantFilterListener::ROTAS, true)) {
            return;
        }
        $headers = $evento->getResponse()->headers;
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Referrer-Policy', 'no-referrer');
    }
}
