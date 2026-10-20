<?php

namespace App\Processo\Repository;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\MovimentacaoProcesso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MovimentacaoProcesso>
 */
class MovimentacaoProcessoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MovimentacaoProcesso::class);
    }

    public function save(MovimentacaoProcesso $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(MovimentacaoProcesso $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * A data da movimentação mais recente de cada processo pedido (relatório de pastas paradas).
     * Uma consulta para o lote, com o tenant explícito: o comando roda sem TenantFilter.
     *
     * @param list<int> $processoIds
     *
     * @return array<int, \DateTimeImmutable> processoId => data da última movimentação
     */
    public function ultimasPorProcesso(array $processoIds, Tenant $tenant): array
    {
        if ($processoIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.processo) AS processo_id', 'MAX(m.dataMovimentacao) AS ultima')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('IDENTITY(m.processo) IN (:ids)')
            ->andWhere('m.dataMovimentacao IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $processoIds)
            ->groupBy('m.processo')
            ->getQuery()
            ->getArrayResult();

        $porProcesso = [];
        foreach ($linhas as $l) {
            $porProcesso[(int) $l['processo_id']] = new \DateTimeImmutable((string) $l['ultima']);
        }

        return $porProcesso;
    }
}
