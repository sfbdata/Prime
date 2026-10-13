<?php

namespace App\Twig;

use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * PAS-050: `can_access_resource` guarda a resposta por requisição. `ResetInterface` (tag
 * `kernel.reset` por autoconfiguração) esvazia a memória entre requisições — worker de longa
 * duração e a suíte reaproveitam o mesmo serviço, e a decisão de um usuário/escritório não pode
 * vazar para a requisição seguinte.
 */
class PermissionExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, bool> chave: usuário|escritório|tipo|id|ação */
    private array $acessoARecurso = [];

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_access_module', $this->canAccessModule(...)),
            new TwigFunction('can_administer', $this->canAdminister(...)),
            new TwigFunction('has_permission', $this->hasPermission(...)),
            new TwigFunction('can_access_resource', $this->canAccessResource(...)),
        ];
    }

    public function canAccessModule(string $module): bool
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return false;
        }

        return $this->permissionChecker->canAccessModule($user, $tenant, $module);
    }

    public function canAdminister(string $permission): bool
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return false;
        }

        return $this->permissionChecker->canAdminister($user, $tenant, $permission);
    }

    public function hasPermission(string $permission): bool
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return false;
        }

        return $this->permissionChecker->hasPermission($user, $tenant, $permission);
    }

    /**
     * `can_access_resource('pasta', pasta.id, 'delete')`: a MESMA decisão que o controller toma
     * com `denyResourceAccessUnlessGranted` (PermissionChecker::canAccessResource), para a tela
     * não oferecer o controle que o servidor recusaria. É só honestidade na interface — o
     * servidor continua conferindo. A decisão consulta o banco (vínculo do usuário e acesso
     * específico ao recurso), por isso fica guardada por requisição, pela chave
     * (usuário, escritório, tipo, id, ação): os parciais da pasta podem perguntar a mesma coisa
     * sem repetir as consultas.
     */
    public function canAccessResource(string $resourceType, int $resourceId, string $action): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $chave  = implode('|', [
            $user->getId() ?? spl_object_id($user),
            $tenant?->getId() ?? '-',
            $resourceType,
            $resourceId,
            $action,
        ]);

        return $this->acessoARecurso[$chave]
            ??= $this->permissionChecker->canAccessResource($user, $tenant, $resourceType, $resourceId, $action);
    }

    public function reset(): void
    {
        $this->acessoARecurso = [];
    }
}
