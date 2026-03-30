<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
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
     * Retorna todos os acessos específicos por item concedidos a um usuário.
     *
     * @return ResourceAccess[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['resourceType' => 'ASC', 'resourceId' => 'ASC']);
    }

    public function save(ResourceAccess $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
