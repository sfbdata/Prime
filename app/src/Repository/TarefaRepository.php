<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Processo\Entity\Processo;
use App\Entity\Tarefa\Tarefa;
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
}
