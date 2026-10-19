<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\DTO\Solicitante;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Quem está pedindo, na sessão: o usuário, o escritório escolhido, se é Master e se é primário (perfil de
 * sistema do escritório). Comum aos controllers do Contribua.
 */
final class SolicitanteDaSessao
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    public function atual(): Solicitante
    {
        $escritorio = $this->tenantContext->getCurrentTenant();
        if ($escritorio === null) {
            throw new AccessDeniedException('Escolha um escritório para abrir o Contribua.');
        }
        $papel = $this->tenantContext->getCurrentUserTenant()?->getTenantRole();

        return new Solicitante($this->usuario(), $escritorio, $this->security->isGranted('ROLE_SUPER_ADMIN'), $papel?->isSystem() === true);
    }

    public function usuario(): User
    {
        $usuario = $this->security->getUser();
        if (!$usuario instanceof User) {
            throw new AccessDeniedException();
        }

        return $usuario;
    }
}
