<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\PortalDaAssembleiaOutput;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\LiberacaoDoVotoRepository;

/** O participante abre o código público; só vê assembleia publicada e escritório ativo e liberado. */
final class MontarPortalDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly LiberacaoDoVotoRepository $liberacoes,
    ) {
    }

    public function executar(string $codigo): ?PortalDaAssembleiaOutput
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $codigo)) {
            return null;
        }
        $assembleia = $this->assembleias->buscarPublicadaPeloCodigo($codigo);
        if ($assembleia === null || $assembleia->getTenant()->isActive() !== true || $assembleia->getTenant()->getExcluidoEm() !== null) {
            return null;
        }
        if ($this->liberacoes->findDoTenantParaOMaster($assembleia->getTenant())?->isLiberado() !== true) {
            return null;
        }

        return new PortalDaAssembleiaOutput($assembleia->getTitulo(), $assembleia->getCodigoPublico());
    }
}
