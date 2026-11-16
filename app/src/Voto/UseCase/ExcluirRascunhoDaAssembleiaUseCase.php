<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\FotosDasCandidaturas;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Exclui o rascunho nunca publicado (as pautas, opções e unidades saem junto, pelo ON DELETE CASCADE do banco). Votação
 * publicada nunca é excluída: pode ser arquivada. As fotos das candidaturas só saem do armazenamento depois do COMMIT.
 */
final class ExcluirRascunhoDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly FotosDasCandidaturas $fotos,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId): void {
            $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
                ?? throw new AssembleiaNaoEncontradaException();
            $assembleia->exigirExclusaoPossivel();
            $fotos = [];
            foreach ($assembleia->getPautas() as $pauta) {
                $fotos = [...$fotos, ...FotosDasCandidaturas::chavesDaPauta($pauta)];
            }
            $this->assembleias->remover($assembleia, flush: true);
            $this->fotos->descartar($fotos, 'rascunho excluído');
        });
    }
}
