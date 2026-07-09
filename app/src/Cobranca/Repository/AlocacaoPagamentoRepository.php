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
}
