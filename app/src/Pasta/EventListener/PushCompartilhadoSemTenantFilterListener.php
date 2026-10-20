<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Desliga o `TenantFilter` nas rotas públicas do link externo do Push (`push_compartilhado*`).
 *
 * A página é de quem NÃO é do escritório. Sem login o filtro já fica desligado; mas quem abre o link
 * logado (o próprio advogado em "Ver como terceiro", ou alguém de outro escritório) teria o filtro
 * ligado no tenant DA SESSÃO pelo `TenantFilterListener` (prioridade 5) e veria "Link não
 * encontrado" ou uma página pela metade. Aqui, depois dele e do `TenantUrlScopeListener` (4), o
 * filtro sai; quem escopa é o código, que passa o tenant do link em toda consulta.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final class PushCompartilhadoSemTenantFilterListener
{
    public const PREFIXO_DAS_ROTAS = 'push_compartilhado';

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
