<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Enum\AvaliacaoDaOcorrencia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Exception\SemPapelNaAvaliacaoException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\TransacaoDoVoto;

/**
 * A decisão do conselho sobre uma avaliação (spec voto-avaliacoes-do-conselho-1611, seção 2): "Manter inaptidão" ou
 * "Tornar apto(a)", com justificativa obrigatória (até 160). A decisão tomada não se refaz. "Tornar apto(a)" só vale
 * enquanto nenhuma pauta da assembleia estiver encerrada (ou com o prazo vencido); "Manter" vale sempre. Tornar apto muda
 * a unidade pelo método rico dela e NÃO recalcula o hash da lista (gravado na trava).
 *
 * É a ÚNICA escrita do nível Visualizar: a rota está em `ROTAS_DO_VISUALIZAR` da barreira e o papel é conferido aqui
 * ({@see AcessoAAssembleia::podeDecidirAvaliacao()}), nunca só pela rota. A linha da ocorrência é lida TRAVADA, para duas
 * decisões simultâneas não passarem juntas. Ocorrência de outra assembleia ou escritório dá 404. Grava a trilha na mesma
 * transação.
 */
final class DecidirAvaliacaoUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly OcorrenciaDaUnidadeRepository $ocorrencias,
        private readonly AcessoAAssembleia $acesso,
        private readonly RegistrarAuditoriaDasAvaliacoesUseCase $auditoria,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    /**
     * @throws AssembleiaNaoEncontradaException      assembleia ou ocorrência fora do escritório
     * @throws SemPapelNaAvaliacaoException          a pessoa não decide avaliação nesta assembleia
     * @throws OperacaoInvalidaNaAssembleiaException decisão inválida, sem justificativa, já decidida ou votação encerrada
     */
    public function executar(
        Tenant $tenant,
        int $assembleiaId,
        int $ocorrenciaId,
        AvaliacaoDaOcorrencia $decisao,
        string $justificativa,
        User $ator,
        string $porNome,
        ?\DateTimeImmutable $agora = null,
    ): OcorrenciaDaUnidade {
        $agora ??= new \DateTimeImmutable();
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        if (!$this->acesso->podeDecidirAvaliacao($ator, $assembleia)) {
            throw new SemPapelNaAvaliacaoException();
        }

        return $this->transacao->executar(function () use ($tenant, $assembleia, $ocorrenciaId, $decisao, $justificativa, $ator, $porNome, $agora): OcorrenciaDaUnidade {
            $ocorrencia = $this->ocorrencias->buscarTravadaDaAssembleia($ocorrenciaId, $assembleia)
                ?? throw new AssembleiaNaoEncontradaException();
            if (!$ocorrencia->pendente()) {
                throw new OperacaoInvalidaNaAssembleiaException('Não há avaliação pendente.');
            }
            if (AvaliacaoDaOcorrencia::Pendente === $decisao) {
                throw new OperacaoInvalidaNaAssembleiaException('Decisão inválida.');
            }
            if ('' === trim($justificativa)) {
                throw new OperacaoInvalidaNaAssembleiaException('Informe a justificativa.');
            }
            if (AvaliacaoDaOcorrencia::TornadaApta === $decisao) {
                if ($assembleia->algumaPautaEncerrada($agora)) {
                    throw new OperacaoInvalidaNaAssembleiaException('Votação encerrada: a aptidão não pode mais ser alterada.');
                }
                $ocorrencia->getUnidade()->tornarAptaPeloConselho();
            }
            $ocorrencia->decidir($decisao, $justificativa, $porNome, $agora);
            $this->transacao->gravar();
            $this->auditoria->avaliacaoDecidida($tenant, $ator, $assembleia, $ocorrencia);

            return $ocorrencia;
        });
    }
}
