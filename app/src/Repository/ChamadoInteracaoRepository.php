<?php

namespace App\Repository;

use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoInteracao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChamadoInteracao>
 */
class ChamadoInteracaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChamadoInteracao::class);
    }

    /**
     * Busca interações de um chamado (visíveis para o usuário)
     */
    public function findByChamado(Chamado $chamado, bool $incluirInternos = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.chamado = :chamado')
            ->setParameter('chamado', $chamado)
            ->orderBy('i.criadoEm', 'ASC');

        if (!$incluirInternos) {
            $qb->andWhere('i.interno = false');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta interações por chamado
     */
    public function countByChamado(Chamado $chamado): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.chamado = :chamado')
            ->setParameter('chamado', $chamado)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
