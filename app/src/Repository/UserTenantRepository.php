<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserTenant>
 */
final class UserTenantRepository extends ServiceEntityRepository
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
}
