<?php

declare(strict_types=1);

namespace App\Kanban\Repository;

use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanColuna;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KanbanColunaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanColuna::class);
    }

    public function salvar(KanbanColuna $coluna, bool $flush = false): void
    {
        $this->getEntityManager()->persist($coluna);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPorBoardOrdenada(KanbanBoard $board): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.board = :board')
            ->setParameter('board', $board)
            ->orderBy('c.posicao', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPorBoardEId(int $id, KanbanBoard $board): ?KanbanColuna
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->andWhere('c.board = :board')
            ->setParameter('id', $id)
            ->setParameter('board', $board)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
