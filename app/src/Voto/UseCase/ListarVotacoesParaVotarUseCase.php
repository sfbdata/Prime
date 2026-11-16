<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\DivulgacaoDaAssembleiaOutput;
use App\Voto\DTO\VotacaoParaVotar;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\EnderecoPublicoDaAssembleia;
use App\Voto\Service\LeituraDaAssembleia;

/**
 * Aba "Votar" de /voto (desenho 18): todas as votações publicadas do escritório, uma por pauta, na ordem das
 * assembleias (mais recentes primeiro) e das pautas. Ficam de fora o rascunho, a assembleia arquivada e a pauta
 * retirada da ordem do dia; a cancelada e a encerrada continuam, com o chip e a frase. Só do escritório informado.
 * Antes de montar, grava o encerramento das pautas com prazo vencido.
 */
final class ListarVotacoesParaVotarUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly EncerrarPautaPorPrazoVencidoUseCase $encerrarVencidas,
        private readonly LeituraDaAssembleia $leitura,
        private readonly EnderecoPublicoDaAssembleia $endereco,
    ) {
    }

    /** @return list<VotacaoParaVotar> */
    public function executar(Tenant $tenant, ?\DateTimeImmutable $agora = null): array
    {
        $votacoes = [];
        foreach ($this->assembleias->listarDoEscritorio($tenant) as $assembleia) {
            $this->encerrarVencidas->executar($assembleia, $agora);
            $lida = $this->leitura->montar($assembleia);
            if (!$lida->publicada() || $lida->arquivada()) {
                continue;
            }
            $codigo = $assembleia->getCodigoPublico();
            $divulgacao = new DivulgacaoDaAssembleiaOutput($lida->id, $lida->titulo, $codigo, $this->endereco->gerar($codigo));
            foreach ($lida->pautas as $pauta) {
                if (!$pauta->retirada()) {
                    $votacoes[] = new VotacaoParaVotar($lida, $pauta, $divulgacao);
                }
            }
        }

        return $votacoes;
    }
}
