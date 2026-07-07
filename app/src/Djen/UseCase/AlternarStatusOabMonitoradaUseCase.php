<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\OabMonitorada;
use App\Djen\Repository\OabMonitoradaRepository;

/**
 * Ativa/desativa o monitoramento de uma OAB sem apagá-la (pausa a captação preservando o cadastro
 * e o histórico). A busca tenant-safe e o 404 ficam no controller.
 */
final class AlternarStatusOabMonitoradaUseCase
{
    public function __construct(
        private readonly OabMonitoradaRepository $oabRepository,
    ) {
    }

    public function executar(OabMonitorada $oab): bool
    {
        $novoStatus = !$oab->isAtivo();
        $oab->setAtivo($novoStatus);
        $this->oabRepository->salvar($oab, true);

        return $novoStatus;
    }
}
