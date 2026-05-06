<?php

declare(strict_types=1);

namespace App\Kanban\Repository;

use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanComentario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KanbanComentarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanComentario::class);
    }

    public function salvar(KanbanComentario $comentario, bool $flush = false): void
    {
        $this->getEntityManager()->persist($comentario);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(KanbanComentario $comentario, bool $flush = false): void
    {
        $this->getEntityManager()->remove($comentario);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPorTenantEId(int $id, Tenant $tenant): ?KanbanComentario
    {
        return $this->createQueryBuilder('cm')
            ->join('cm.card', 'c')
            ->join('c.board', 'b')
            ->where('cm.id = :id')
            ->andWhere('b.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
