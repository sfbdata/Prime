<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    /**
     * Conta os escritórios ATIVOS criados por um usuário (dos quais ele é dono).
     * Base do limite de escritórios próprios (RN08). Escritórios excluídos
     * (soft delete, isActive=false) não contam — liberam vaga.
     */
    public function contarPorCriador(User $criador): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.criadoPor = :criador')
            ->andWhere('t.isActive = true')
            ->setParameter('criador', $criador)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Escritórios elegíveis à purga definitiva (hard delete): soft-deletados
     * (isActive=false) cuja quarentena (excluidoEm) já passou do limite de carência.
     * Enquanto dentro da carência, o suporte ainda pode reativar (RN09).
     *
     * @return Tenant[]
     */
    public function encontrarPurgaveis(\DateTimeImmutable $limite): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.isActive = false')
            ->andWhere('t.excluidoEm IS NOT NULL')
            ->andWhere('t.excluidoEm <= :limite')
            ->setParameter('limite', $limite)
            ->orderBy('t.excluidoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
