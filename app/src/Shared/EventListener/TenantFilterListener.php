<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Liga o TenantFilter no início de cada request com o tenant ativo. Roda DEPOIS do
 * TenantContextValidatorListener (prioridade 6), por isso prioridade 5. Sem tenant ativo
 * (super admin de plataforma, rotas sem tenant), o filtro permanece desligado.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final class TenantFilterListener
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return;
        }

        $this->em->getFilters()
            ->enable('tenant')
            ->setParameter('tenant', $tenant->getId(), Types::INTEGER);
    }
}
