<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Repository\ResourceAccessRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;

/**
 * Quais pastas o leitor do relatório de pastas sem processo enxerga (spec relatorio-processos-controladoria,
 * D4): as que ele pode VER, pela mesma regra de `PermissionChecker::canAccessResource(pasta, view)`, em lote.
 *
 * Todas as do escritório: Master, primário (perfil de sistema), quem é Controladoria e quem tem
 * `resources.pasta.view` no perfil. Os demais: só as pastas com acesso item a item (`resource_access`).
 */
final class PastasVisiveisNoRelatorio
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly ResourceAccessRepository $resourceAccessRepository,
    ) {
    }

    /**
     * @return list<int>|null nulo = todas as pastas do escritório
     */
    public function ids(User $leitor, Tenant $tenant): ?array
    {
        if ($this->veTodas($leitor, $tenant)) {
            return null;
        }

        return $this->resourceAccessRepository->idsComAcesso($leitor, $tenant, AccessRequest::RESOURCE_PASTA, AccessRequest::ACTION_VIEW);
    }

    public function veTodas(User $leitor, Tenant $tenant): bool
    {
        if ($this->permissionChecker->isAdministradorDoEscritorio($leitor, $tenant)) {
            return true;
        }
        if ($this->userTenantRepository->findAtivoPorUserETenant($leitor, $tenant)?->isControladoria() === true) {
            return true;
        }

        return $this->permissionChecker->hasPermission($leitor, $tenant, 'resources.' . AccessRequest::RESOURCE_PASTA . '.' . AccessRequest::ACTION_VIEW);
    }
}
