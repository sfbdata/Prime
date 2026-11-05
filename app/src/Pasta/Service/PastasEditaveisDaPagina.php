<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;

/**
 * As pastas de uma página da Lista (Expediente e Demandas) que a pessoa pode EDITAR, para a linha só entregar
 * o botão e o token dos marcadores a quem edita (auditoria 3.10/3.11). Mesmo `canAccessResource` de `edit`
 * que o `SincronizarMarcadoresDaPastaUseCase` confere, em lote: uma consulta para os cadeados, uma para o
 * vínculo e uma para os acessos item a item ({@see PermissionChecker::idsPermitidosComVinculo()}).
 */
final class PastasEditaveisDaPagina
{
    public function __construct(
        private readonly AcessoAPasta $acessoAPasta,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /**
     * @param Pasta[] $pastas
     *
     * @return array<int, true> ids das pastas editáveis
     */
    public function para(array $pastas, User $pessoa, Tenant $tenant): array
    {
        if ($pastas === []) {
            return [];
        }
        // Carrega os cadeados da página numa consulta: o checker os lê da memória.
        $this->acessoAPasta->niveisEmLote($pessoa, $pastas);
        $ids = array_values(array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas));
        $vinculo = $this->vinculos->findAtivoPorUserETenant($pessoa, $tenant);

        return array_fill_keys(
            $this->permissionChecker->idsPermitidosComVinculo($pessoa, $vinculo, AccessRequest::RESOURCE_PASTA, $ids, AccessRequest::ACTION_EDIT),
            true,
        );
    }
}
