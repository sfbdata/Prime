<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Repository\VotoNominalRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\Service\TransacaoDoVoto;

/**
 * Reinicia a simulação (desenho 18, "Reiniciar simulação"): apaga votos, participações, registro nominal e contadores e
 * volta a assembleia a rascunho, com as pautas aguardando e acesso novo (código público e links das unidades). Só simulação: a votação real é recusada ANTES de qualquer
 * escrita e nunca perde voto. Tudo numa transação, com as pautas relidas TRAVADAS (um voto em andamento termina antes);
 * assembleia de outro escritório é "não encontrada" (404).
 */
final class ReiniciarSimulacaoDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly VotoNominalRepository $nominais,
        private readonly OpcaoDaPautaRepository $opcoes,
        private readonly TransacaoDoVoto $transacao,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly TokenDaUnidade $tokens,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId): void {
            $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
                ?? throw new AssembleiaNaoEncontradaException();
            $assembleia->exigirSimulacao('Só votação de simulação pode ser reiniciada.');
            $this->transacao->executar(function () use ($assembleia): void {
                foreach ($assembleia->getPautas() as $pauta) {
                    $this->pautas->buscarTravadaDaAssembleia((int) $pauta->getId(), $assembleia);
                }
                $this->participacoes->apagarDaAssembleia($assembleia);
                $this->nominais->apagarDaAssembleia($assembleia);
                $this->opcoes->zerarDaAssembleia($assembleia);
                $this->pautas->zerarNulosDaAssembleia($assembleia);
                $assembleia->reiniciarSimulacao(function (): array {
                    $semente = $this->tokens->novaSemente();

                    return [$semente, $this->tokens->hash($this->tokens->token($semente))];
                });
            });
            foreach ($assembleia->getPautas() as $pauta) {
                $this->pautas->relerContadores($pauta);
            }
        });
    }
}
