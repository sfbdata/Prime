<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\EstatisticaDaVotacao;
use App\Voto\DTO\EstatisticasDoVotoOutput;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\Entity\Assembleia;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Repository\AssembleiaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Monta as estatísticas do Master lendo TODAS as votações da plataforma. A conta não é reescrita: cada assembleia passa
 * pela LeituraDaAssembleia (participação por pauta e, na encerrada, o quórum pela ApuracaoDaPauta), a mesma da tela de
 * Resultados. Custo: umas poucas consultas por assembleia publicada (totais de aptos, participações, opções), sem N+1
 * por unidade. O TenantFilter fica suspenso durante TODA a leitura (a coleção lazy e os repositórios leem outros
 * escritórios) e volta ao estado de antes no fim. Quem chama já exigiu o Master.
 *
 * Fora: rascunho e simulação (a consulta), pauta cancelada e pauta retirada da ordem do dia (não são votação
 * realizada nem em andamento). Pauta aberta só leva a participação em números e nenhum quórum (nunca parcial).
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
        foreach ($this->leitura->montar($assembleia)->pautas as $pauta) {
            if (null !== $pauta->canceladaEm || null !== $pauta->retiradaEm) {
                continue;
            }
            $linhas[] = new EstatisticaDaVotacao(
                escritorio: $assembleia->getTenant()->getName(),
                entidade: $assembleia->getEntidadeNome(),
                assembleia: $assembleia->getTitulo(),
                pauta: $pauta->titulo,
                categoria: $assembleia->getCenario()->rotulo(),
                estado: $pauta->estado,
                encerramento: $pauta->encerradaEm,
                aptos: $pauta->unidadesAptas,
                votantes: $pauta->votantes,
                quorum: self::quorum($pauta),
            );
        }

        return $linhas;
    }

    private static function quorum(PautaNoPainel $pauta): ?string
    {
        if (EstadoDaPauta::Encerrada !== $pauta->estado || null === $pauta->resultado) {
            return null;
        }
        if (bccomp($pauta->resultado->quorumExigido, '0', 2) <= 0) {
            return EstatisticaDaVotacao::QUORUM_NAO_EXIGIDO;
        }

        return $pauta->resultado->quorumOk ? EstatisticaDaVotacao::QUORUM_ATINGIDO : EstatisticaDaVotacao::QUORUM_NAO_ATINGIDO;
    }
}
