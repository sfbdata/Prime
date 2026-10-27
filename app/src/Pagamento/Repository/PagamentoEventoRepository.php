<?php

declare(strict_types=1);

namespace App\Pagamento\Repository;

use App\Pagamento\Entity\PagamentoEvento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PagamentoEvento>
 */
// Não-final: os testes de UseCase mockam o repositório.
class PagamentoEventoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PagamentoEvento::class);
    }

    public function salvar(PagamentoEvento $evento): void
    {
        $this->getEntityManager()->persist($evento);
    }

    /** @return list<PagamentoEvento> a trilha do pagamento, do mais antigo ao mais novo */
    public function doPagamento(int $pagamentoId, int $tenantId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.pagamento) = :p')->setParameter('p', $pagamentoId)
            ->andWhere('IDENTITY(e.tenant) = :t')->setParameter('t', $tenantId)
            ->orderBy('e.em', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** A trilha já tem esse tipo? (registros que não podem repetir a cada reconsulta, como o teste do sandbox) */
    public function jaTem(int $pagamentoId, string $tipo): bool
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('IDENTITY(e.pagamento) = :p')->setParameter('p', $pagamentoId)
            ->andWhere('e.tipo = :t')->setParameter('t', $tipo)
            ->getQuery()->getSingleScalarResult() > 0;
    }
}
