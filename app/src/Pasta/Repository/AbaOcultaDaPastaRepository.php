<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Regras de aba oculta da pasta. Toda consulta leva o tenant explícito: a barreira das rotas roda
 * antes do controller e não confia só no `TenantFilter`.
 *
 * @extends ServiceEntityRepository<AbaOcultaDaPasta>
 */
class AbaOcultaDaPastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbaOcultaDaPasta::class);
    }

    public function salvar(AbaOcultaDaPasta $regra, bool $flush = false): void
    {
        $this->getEntityManager()->persist($regra);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(AbaOcultaDaPasta $regra, bool $flush = false): void
    {
        $this->getEntityManager()->remove($regra);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function daAba(Pasta $pasta, AbaDaPasta $aba, Tenant $tenant): ?AbaOcultaDaPasta
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.pasta = :pasta')
            ->andWhere('r.aba = :aba')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->setParameter('aba', $aba->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todas as regras da pasta, numa consulta só (a tela da pasta precisa de todas de uma vez).
     *
     * @return list<AbaOcultaDaPasta>
     */
    public function daPasta(Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<AbaOcultaDaPasta> $regras */
        $regras = $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getResult();

        return $regras;
    }
}
