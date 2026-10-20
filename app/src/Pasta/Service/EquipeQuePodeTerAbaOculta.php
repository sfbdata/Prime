<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * Quem pode ter uma aba da pasta escondida (spec pasta-ocultar-aba §2.2 e §2.4): os vínculos ATIVOS
 * deste escritório, de contas ativas, menos os primários e o Master, que nunca são ocultados.
 *
 * É a lista do modal "Ocultar aba" e o filtro dos ids que o "Ocultar para…" aceita: o mesmo conjunto
 * nos dois lados, para "todos os elegíveis marcados" significar "toda a equipe".
 */
final class EquipeQuePodeTerAbaOculta
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $primarios,
    ) {
    }

    /** @return list<UserTenant> em ordem de nome, com usuário, cargo e lotação carregados */
    public function doEscritorio(Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->vinculos->findAtivosComCargoELotacaoPorTenant($tenant),
            fn (UserTenant $v): bool => !$this->primarios->ehPrimario($v) && !$this->primarios->ehMaster($v->getUser()),
        ));
    }

    /** @return list<int> */
    public function idsDoEscritorio(Tenant $tenant): array
    {
        return array_map(static fn (UserTenant $v): int => (int) $v->getUser()->getId(), $this->doEscritorio($tenant));
    }
}
