<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserTenant>
 */
class UserTenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTenant::class);
    }

    /** @return UserTenant[] */
    public function findActiveByUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'isActive' => true]);
    }

    public function existeVinculoAtivo(User $user, Tenant $tenant): bool
    {
        return $this->createQueryBuilder('ut')
            ->select('COUNT(ut.id)')
            ->andWhere('ut.user = :user')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findAtivoPorUserETenant(User $user, Tenant $tenant): ?UserTenant
    {
        return $this->findOneBy(['user' => $user, 'tenant' => $tenant, 'isActive' => true]);
    }

    public function findPorUserETenant(User $user, Tenant $tenant): ?UserTenant
    {
        return $this->findOneBy(['user' => $user, 'tenant' => $tenant]);
    }
}
