<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\OabMonitorada;
use App\Djen\Repository\OabMonitoradaRepository;

/**
 * Remove uma OAB monitorada. A busca tenant-safe e o 404 ficam no controller (que passa a entidade
 * já validada como pertencente ao escritório atual). As publicações já captadas são preservadas —
 * a FK oab_monitorada_id é anulada (onDelete SET NULL), mantendo o histórico do escritório.
 */
final class RemoverOabMonitoradaUseCase
{
    public function __construct(
        private readonly OabMonitoradaRepository $oabRepository,
    ) {
    }

    public function executar(OabMonitorada $oab): void
    {
        $this->oabRepository->remover($oab, true);
    }
}
