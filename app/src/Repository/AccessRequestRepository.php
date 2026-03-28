<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRequest>
 */
class AccessRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequest::class);
    }

    /**
     * Verifica se já existe uma solicitação pendente do usuário para o item/ação.
     * Evita duplicatas na tabela.
     */
    public function findPendingForUserAndResource(
        User $user,
        string $resourceType,
        int $resourceId,
        string $action,
    ): ?AccessRequest {
        return $this->findOneBy([
            'user'         => $user,
            'resourceType' => $resourceType,
            'resourceId'   => $resourceId,
            'action'       => $action,
            'status'       => AccessRequest::STATUS_PENDING,
        ]);
    }

    /**
     * Lista todas as solicitações pendentes de um tenant (isolamento obrigatório).
     * O admin vê apenas solicitações de usuários do seu próprio tenant.
     *
     * @return AccessRequest[]
     */
    public function findPendingByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ar')
            ->join('ar.user', 'u')
            ->where('u.tenant = :tenant')
            ->andWhere('ar.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', AccessRequest::STATUS_PENDING)
            ->orderBy('ar.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(AccessRequest $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
