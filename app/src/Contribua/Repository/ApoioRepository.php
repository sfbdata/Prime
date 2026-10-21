<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\Apoio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Apoio>
 */
// Não-final: os testes de UseCase mockam o repositório.
class ApoioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Apoio::class);
    }

    public function salvar(Apoio $apoio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($apoio);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O escritório registrou apoio desde `$desde`? ("Obrigado! Apoio registrado este mês") */
    public function apoiouDesde(int $tenantId, \DateTimeImmutable $desde): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('IDENTITY(a.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->andWhere('a.criadoEm >= :desde')->setParameter('desde', $desde)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /** Apoios registrados do escritório; `null` = todos (Master, pela `VisaoDoMaster`). */
    public function quantos(?int $tenantId): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        if ($tenantId !== null) {
            $qb->andWhere('IDENTITY(a.tenant) = :tenant')->setParameter('tenant', $tenantId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Dos escritórios dados, os que têm apoio CONFIRMADO pelo banco desde a data (tenant explícito, sem
     * depender do filtro da sessão: quem pergunta é outro domínio, sobre outros escritórios).
     *
     * @param list<int> $tenantIds
     *
     * @return list<int>
     */
    public function comApoioConfirmadoDesde(array $tenantIds, \DateTimeImmutable $desde): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $linhas = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT tenant_id FROM contribua_apoio WHERE tenant_id IN (?) AND confirmado_em >= ?',
            [$tenantIds, $desde->format('Y-m-d H:i:s')],
            [ArrayParameterType::INTEGER, ParameterType::STRING],
        );

        return array_map('intval', $linhas);
    }
}
