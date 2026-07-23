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
            // MAIS RECENTE PRIMEIRO: a observação recém-escrita é a que interessa ler, e é o que
            // as timelines da Pasta e da Meta já faziam. Uso exclusivo de exibição.
            ->orderBy('o.criadaEm', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
