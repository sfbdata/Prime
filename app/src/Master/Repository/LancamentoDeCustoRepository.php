<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\LancamentoDeCusto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Custos lançados pelo Master. GLOBAL, sem tenant (ver {@see LancamentoDeCusto}).
 *
 * @extends ServiceEntityRepository<LancamentoDeCusto>
 */
class LancamentoDeCustoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LancamentoDeCusto::class);
    }

    public function salvar(LancamentoDeCusto $lancamento, bool $flush = false): void
    {
        $this->getEntityManager()->persist($lancamento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @param list<LancamentoDeCusto> $lancamentos persiste todos e grava numa só transação */
    public function salvarVarios(array $lancamentos): void
    {
        foreach ($lancamentos as $lancamento) {
            $this->getEntityManager()->persist($lancamento);
        }
        $this->getEntityManager()->flush();
    }

    public function remover(LancamentoDeCusto $lancamento, bool $flush = false): void
    {
        $this->getEntityManager()->remove($lancamento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<LancamentoDeCusto> os mais recentes primeiro */
    public function doMes(string $mes): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.mes = :mes')
            ->setParameter('mes', $mes)
            ->orderBy('l.lancadoEm', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
