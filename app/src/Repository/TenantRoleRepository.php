<?php

namespace App\Repository;

use App\Entity\Tenant\TenantRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantRole>
 */
class TenantRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantRole::class);
    }

    /**
     * Retorna todos os perfis de um tenant ordenados por nome.
     *
     * @return TenantRole[]
     */
    public function findByTenantId(int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('r.isSystem', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
