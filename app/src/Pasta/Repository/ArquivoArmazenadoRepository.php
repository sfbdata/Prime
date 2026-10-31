<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\ArquivoArmazenado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ArquivoArmazenado> */
class ArquivoArmazenadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArquivoArmazenado::class);
    }

    public function existeCodigo(int $tenantId, string $codigo): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('IDENTITY(a.tenant) = :t')->andWhere('a.codigo = :c')
            ->setParameter('t', $tenantId)->setParameter('c', $codigo)
            ->getQuery()->getSingleScalarResult() > 0;
    }
}
