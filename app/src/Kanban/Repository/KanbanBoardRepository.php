<?php

declare(strict_types=1);

namespace App\Kanban\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanBoard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KanbanBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanBoard::class);
    }

    public function salvar(KanbanBoard $board, bool $flush = false): void
    {
        $this->getEntityManager()->persist($board);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(KanbanBoard $board, bool $flush = false): void
    {
        $this->getEntityManager()->remove($board);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPorTenantEId(int $id, Tenant $tenant): ?KanbanBoard
    {
        return $this->createQueryBuilder('b')
            ->where('b.id = :id')
            ->andWhere('b.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return KanbanBoard[] */
    public function findAcessiveisPorUsuario(User $user, Tenant $tenant): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.participantes', 'p')
            ->where('b.tenant = :tenant')
            ->andWhere('b.criadoPor = :user OR p.id = :userId')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->orderBy('b.criadoEm', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
