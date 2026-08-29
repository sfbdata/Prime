<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaPagamento>
 */
final class PastaPagamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaPagamento::class);
    }

    /**
     * Todos os pagamentos da pasta, do vencimento mais próximo para o mais
     * distante — a ordem em que o card do trilho lê, e a mesma em que os totais
     * são somados. O desempate por id mantém a lista estável entre dois
     * lançamentos com o mesmo vencimento.
     *
     * @return PastaPagamento[]
     */
    public function findByPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('p.vencimento', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Guarda de posse: o pagamento só existe se for DESTA pasta e DESTE
     * escritório. Quem chama devolve 404 quando vier nulo — 403 confirmaria
     * que o id existe em algum lugar.
     */
    public function findByIdAndPastaAndTenant(int $id, Pasta $pasta, Tenant $tenant): ?PastaPagamento
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
