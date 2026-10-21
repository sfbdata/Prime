<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\MotivoDeNaoApoiar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MotivoDeNaoApoiar>
 */
// Não-final: os testes de UseCase mockam o repositório.
class MotivoDeNaoApoiarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MotivoDeNaoApoiar::class);
    }

    public function salvar(MotivoDeNaoApoiar $motivo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($motivo);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function respondeuDesde(int $tenantId, \DateTimeImmutable $desde): bool
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('IDENTITY(m.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->andWhere('m.criadoEm >= :desde')->setParameter('desde', $desde)
            ->getQuery()->getSingleScalarResult() > 0;
    }
}
