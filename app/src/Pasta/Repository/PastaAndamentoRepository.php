<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaAndamento>
 */
class PastaAndamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaAndamento::class);
    }

    public function daPasta(Pasta $pasta, string $via, Tenant $tenant): ?PastaAndamento
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.pasta = :pasta')
            ->andWhere('a.via = :via')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('via', $via)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
