<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\FotosDasCandidaturas;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Apaga a simulação (desenho 18, "Apagar simulação"): a assembleia, as pautas, opções, unidades e todos os votos de treino
 * saem juntos, pelo ON DELETE CASCADE do banco. Só simulação: a votação real é recusada e nada é apagado (o rascunho
 * nunca publicado tem o próprio caso de uso). As fotos só saem do armazenamento depois do COMMIT.
 */
final class ExcluirSimulacaoDaAssembleiaUseCase
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
            $assembleia->exigirSimulacao('Só votação de simulação pode ser apagada.');
            $fotos = [];
            foreach ($assembleia->getPautas() as $pauta) {
                $fotos = [...$fotos, ...FotosDasCandidaturas::chavesDaPauta($pauta)];
            }
            $this->assembleias->remover($assembleia, flush: true);
            $this->fotos->descartar($fotos, 'simulação apagada');
        });
    }
}
