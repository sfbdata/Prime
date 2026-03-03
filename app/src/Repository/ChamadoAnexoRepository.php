<?php

namespace App\Repository;

use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoAnexo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChamadoAnexo>
 */
class ChamadoAnexoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChamadoAnexo::class);
    }

    /**
     * Busca anexos de um chamado
     */
    public function findByChamado(Chamado $chamado): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.chamado = :chamado')
            ->setParameter('chamado', $chamado)
            ->orderBy('a.criadoEm', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcula tamanho total dos anexos de um chamado
     */
    public function getTamanhoTotalByChamado(Chamado $chamado): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('SUM(a.tamanho)')
            ->where('a.chamado = :chamado')
            ->setParameter('chamado', $chamado)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
