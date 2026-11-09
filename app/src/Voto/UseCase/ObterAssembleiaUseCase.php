<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\LeituraDaAssembleia;

/** Uma assembleia do escritório (guarda de posse), depois de encerrar as pautas com prazo vencido. */
final class ObterAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly EncerrarPautaPorPrazoVencidoUseCase $encerrarVencidas,
        private readonly LeituraDaAssembleia $leitura,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, ?\DateTimeImmutable $agora = null): AssembleiaNoPainel
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $this->encerrarVencidas->executar($assembleia, $agora);

        return $this->leitura->montar($assembleia);
    }
}
