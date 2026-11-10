<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Aceites mensais da folha (E16, decisão 1): histórico numerado por pessoa, escritório e mês, e vale o último. Toda
 * consulta leva o escritório escrito à mão.
 *
 * @extends ServiceEntityRepository<AceiteDaFolhaDoPonto>
 */
class AceiteDaFolhaDoPontoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AceiteDaFolhaDoPonto::class);
    }

    /** Só persiste: quem grava o aceite decide o flush (o aceite e o evento da trilha saem na mesma transação). */
    public function adicionar(AceiteDaFolhaDoPonto $aceite): void
    {
        $this->getEntityManager()->persist($aceite);
    }

    /** O aceite que vale (o de maior número) da pessoa NESTE escritório e nesta competência; null se não assinou. */
    public function ultimoDaCompetencia(Tenant $escritorio, User $pessoa, int $ano, int $mes): ?AceiteDaFolhaDoPonto
    {
        return $this->daCompetencia($escritorio, $pessoa, $ano, $mes)
            ->orderBy('a.numero', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * O número do próximo aceite da competência: 1 na primeira assinatura, o último mais um nas seguintes. Dois
     * pedidos ao mesmo tempo recebem o mesmo número e o índice único recusa o segundo.
     */
    public function proximoNumero(Tenant $escritorio, User $pessoa, int $ano, int $mes): int
    {
        $ultimo = $this->daCompetencia($escritorio, $pessoa, $ano, $mes)
            ->select('MAX(a.numero)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $ultimo + 1;
    }

    private function daCompetencia(Tenant $escritorio, User $pessoa, int $ano, int $mes): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :escritorio')
            ->andWhere('a.user = :pessoa')
            ->andWhere('a.ano = :ano')
            ->andWhere('a.mes = :mes')
            ->setParameter('escritorio', $escritorio)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('ano', $ano)
            ->setParameter('mes', $mes);
    }
}
