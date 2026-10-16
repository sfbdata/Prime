<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Prazos e audiências registrados à mão nas pastas. Toda consulta é pelo escritório.
 *
 * @extends ServiceEntityRepository<PastaPrazo>
 */
class PastaPrazoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaPrazo::class);
    }

    public function findDaPasta(int $id, Pasta $pasta, Tenant $tenant): ?PastaPrazo
    {
        return $this->createQueryBuilder('z')
            ->andWhere('z.id = :id')
            ->andWhere('z.pasta = :pasta')
            ->andWhere('z.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Prazos não cumpridos da pasta, do mais próximo ao mais distante.
     *
     * @return PastaPrazo[]
     */
    public function abertosDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('z')
            ->andWhere('z.pasta = :pasta')
            ->andWhere('z.tenant = :tenant')
            ->andWhere('z.cumpridoEm IS NULL')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('z.data', 'ASC')
            ->addOrderBy('z.hora', 'ASC')
            ->addOrderBy('z.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
