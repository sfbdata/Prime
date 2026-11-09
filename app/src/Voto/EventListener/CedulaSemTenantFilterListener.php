<?php

declare(strict_types=1);

namespace App\Voto\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Desliga o `TenantFilter` na cédula pública da assembleia (`voto_assembleia_cedula*`, `/votar/{token}`).
 *
 * A página é do morador, que não é do escritório. Sem login o filtro já fica desligado; mas quem abre o link logado
 * (alguém da equipe conferindo o link, ou um morador que tem conta em outro escritório) teria o filtro ligado no
 * tenant DA SESSÃO pelo `TenantFilterListener` (prioridade 5) e veria a cédula pela metade. Aqui, depois dele e do
 * `TenantUrlScopeListener` (4), o filtro sai; quem escopa é o código, que passa o escritório da assembleia em toda
 * consulta. Mesmo modelo do `App\Pasta\EventListener\PushCompartilhadoSemTenantFilterListener`.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final class CedulaSemTenantFilterListener
{
    public const PREFIXO_DAS_ROTAS = 'voto_assembleia_cedula';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $rota = (string) $event->getRequest()->attributes->get('_route', '');
        if (!str_starts_with($rota, self::PREFIXO_DAS_ROTAS)) {
            return;
        }

        $filtros = $this->em->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
    }
}
