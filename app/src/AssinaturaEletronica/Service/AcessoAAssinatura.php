<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * A porta de toda rota da tela Assinatura: usuário logado, escritório atual e o módulo `assinatura_eletronica`
 * (`modules.assinatura_eletronica.view`). A chave do Master é conferida antes, pelo `BloqueioDeRecursoDesativado`.
 */
final readonly class AcessoAAssinatura
{
    public const MODULO = 'assinatura_eletronica';

    public function __construct(
        private Security $seguranca,
        private TenantContext $tenantContext,
        private PermissionChecker $permissoes,
    ) {
    }

    /** @return array{User, Tenant}|null nulo = sem acesso */
    public function quemPode(): ?array
    {
        $usuario = $this->seguranca->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null || !$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return null;
        }

        return [$usuario, $tenant];
    }
}
