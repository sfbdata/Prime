<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\LancamentoHorasPagas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LancamentoHorasPagas>
 */
class LancamentoHorasPagasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LancamentoHorasPagas::class);
    }

    /**
     * Soma, com sinal, os lançamentos do colaborador naquela competência. 0 quando não há nenhum.
     *
     * Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
     */
    public function somarPorCompetencia(User $user, Tenant $tenant, int $ano, int $mes): int
    {
        $soma = $this->createQueryBuilder('l')
            ->select('SUM(l.minutos)')
            ->andWhere('l.user = :user')
            ->andWhere('l.tenant = :tenant')
            ->andWhere('l.ano = :ano')
            ->andWhere('l.mes = :mes')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('ano', $ano)
            ->setParameter('mes', $mes)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $soma;
    }

    /**
     * Lançamentos do colaborador no escritório, mais recentes primeiro. Alimenta a ficha do admin.
     *
     * @return LancamentoHorasPagas[]
     */
    public function listarPorUser(User $user, Tenant $tenant): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->orderBy('l.ano', 'DESC')
            ->addOrderBy('l.mes', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca por id EXIGINDO o tenant — nunca buscar lançamento só por id vindo da URL (IDOR).
     */
    public function buscarDoTenant(int $id, Tenant $tenant): ?LancamentoHorasPagas
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.id = :id')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
