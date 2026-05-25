<?php

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaMensagem>
 */
class PastaMensagemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaMensagem::class);
    }

    /**
     * @return PastaMensagem[]
     */
    public function findByPasta(Pasta $pasta, Tenant $tenant, int $limit = 150): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.pasta = :pasta')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('m.criadaEm', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
