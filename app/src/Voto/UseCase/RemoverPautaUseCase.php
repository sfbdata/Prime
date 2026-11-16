<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\FotosDasCandidaturas;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Tira uma pauta da assembleia em rascunho (as opções saem junto, pelo ON DELETE CASCADE do banco). As fotos das
 * candidaturas só saem do armazenamento depois do COMMIT.
 */
final class RemoverPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly FotosDasCandidaturas $fotos,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $pautaId): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $pautaId);
        });
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, int $pautaId): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirPautasAlteraveis();
        $pauta = $this->pautas->buscarDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        $fotosDaPauta = FotosDasCandidaturas::chavesDaPauta($pauta);
        $assembleia->getPautas()->removeElement($pauta);
        $this->pautas->remover($pauta, flush: true);
        $this->fotos->descartar($fotosDaPauta, 'pauta removida');
    }
}
