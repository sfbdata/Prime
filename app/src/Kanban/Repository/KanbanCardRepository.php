<?php

declare(strict_types=1);

namespace App\Kanban\Repository;

use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KanbanCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanCard::class);
    }

    public function salvar(KanbanCard $card, bool $flush = false): void
    {
        $this->getEntityManager()->persist($card);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(KanbanCard $card, bool $flush = false): void
    {
        $this->getEntityManager()->remove($card);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPorTenantEId(int $id, Tenant $tenant): ?KanbanCard
    {
        return $this->createQueryBuilder('c')
            ->join('c.board', 'b')
            ->where('c.id = :id')
            ->andWhere('b.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return KanbanCard[] */
    public function findPorColunaOrdenados(KanbanColuna $coluna): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.coluna = :coluna')
            ->setParameter('coluna', $coluna)
            ->orderBy('c.posicao', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function proximaPosicaoNaColuna(KanbanColuna $coluna): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.posicao)')
            ->where('c.coluna = :coluna')
            ->setParameter('coluna', $coluna)
            ->getQuery()
            ->getSingleScalarResult();

        return ($max ?? -1) + 1;
    }
}
