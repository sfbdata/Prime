<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\DTO\Solicitante;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quem está agindo no Contribua, montado da sessão como o ContribuaController faz (pessoa logada, escritório
 * da sessão, Master e primário). Usado pelo boleto (controller e parcial), que não mexe no controller da f6.
 */
final class SolicitanteAtual
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    /** null sem pessoa logada ou sem escritório na sessão. */
    public function agora(): ?Solicitante
    {
        $usuario = $this->security->getUser();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $escritorio) {
            return null;
        }
        $papel = $this->tenantContext->getCurrentUserTenant()?->getTenantRole();

        return new Solicitante($usuario, $escritorio, $this->security->isGranted('ROLE_SUPER_ADMIN'), true === $papel?->isSystem());
    }
}
