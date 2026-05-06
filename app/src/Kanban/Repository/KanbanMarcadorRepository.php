<?php

declare(strict_types=1);

namespace App\Kanban\Repository;

use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanMarcador;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KanbanMarcadorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanMarcador::class);
    }

    public function salvar(KanbanMarcador $marcador, bool $flush = false): void
    {
        $this->getEntityManager()->persist($marcador);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(KanbanMarcador $marcador, bool $flush = false): void
    {
        $this->getEntityManager()->remove($marcador);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPorBoardEId(int $id, KanbanBoard $board): ?KanbanMarcador
    {
        return $this->createQueryBuilder('m')
            ->where('m.id = :id')
            ->andWhere('m.board = :board')
            ->setParameter('id', $id)
            ->setParameter('board', $board)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return KanbanMarcador[] */
    public function findPorBoard(KanbanBoard $board): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.board = :board')
            ->setParameter('board', $board)
            ->orderBy('m.nome', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
