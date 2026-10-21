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

    /**
     * Os valores de `orgao` com a acentuação estragada pelo mapper antigo ("Ã"/"Â" + U+0080..U+00BF, ou o "Μ"
     * grego do "õ"), com a quantidade de cada um. Todos os escritórios: é o reparo do comando
     * `app:processo:corrigir-acentuacao-do-orgao`, e o nome do órgão é público.
     *
     * @return array<string, int> valor → quantidade
     */
    public function orgaosDasMovimentacoesComAcentuacaoEstragada(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT orgao AS valor, count(*) AS n FROM movimentacao_processo WHERE orgao ~ ('[ÃÂ][' || chr(128) || '-' || chr(191) || chr(924) || ']') GROUP BY orgao ORDER BY count(*) DESC, orgao",
        );

        $valores = [];
        foreach ($linhas as $linha) {
            $valores[(string) $linha['valor']] = (int) $linha['n'];
        }

        return $valores;
    }

    /** Troca um valor de `orgao` por outro em todos os escritórios (reparo da acentuação); devolve quantos mudaram. */
    public function trocarOrgaoDasMovimentacoes(string $antes, string $depois): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE App\Processo\Entity\MovimentacaoProcesso e SET e.orgao = :depois WHERE e.orgao = :antes',
        )->setParameter('antes', $antes)->setParameter('depois', $depois)->execute();
    }
}
