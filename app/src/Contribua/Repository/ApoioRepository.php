<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\Apoio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Apoio>
 */
// Não-final: os testes de UseCase mockam o repositório.
class ApoioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Apoio::class);
    }

    public function salvar(Apoio $apoio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($apoio);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O escritório registrou apoio desde `$desde`? ("Obrigado! Apoio registrado este mês") */
    public function apoiouDesde(int $tenantId, \DateTimeImmutable $desde): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('IDENTITY(a.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->andWhere('a.criadoEm >= :desde')->setParameter('desde', $desde)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /** Apoios registrados do escritório; `null` = todos (Master, pela `VisaoDoMaster`). */
    public function quantos(?int $tenantId): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        if ($tenantId !== null) {
            $qb->andWhere('IDENTITY(a.tenant) = :tenant')->setParameter('tenant', $tenantId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * O apoio confirmado pelo banco desta conta cujo prêmio ainda não foi visto (C4, desenho 08: um prêmio por
     * apoio confirmado). Escritório filtrado à mão.
     */
    public function comPremioPendente(int $tenantId): ?Apoio
    {
        return $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.tenant) = :tenant')
            ->andWhere('a.confirmadoEm IS NOT NULL')
            ->andWhere('a.idCora IS NOT NULL')
            ->andWhere('a.premioVistoEm IS NULL')
            ->setParameter('tenant', $tenantId)
            ->orderBy('a.confirmadoEm', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function doEscritorio(int $id, int $tenantId): ?Apoio
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')
            ->andWhere('IDENTITY(a.tenant) = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
