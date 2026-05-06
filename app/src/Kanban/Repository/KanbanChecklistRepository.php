<?php

declare(strict_types=1);

namespace App\Kanban\Repository;

use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KanbanChecklistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanChecklist::class);
    }

    public function salvar(KanbanChecklist $checklist, bool $flush = false): void
    {
        $this->getEntityManager()->persist($checklist);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(KanbanChecklist $checklist, bool $flush = false): void
    {
        $this->getEntityManager()->remove($checklist);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPorCardEId(int $id, KanbanCard $card): ?KanbanChecklist
    {
        return $this->createQueryBuilder('cl')
            ->where('cl.id = :id')
            ->andWhere('cl.card = :card')
            ->setParameter('id', $id)
            ->setParameter('card', $card)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function proximaPosicaoNoCard(KanbanCard $card): int
    {
        $max = $this->createQueryBuilder('cl')
            ->select('MAX(cl.posicao)')
            ->where('cl.card = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->getSingleScalarResult();

        return ($max ?? -1) + 1;
    }
}
