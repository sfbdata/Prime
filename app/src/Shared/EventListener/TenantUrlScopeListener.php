<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Trava automática de escopo por escritório (B5): quando a rota casada tem o parâmetro
 * {tenantId}, fixa o TenantFilter NESSE tenant — independentemente do tenant da sessão.
 *
 * Roda DEPOIS do roteamento (atributos de rota já populados) e DEPOIS do TenantFilterListener
 * (prioridade 5), por isso prioridade 4: sobrescreve o pin da sessão com o tenant da URL nas
 * rotas de escritório. Em rotas de plataforma (sem {tenantId}) é inerte → visão global do
 * super-admin preservada.
 *
 * NÃO autoriza nada — só restringe (escopa) os dados. O guard de cada controller continua
 * rejeitando quem não pode operar naquele tenant; fixar o filtro num tenant nunca concede acesso.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class TenantUrlScopeListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenantId = $event->getRequest()->attributes->get('tenantId');
        if ($tenantId === null || !ctype_digit((string) $tenantId)) {
            return;
        }

        $this->em->getFilters()
            ->enable('tenant')
            ->setParameter('tenant', (int) $tenantId, Types::INTEGER);
    }
}
