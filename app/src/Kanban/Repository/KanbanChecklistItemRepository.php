<?php

declare(strict_types=1);

namespace App\Kanban\Repository;

use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class KanbanChecklistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanChecklistItem::class);
    }

    public function salvar(KanbanChecklistItem $item, bool $flush = false): void
    {
        $this->getEntityManager()->persist($item);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(KanbanChecklistItem $item, bool $flush = false): void
    {
        $this->getEntityManager()->remove($item);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPorChecklistEId(int $id, KanbanChecklist $checklist): ?KanbanChecklistItem
    {
        return $this->createQueryBuilder('i')
            ->where('i.id = :id')
            ->andWhere('i.checklist = :checklist')
            ->setParameter('id', $id)
            ->setParameter('checklist', $checklist)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function proximaPosicaoNoChecklist(KanbanChecklist $checklist): int
    {
        $max = $this->createQueryBuilder('i')
            ->select('MAX(i.posicao)')
            ->where('i.checklist = :checklist')
            ->setParameter('checklist', $checklist)
            ->getQuery()
            ->getSingleScalarResult();

        return ($max ?? -1) + 1;
    }
}
