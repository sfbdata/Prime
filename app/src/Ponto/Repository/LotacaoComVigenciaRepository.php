<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\LotacaoComVigencia;
use App\Ponto\Service\HistoricoDeLotacoes;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As versões da lotação de uma pessoa NUM escritório (spec `docs/specs/ponto-desenho-0811.md`, E6: lotação com
 * vigência). Toda consulta leva o escritório escrito à mão, com o filtro de escritório suspenso: a lista de
 * pendências lê a folha da pessoa em outro escritório dela.
 *
 * @extends ServiceEntityRepository<LotacaoComVigencia>
 */
class LotacaoComVigenciaRepository extends ServiceEntityRepository
{
    use ConsultaSemFiltroDeEscritorio;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LotacaoComVigencia::class);
    }

    /** @return list<LotacaoComVigencia> as versões do vínculo, em qualquer ordem */
    public function versoesDoVinculo(User $pessoa, Tenant $escritorio): array
    {
        return $this->semFiltroDeEscritorio(fn (): array => $this->findBy(['user' => $pessoa, 'tenant' => $escritorio]));
    }

    /**
     * O histórico da pessoa no escritório numa consulta só: a lotação de hoje do vínculo e as versões. Sem vínculo
     * naquele escritório, nenhuma lotação.
     */
    public function historicoDoVinculo(User $pessoa, Tenant $escritorio): HistoricoDeLotacoes
    {
        if ($pessoa->getId() === null || $escritorio->getId() === null) {
            return HistoricoDeLotacoes::semVinculo();
        }
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT ut.lotacao_id AS hoje, v.id AS versao, v.lotacao_id AS lotacao, v.vale_a_partir_de AS desde
               FROM user_tenant ut
               LEFT JOIN lotacao_vigencia v ON v.user_id = ut.user_id AND v.tenant_id = ut.tenant_id
              WHERE ut.user_id = :pessoa AND ut.tenant_id = :escritorio',
            ['pessoa' => $pessoa->getId(), 'escritorio' => $escritorio->getId()],
        );
        if ($linhas === []) {
            return HistoricoDeLotacoes::semVinculo();
        }
        $versoes = [];
        foreach ($linhas as $linha) {
            if ($linha['versao'] !== null) {
                $versoes[] = [
                    'desde'   => $linha['desde'] !== null ? (string) $linha['desde'] : null,
                    'lotacao' => $linha['lotacao'] !== null ? (int) $linha['lotacao'] : null,
                ];
            }
        }

        return HistoricoDeLotacoes::doVinculo($linhas[0]['hoje'] !== null ? (int) $linhas[0]['hoje'] : null, $versoes);
    }
}
