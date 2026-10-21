<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserTenantRepository;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;

/**
 * As recusas comuns do fluxo de exclusão pelo primário (PR 5, decisões E1, E2 e E9): a pessoa é primária
 * do escritório (qualquer primário, não só o titular), a conta não é a do Master e está no ar (nem
 * suspensa pelo Master nem em quarentena).
 */
final class GuardaDaExclusaoPeloPrimario
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly RegraDosMasters $regraDosMasters,
    ) {
    }

    /** O card aparece para esta pessoa neste escritório? */
    public function podeVer(User $usuario, Tenant $tenant): bool
    {
        $vinculo = $this->vinculos->findAtivoPorUserETenant($usuario, $tenant);

        return $vinculo !== null
            && $this->regraDosPrimarios->ehPrimario($vinculo)
            && $this->regraDosMasters->recusaParaExcluir($tenant) === null;
    }

    /** @throws PedidoDeExclusaoRecusado */
    public function exigir(User $usuario, Tenant $tenant): UserTenant
    {
        $vinculo = $this->vinculos->findAtivoPorUserETenant($usuario, $tenant);
        if ($vinculo === null || !$this->regraDosPrimarios->ehPrimario($vinculo)) {
            throw PedidoDeExclusaoRecusado::semPermissao();
        }
        $recusa = $this->regraDosMasters->recusaParaExcluir($tenant);
        if ($recusa !== null) {
            throw new PedidoDeExclusaoRecusado($recusa, 403);
        }
        if ($tenant->isActive() !== true || $tenant->getExcluidoEm() !== null) {
            throw new PedidoDeExclusaoRecusado('A conta está fora do ar. Fale com o suporte do BlueJus.', 409);
        }

        return $vinculo;
    }
}
