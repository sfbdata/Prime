<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
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
        private UserTenantRepository $vinculos,
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

    /**
     * Quem pode "Marcar como assinado em papel" (função provisória): qualquer colaborador do escritório com acesso ao
     * módulo (quem criou o documento incluído), sem permissão extra. O Master NÃO tem o bypass que o
     * `PermissionChecker` lhe dá em todo escritório: só marca onde tem vínculo ativo (dono ou colaborador).
     *
     * @return array{User, Tenant}|null nulo = sem acesso
     */
    public function quemPodeMarcarEmPapel(): ?array
    {
        $quem = $this->quemPode();
        if ($quem === null) {
            return null;
        }

        [$usuario, $tenant] = $quem;
        if (\in_array('ROLE_SUPER_ADMIN', $usuario->getRoles(), true) && !$this->temVinculoAtivo($usuario, $tenant)) {
            return null;
        }

        return $quem;
    }

    private function temVinculoAtivo(User $usuario, Tenant $tenant): bool
    {
        return $this->vinculos->findOneBy(['user' => $usuario, 'tenant' => $tenant])?->isActive() === true;
    }
}
