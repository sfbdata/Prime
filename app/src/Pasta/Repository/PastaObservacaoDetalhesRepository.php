<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaObservacaoDetalhes>
 */
final class PastaObservacaoDetalhesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaObservacaoDetalhes::class);
    }

    /** @return PastaObservacaoDetalhes[] */
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
