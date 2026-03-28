<?php

namespace App\Repository;

use App\Entity\Permission\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Permission>
 */
class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    /**
     * Retorna todas as permissões agrupadas por group (modules, resources, admin).
     *
     * @return array<string, Permission[]>
     */
    public function findAllGrouped(): array
    {
        $permissions = $this->createQueryBuilder('p')
            ->orderBy('p.group', 'ASC')
            ->addOrderBy('p.code', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($permissions as $permission) {
            $grouped[$permission->getGroup()][] = $permission;
        }

        return $grouped;
    }
}
