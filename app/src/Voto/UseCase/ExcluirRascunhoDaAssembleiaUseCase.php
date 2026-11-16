<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Exclui o rascunho nunca publicado (as pautas, opções e unidades saem junto, pelo ON DELETE CASCADE do banco). Votação
 * publicada nunca é excluída: pode ser arquivada.
 */
final class ExcluirRascunhoDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId): void {
            $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
                ?? throw new AssembleiaNaoEncontradaException();
            $assembleia->exigirExclusaoPossivel();
            $this->assembleias->remover($assembleia, flush: true);
        });
    }
}
