<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Tarefa\AtribuicaoTarefa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AtribuicaoTarefa>
 */
class AtribuicaoTarefaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AtribuicaoTarefa::class);
    }

    /**
     * @return AtribuicaoTarefa[]
     */
    public function findByUsuario(User $usuario): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.tarefa', 't')
            ->addSelect('t')
            ->where('a.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('a.dataAtribuicao', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
