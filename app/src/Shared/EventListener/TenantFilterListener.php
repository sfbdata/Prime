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
 *
 * Rotas em ROTAS_SEM_FILTRO não recebem o filtro do escritório da SESSÃO: a tela de autorização do conector de I.A.
 * (`/oauth/authorize`, spec mcp-remoto-oauth 4 e 9.4) escolhe um escritório que pode não ser o da sessão, e lista os
 * escritórios da pessoa; com o filtro da sessão ligado, o escritório escolhido sumiria das consultas. Ali toda
 * consulta passa o escritório explícito.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final class TenantFilterListener
{
    private const ROTAS_SEM_FILTRO = [
        'conector_oauth_autorizar',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (\in_array($event->getRequest()->attributes->get('_route'), self::ROTAS_SEM_FILTRO, true)) {
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
