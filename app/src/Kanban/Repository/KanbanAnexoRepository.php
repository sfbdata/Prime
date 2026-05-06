<?php

declare(strict_types=1);

namespace App\Kanban\Repository;

use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanAnexo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KanbanAnexoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanAnexo::class);
    }

    public function salvar(KanbanAnexo $anexo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($anexo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(KanbanAnexo $anexo, bool $flush = false): void
    {
        $this->getEntityManager()->remove($anexo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPorTenantEId(int $id, Tenant $tenant): ?KanbanAnexo
    {
        return $this->createQueryBuilder('a')
            ->join('a.card', 'c')
            ->join('c.board', 'b')
            ->where('a.id = :id')
            ->andWhere('b.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
