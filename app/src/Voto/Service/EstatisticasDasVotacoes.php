<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\EstatisticaDaVotacao;
use App\Voto\DTO\EstatisticasDoVotoOutput;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\Entity\Assembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Repository\AssembleiaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Monta as estatísticas do Master lendo TODAS as votações da plataforma. A conta não é reescrita: cada assembleia passa
 * pela LeituraDaAssembleia (participação por pauta e, na encerrada, o quórum pela ApuracaoDaPauta), a mesma da tela de
 * Resultados. Custo: umas poucas consultas por assembleia publicada (totais de aptos, participações, opções), sem N+1
 * por unidade. O TenantFilter fica suspenso durante TODA a leitura (a coleção lazy e os repositórios leem outros
 * escritórios) e volta ao estado de antes no fim. Quem chama já exigiu o Master.
 *
 * Fora: rascunho e simulação (a consulta; desenho H466). Pauta cancelada e retirada entram com o chip do estado delas e
 * não contam como realizadas. Lê só números (`montarSoNumeros`: sem relação nominal, sem refresh, sem apuração): o
 * quórum vem da mesma conta da ApuracaoDaPauta sobre a participação, em qualquer estado, e nada além da participação
 * (que já aparece) é exposto. Teto aceito: TODAS as assembleias publicadas, sem paginação, porque os KPIs são do total.
 */
final class EstatisticasDasVotacoes
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly LeituraDaAssembleia $leitura,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function calcular(?CenarioDaAssembleia $categoria): EstatisticasDoVotoOutput
    {
        $filtros = $this->em->getFilters();
        $estavaLigado = $filtros->isEnabled('tenant');
        if ($estavaLigado) {
            $filtros->suspend('tenant');
        }
        try {
            $linhas = [];
            foreach ($this->assembleias->listarParaEstatisticasDoMaster($categoria) as $assembleia) {
                array_push($linhas, ...$this->daAssembleia($assembleia));
            }
        } finally {
            if ($estavaLigado) {
                $filtros->restore('tenant');
            }
        }

        return new EstatisticasDoVotoOutput($linhas);
    }

    /** @return list<EstatisticaDaVotacao> */
    private function daAssembleia(Assembleia $assembleia): array
    {
        $linhas = [];
        foreach ($this->leitura->montarSoNumeros($assembleia)->pautas as $pauta) {
            $linhas[] = new EstatisticaDaVotacao(
                escritorio: $assembleia->getTenant()->getName(),
                entidade: $assembleia->getEntidadeNome(),
                assembleia: $assembleia->getTitulo(),
                pauta: $pauta->titulo,
                categoria: $assembleia->getCenario()->rotulo(),
                situacao: self::situacao($pauta),
                encerramento: $pauta->encerradaEm ?? $pauta->prazoAte ?? $pauta->participacao->encerraEm,
                aptos: $pauta->unidadesAptas,
                votantes: $pauta->votantes,
                quorum: self::quorum($pauta),
            );
        }

        return $linhas;
    }

    private static function situacao(PautaNoPainel $pauta): string
    {
        return match (true) {
            null !== $pauta->canceladaEm => 'cancelada',
            null !== $pauta->retiradaEm => 'retirada',
            default => $pauta->estado->value,
        };
    }

    /** O quórum pela mesma conta da apuração, em qualquer estado; só participação e peso apto, nenhum voto. */
    private static function quorum(PautaNoPainel $pauta): ?string
    {
        if (null !== $pauta->canceladaEm || null !== $pauta->retiradaEm) {
            return null;
        }
        // Antes de abrir a pauta ainda não tem convocação: vale o quórum da 1ª convocação, que é o que ela vai exigir.
        $exigido = $pauta->quorumExigido ?? (EstadoDaPauta::Aguardando === $pauta->estado ? $pauta->quorumPrimeira : null);
        if (bccomp($exigido ?? '0', '0', 2) <= 0) {
            return EstatisticaDaVotacao::QUORUM_NAO_EXIGIDO;
        }

        return ApuracaoDaPauta::quorumDaPautaAtingido($pauta->pesoVotante, $pauta->pesoApto, $exigido)
            ? EstatisticaDaVotacao::QUORUM_ATINGIDO
            : EstatisticaDaVotacao::QUORUM_NAO_ATINGIDO;
    }
}
