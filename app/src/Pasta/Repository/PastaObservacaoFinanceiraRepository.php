<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaObservacaoFinanceira>
 */
final class PastaObservacaoFinanceiraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaObservacaoFinanceira::class);
    }

    /** @return PastaObservacaoFinanceira[] */
    public function findByPasta(Pasta $pasta, Tenant $tenant, int $limit = 100): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.pasta = :pasta')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('o.criadaEm', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
