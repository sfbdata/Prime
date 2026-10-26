<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\IncidenteDeDesempenho;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Incidentes da seção Desempenho. GLOBAL, sem tenant (ver {@see IncidenteDeDesempenho}).
 *
 * @extends ServiceEntityRepository<IncidenteDeDesempenho>
 */
class IncidenteDeDesempenhoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidenteDeDesempenho::class);
    }

    public function salvar(IncidenteDeDesempenho $incidente, bool $flush = false): void
    {
        $this->getEntityManager()->persist($incidente);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<IncidenteDeDesempenho> os mais recentes primeiro */
    public function recentes(int $limite): array
    {
        return $this->createQueryBuilder('i')
            ->orderBy('i.abertoEm', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function contarAbertos(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.resolvidoEm IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
