<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;

/** Tira uma pauta da assembleia em rascunho (as opções saem junto, pelo ON DELETE CASCADE do banco). */
final class RemoverPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirPautasAlteraveis();
        $pauta = $this->pautas->buscarDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->getPautas()->removeElement($pauta);
        $this->pautas->remover($pauta, flush: true);
    }
}
