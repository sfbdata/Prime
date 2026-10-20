<?php

declare(strict_types=1);

namespace App\Indicacao\Repository;

use App\Indicacao\Entity\IndicacaoAlerta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os alertas de "Disparo alto de convites" (o card do Master mostra os 3 últimos).
 *
 * @extends ServiceEntityRepository<IndicacaoAlerta>
 */
class IndicacaoAlertaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicacaoAlerta::class);
    }

    public function salvar(IndicacaoAlerta $alerta, bool $flush = false): void
    {
        $this->getEntityManager()->persist($alerta);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<IndicacaoAlerta> */
    public function ultimos(int $quantos): array
    {
        /** @var list<IndicacaoAlerta> */
        return $this->createQueryBuilder('a')
            ->orderBy('a.em', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $quantos))
            ->getQuery()
            ->getResult();
    }
}
