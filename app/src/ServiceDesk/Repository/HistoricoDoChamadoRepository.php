<?php

declare(strict_types=1);

namespace App\ServiceDesk\Repository;

use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Entity\HistoricoDoChamado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HistoricoDoChamado>
 */
class HistoricoDoChamadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HistoricoDoChamado::class);
    }

    public function salvar(HistoricoDoChamado $linha, bool $flush = false): void
    {
        $this->getEntityManager()->persist($linha);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<HistoricoDoChamado> do mais antigo ao mais novo */
    public function doChamado(ChamadoDeSuporte $chamado): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.chamado = :chamado')
            ->setParameter('chamado', $chamado)
            ->orderBy('h.criadoEm', 'ASC')
            ->addOrderBy('h.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
