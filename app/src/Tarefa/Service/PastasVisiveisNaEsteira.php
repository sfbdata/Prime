<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Repository\ResourceAccessRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;

/**
 * O "ver a pasta" da esteira de Minhas Metas (lista de `?etapa=` e contagens das caixas), em lote: a mesma regra de
 * `PermissionChecker::canAccessResource(pasta, view)` que a lista normal e o `AcessoAMeta` usam, sem consultar por
 * meta. O cadeado fica de fora daqui: quem chama já tira as pastas ocultas (`AcessoAPasta::idsOcultosPara`), para
 * todos, inclusive para quem cuida da meta.
 *
 * Todas as pastas: o Master, o primário (perfil de sistema) e quem tem `resources.pasta.view` no perfil. Os demais:
 * só as pastas com acesso item a item (`resource_access` com ver). Sem vínculo ativo: nenhuma.
 */
final class PastasVisiveisNaEsteira
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
        if ($this->permissionChecker->isAdministradorDoEscritorio($leitor, $tenant)) {
            return null;
        }
        if ($this->userTenantRepository->findAtivoPorUserETenant($leitor, $tenant) === null) {
            return [];
        }
        if ($this->permissionChecker->hasPermission($leitor, $tenant, 'resources.' . AccessRequest::RESOURCE_PASTA . '.' . AccessRequest::ACTION_VIEW)) {
            return null;
        }

        return $this->resourceAccessRepository->idsComAcesso($leitor, $tenant, AccessRequest::RESOURCE_PASTA, AccessRequest::ACTION_VIEW);
    }
}
