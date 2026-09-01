<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\PastaChecklistModeloItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaChecklistModeloItem>
 *
 * Os itens são sempre acessados pelo modelo (que os carrega ordenados), nunca soltos —
 * por isso aqui não há query própria: teria de filtrar por tenant e duplicaria o que o
 * mapeamento já entrega.
 */
class PastaChecklistModeloItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaChecklistModeloItem::class);
    }
}
