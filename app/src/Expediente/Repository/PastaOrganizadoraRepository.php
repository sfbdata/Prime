<?php

namespace App\Expediente\Repository;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\PastaOrganizadora;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PastaOrganizadoraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaOrganizadora::class);
    }

    /** @return PastaOrganizadora[] */
    public function findRaizPorTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.pai IS NULL')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.ordem', 'ASC')
            ->addOrderBy('p.nome', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPorTenant(int $id, Tenant $tenant): ?PastaOrganizadora
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
