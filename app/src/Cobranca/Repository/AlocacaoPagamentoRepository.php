<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlocacaoPagamento>
 */
// Não-final: permite substituição por mock nos testes de UseCase e da CalculadoraSaldo.
class AlocacaoPagamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlocacaoPagamento::class);
    }

    public function salvar(AlocacaoPagamento $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(AlocacaoPagamento $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Σ, em centavos, do que foi alocado às obrigações informadas — fonte da subtração do saldo
     * derivado (SPEC §10/§12, invariável 20). A `CalculadoraSaldo` passa apenas as obrigações
     * EXIGÍVEIS, então alocações a obrigações substituídas por acordo saem junto. Escopo por tenant
     * explícito.
     *
     * @param int[] $obrigacaoIds
     */
    public function totalAlocadoEmObrigacoes(array $obrigacaoIds, Tenant $tenant): int
    {
        if ($obrigacaoIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.valor), 0)')
            ->andWhere('a.obrigacao IN (:ids)')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('ids', $obrigacaoIds)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Σ alocado POR OBRIGAÇÃO (centavos) das obrigações dos casos informados — versão em LOTE para a
     * agregação tenant-wide do Dashboard (Etapa 9), evitando um `totalAlocadoEmObrigacoes` por caso.
     * Retorna um mapa `obrigacaoId => Σ valor`. Escopo por tenant explícito. `$casoIds` vazio → `[]`.
     *
     * @param int[] $casoIds
     *
     * @return array<int, int>
     */
    public function somasPorObrigacaoDosCasos(array $casoIds, Tenant $tenant): array
    {
        if ($casoIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.obrigacao) AS obrigacaoId', 'COALESCE(SUM(a.valor), 0) AS total')
            ->innerJoin('a.obrigacao', 'o')
            ->andWhere('o.caso IN (:casos)')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('o.tenant = :tenant')
            ->groupBy('a.obrigacao')
            ->setParameter('casos', $casoIds)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getScalarResult();

        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(int) $linha['obrigacaoId']] = (int) $linha['total'];
        }

        return $mapa;
    }
}
