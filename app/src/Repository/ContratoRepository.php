<?php

namespace App\Repository;

use App\Entity\Contrato\Contrato;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contrato>
 */
class ContratoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contrato::class);
    }

    public function save(Contrato $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Contrato $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return Contrato[]
     */
    public function findByFilters(array $filters): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.clientes', 'cli');

        if (!empty($filters['cliente_id'])) {
            $qb->andWhere('cli.id = :clienteId')
               ->setParameter('clienteId', $filters['cliente_id']);
        }

        if (!empty($filters['data_inicio_de'])) {
            $qb->andWhere('c.dataInicio >= :dataInicioDe')
               ->setParameter('dataInicioDe', new \DateTime($filters['data_inicio_de']));
        }

        if (!empty($filters['data_inicio_ate'])) {
            $qb->andWhere('c.dataInicio <= :dataInicioAte')
               ->setParameter('dataInicioAte', new \DateTime($filters['data_inicio_ate']));
        }

        if (!empty($filters['valor_min'])) {
            $qb->andWhere('c.valorTotal >= :valorMin')
               ->setParameter('valorMin', (float) $filters['valor_min']);
        }

        if (!empty($filters['valor_max'])) {
            $qb->andWhere('c.valorTotal <= :valorMax')
               ->setParameter('valorMax', (float) $filters['valor_max']);
        }

        return $qb->orderBy('c.id', 'DESC')->getQuery()->getResult();
    }
}
