<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaChecklistItem>
 */
class PastaChecklistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaChecklistItem::class);
    }

    /** @return PastaChecklistItem[] */
    public function findByPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.pasta = :pasta')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('c.ordem', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function proximaOrdem(Pasta $pasta, Tenant $tenant): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.ordem)')
            ->andWhere('c.pasta = :pasta')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return ($max === null ? 0 : (int) $max) + 1;
    }

    public function salvar(PastaChecklistItem $item, bool $flush = false): void
    {
        $this->getEntityManager()->persist($item);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PastaChecklistItem $item, bool $flush = false): void
    {
        $this->getEntityManager()->remove($item);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
