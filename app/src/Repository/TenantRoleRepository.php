<?php

namespace App\Repository;

use App\Entity\Tenant\Tenant;
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

    /**
     * O perfil do primário (isSystem) do escritório. O TenantBootstrapService cria um só; se a
     * história deixou mais de um, vale o mais antigo, para a escolha ser estável.
     */
    public function findPerfilDoPrimario(Tenant $tenant): ?TenantRole
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.isSystem = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Perfis secundários (não isSystem) do escritório, por nome.
     *
     * @return TenantRole[]
     */
    public function findSecundarios(Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.isSystem = false')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
