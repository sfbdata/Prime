<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Repository\AjusteDeDataRepository;
use App\Entity\Tenant\Tenant;

/**
 * História: "Restaurar datas do sistema" (botão direito) devolve à Agenda do escritório todas as datas
 * ocultadas e os nomes originais das renomeadas. As datas acrescentadas pelo escritório continuam.
 */
final class RestaurarDatasDoSistemaUseCase
{
    public function __construct(
        private readonly AjusteDeDataRepository $ajustes,
    ) {
    }

    public function executar(Tenant $tenant): int
    {
        return $this->ajustes->apagarRenomesEOcultacoes($tenant);
    }
}
