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

    /**
     * Nome já usado por outro marcador do escritório, sem diferenciar maiúsculas (desenho 1.1: "Já existe
     * um marcador com esse nome"). `$excetoId` é o próprio marcador, na edição.
     */
    public function existeNomeNoTenant(string $nome, Tenant $tenant, ?int $excetoId = null): bool
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.tenant = :tenant')
            ->andWhere('LOWER(m.nome) = :nome')
            ->setParameter('tenant', $tenant)
            ->setParameter('nome', mb_strtolower(trim($nome)));

        if ($excetoId !== null) {
            $qb->andWhere('m.id <> :exceto')->setParameter('exceto', $excetoId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Irmãos de um nível do trilho (marcadores de topo quando `$pai` é null), na ordem da tela.
     *
     * @return Marcador[]
     */
    public function findIrmaos(?Marcador $pai, Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('m.ordem', 'ASC')
            ->addOrderBy('m.nome', 'ASC')
            ->addOrderBy('m.id', 'ASC');

        if ($pai === null) {
            $qb->andWhere('m.pai IS NULL');
        } else {
            $qb->andWhere('m.pai = :pai')->setParameter('pai', $pai);
        }

        return $qb->getQuery()->getResult();
    }
}
