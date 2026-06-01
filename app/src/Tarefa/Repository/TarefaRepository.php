<?php

declare(strict_types=1);

namespace App\Tarefa\Repository;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
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
    public function findByResponsavel(User $usuario): array
    {
        return $this->createQueryBuilder('t')
            ->where(':usuario MEMBER OF t.responsaveis OR t.criadoPor = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('t.dataCriacao', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Tarefa[]
     */
    public function findByProcesso(Processo $processo): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->where('p.processo = :processo')
            ->setParameter('processo', $processo)
            ->orderBy('t.dataCriacao', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(Tarefa $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countMetasAtivas(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{concluidas: int, total: int}
     */
    public function countMetasGlobal(Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $total = (int) (clone $qb)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $concluidas = (int) (clone $qb)
            ->select('COUNT(t.id)')
            ->andWhere('t.status = :concluida')
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->getQuery()
            ->getSingleScalarResult();

        return ['concluidas' => $concluidas, 'total' => $total];
    }

    /**
     * @return array<int, int>  userId => total (todos os status)
     */
    public function countPorResponsavel(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, int>  userId => total (status != concluida)
     */
    public function countAtivasPorResponsavel(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, int>  userId => total (ativas com prazo < $referencia)
     */
    public function countVencidasPorResponsavel(Tenant $tenant, \DateTimeImmutable $referencia): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo < :referencia')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->setParameter('referencia', $referencia)
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, int>  userId => total (ativas com prazo entre $referencia e $referencia+7 dias)
     */
    public function countPrazosProximosPorResponsavel(Tenant $tenant, \DateTimeImmutable $referencia): array
    {
        $limite = $referencia->modify('+7 days');

        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo >= :referencia')
            ->andWhere('t.prazo <= :limite')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->setParameter('referencia', $referencia)
            ->setParameter('limite', $limite)
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }
}
