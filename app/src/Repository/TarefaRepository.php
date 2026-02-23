<?php

namespace App\Repository;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tarefa>
 */
class TarefaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tarefa::class);
    }

    /**
     * @return Tarefa[]
     */
    public function findByTenantForAdmin(?Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.atribuicoes', 'a')
            ->addSelect('a')
            ->orderBy('t.dataCriacao', 'DESC');

        if ($tenant !== null) {
            $qb->andWhere('EXISTS (
                SELECT 1
                FROM App\\Entity\\Tarefa\\AtribuicaoTarefa a2
                JOIN a2.usuario u2
                WHERE a2.tarefa = t
                AND u2.tenant = :tenant
            )')
                ->setParameter('tenant', $tenant);
        }

        return $qb->getQuery()->getResult();
    }

    public function save(Tarefa $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
