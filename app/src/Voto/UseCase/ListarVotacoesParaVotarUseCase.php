<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\DivulgacaoDaAssembleiaOutput;
use App\Voto\DTO\VotacaoParaVotar;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\EnderecoPublicoDaAssembleia;
use App\Voto\Service\LeituraDaAssembleia;

/**
 * Aba "Votar" de /voto (desenho 18): todas as votações publicadas do escritório, uma por pauta, na ordem das
 * assembleias (mais recentes primeiro) e das pautas. Ficam de fora o rascunho, a assembleia arquivada e a pauta
 * retirada da ordem do dia; a cancelada e a encerrada continuam, com o chip e a frase. Só do escritório informado.
 * Antes de montar, grava o encerramento das pautas com prazo vencido. Com `$pessoa`, esconde a assembleia que a equipe
 * própria deixa `Oculto` para ela (teto da equipe).
 */
final class ListarVotacoesParaVotarUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly EncerrarPautaPorPrazoVencidoUseCase $encerrarVencidas,
        private readonly LeituraDaAssembleia $leitura,
        private readonly EnderecoPublicoDaAssembleia $endereco,
        private readonly AcessoAAssembleia $acesso,
    ) {
    }

    /** @return list<VotacaoParaVotar> */
    public function executar(Tenant $tenant, ?\DateTimeImmutable $agora = null, ?User $pessoa = null): array
    {
        $votacoes = [];
        $doEscritorio = $this->assembleias->listarDoEscritorio($tenant);
        foreach (null === $pessoa ? $doEscritorio : $this->acesso->visiveis($pessoa, $doEscritorio) as $assembleia) {
            if (EstadoDaAssembleia::Publicada !== $assembleia->getEstado() || null !== $assembleia->getArquivadaEm()) {
                continue;
            }
            $this->encerrarVencidas->executar($assembleia, $agora);
            $lida = $this->leitura->montar($assembleia);
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
