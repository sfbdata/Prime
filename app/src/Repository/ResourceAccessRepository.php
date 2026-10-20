<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResourceAccess>
 */
class ResourceAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResourceAccess::class);
    }

    /**
     * Busca o registro de acesso de um usuário a um item específico.
     */
    public function findForUserAndResource(User $user, string $resourceType, int $resourceId): ?ResourceAccess
    {
        return $this->findOneBy([
            'user'         => $user,
            'resourceType' => $resourceType,
            'resourceId'   => $resourceId,
        ]);
    }

    /**
     * Retorna todos os acessos específicos de um conjunto de usuários, indexados por user_id.
     *
     * @param  User[]  $users
     * @return array<int, ResourceAccess[]>
     */
    public function findByUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }

        /** @var ResourceAccess[] $rows */
        $rows = $this->createQueryBuilder('ra')
            ->where('ra.user IN (:users)')
            ->setParameter('users', $users)
            ->orderBy('ra.resourceType', 'ASC')
            ->addOrderBy('ra.resourceId', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->getUser()?->getId()][] = $row;
        }

        return $map;
    }

    public function save(ResourceAccess $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Ids dos itens de um tipo que o usuário tem acesso item a item para a ação, no escritório
     * (a primeira camada de `PermissionChecker::canAccessResource`, em lote).
     *
     * @return list<int>
     */
    public function idsComAcesso(User $user, Tenant $tenant, string $resourceType, string $action): array
    {
        $coluna = match ($action) {
            ResourceAccess::ACTION_VIEW   => 'ra.canView',
            ResourceAccess::ACTION_EDIT   => 'ra.canEdit',
            ResourceAccess::ACTION_DELETE => 'ra.canDelete',
            default                       => null,
        };
        if ($coluna === null) {
            return [];
        }

        $ids = $this->createQueryBuilder('ra')
            ->select('ra.resourceId')
            ->andWhere('ra.user = :user')
            ->andWhere('ra.tenant = :tenant')
            ->andWhere('ra.resourceType = :tipo')
            ->andWhere($coluna . ' = true')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('tipo', $resourceType)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('intval', $ids));
    }
}
