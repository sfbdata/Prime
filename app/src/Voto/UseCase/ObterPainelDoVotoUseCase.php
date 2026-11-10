<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\LeituraDaAssembleia;

/**
 * As assembleias do escritório, mais recentes primeiro, como o painel da Gestão eleitoral, Acompanhar e Resultados
 * mostram. Antes de montar, grava o encerramento das pautas com prazo vencido.
 */
final class ObterPainelDoVotoUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly EncerrarPautaPorPrazoVencidoUseCase $encerrarVencidas,
        private readonly LeituraDaAssembleia $leitura,
    ) {
    }

    /** @return list<AssembleiaNoPainel> */
    public function executar(Tenant $tenant, ?\DateTimeImmutable $agora = null): array
    {
        $lidas = [];
        foreach ($this->assembleias->listarDoEscritorio($tenant) as $assembleia) {
            $this->encerrarVencidas->executar($assembleia, $agora);
            $lidas[] = $this->leitura->montar($assembleia);
        }

        return $lidas;
    }
}
