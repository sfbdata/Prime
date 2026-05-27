<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaSecao>
 */
class PastaSecaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaSecao::class);
    }

    /** @return PastaSecao[] */
    public function findByPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('s.ordem', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function proximaOrdem(Pasta $pasta, Tenant $tenant): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.ordem)')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return ($max === null ? 0 : (int) $max) + 1;
    }

    public function findByIdAndPastaAndTenant(int $id, Pasta $pasta, Tenant $tenant): ?PastaSecao
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.id = :id')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function salvar(PastaSecao $secao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($secao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PastaSecao $secao, bool $flush = false): void
    {
        $this->getEntityManager()->remove($secao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
