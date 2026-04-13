<?php

namespace App\Expediente\Repository;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarcadorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Marcador::class);
    }

    /** @return Marcador[] */
    public function findRaizPorTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.pai IS NULL')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('m.ordem', 'ASC')
            ->addOrderBy('m.nome', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPorTenant(int $id, Tenant $tenant): ?Marcador
    {
        return $this->createQueryBuilder('m')
            ->where('m.id = :id')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Marcador[] — todos os marcadores do tenant (raiz + subs), ordenados */
    public function findTodosPorTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('m.ordem', 'ASC')
            ->addOrderBy('m.nome', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
