<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\Apoio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * O apoio confirmado pelo banco desta conta cujo prêmio ainda não foi visto (C4, desenho 08: um prêmio por
     * apoio confirmado). Escritório filtrado à mão.
     */
    public function comPremioPendente(int $tenantId): ?Apoio
    {
        return $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.tenant) = :tenant')
            ->andWhere('a.confirmadoEm IS NOT NULL')
            ->andWhere('a.idCora IS NOT NULL')
            ->andWhere('a.premioVistoEm IS NULL')
            ->setParameter('tenant', $tenantId)
            ->orderBy('a.confirmadoEm', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** O apoio informado (Já contribuí) ainda não confirmado mais antigo da conta, desde `desde`. */
    public function informadoSemConfirmacao(int $tenantId, \DateTimeImmutable $desde): ?Apoio
    {
        return $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.tenant) = :tenant')
            ->andWhere('a.confirmadoEm IS NULL')
            ->andWhere('a.idCora IS NULL')
            ->andWhere('a.criadoEm >= :desde')
            ->setParameter('tenant', $tenantId)
            ->setParameter('desde', $desde)
            ->orderBy('a.criadoEm', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Relatório de contribuições do Master (desenho 08, L253): soma por mês desde `$desde`, separando o
     * CONFIRMADO (pelo banco: `confirmado_em`, mês do pagamento) do só INFORMADO pelo escritório (mês do
     * registro). Dado de plataforma, de todos os escritórios: SQL nativo de propósito (o `date_trunc` não existe
     * em DQL e o filtro de tenant não se aplica), lido só pelo UseCase do relatório, atrás de ROLE_SUPER_ADMIN.
     *
     * @return list<array{mes: string, confirmado: int, informado: int}>
     */
    public function totaisPorMes(\DateTimeImmutable $desde): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT to_char(date_trunc('month', COALESCE(a.confirmado_em, a.criado_em)), 'YYYY-MM') AS mes,
                    COALESCE(SUM(a.valor_centavos) FILTER (WHERE a.confirmado_em IS NOT NULL), 0) AS confirmado,
                    COALESCE(SUM(a.valor_centavos) FILTER (WHERE a.confirmado_em IS NULL), 0) AS informado
               FROM contribua_apoio a
              WHERE COALESCE(a.confirmado_em, a.criado_em) >= :desde
              GROUP BY 1 ORDER BY 1",
            ['desde' => $desde->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (array $l): array => ['mes' => (string) $l['mes'], 'confirmado' => (int) $l['confirmado'], 'informado' => (int) $l['informado']], $linhas);
    }

    /**
     * Apoios CONFIRMADOS por conta, de sempre: nome do escritório (nada de e-mail, CPF ou CNPJ), se está
     * excluído (quarentena), soma, quantidade e a data do último. Uma consulta agregada, maior soma primeiro.
     *
     * @return list<array{escritorio: string, excluido: bool, total: int, quantidade: int, ultimo: string}>
     */
    public function totaisPorConta(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.name AS escritorio, (t.excluido_em IS NOT NULL) AS excluido, SUM(a.valor_centavos) AS total,
                    COUNT(a.id) AS quantidade, MAX(a.confirmado_em) AS ultimo
               FROM contribua_apoio a JOIN tenant t ON t.id = a.tenant_id
              WHERE a.confirmado_em IS NOT NULL
              GROUP BY t.id, t.name, t.excluido_em
              ORDER BY total DESC, t.name ASC',
        );

        return array_map(static fn (array $l): array => [
            'escritorio' => (string) $l['escritorio'],
            'excluido'   => (bool) $l['excluido'],
            'total'      => (int) $l['total'],
            'quantidade' => (int) $l['quantidade'],
            'ultimo'     => (string) $l['ultimo'],
        ], $linhas);
    }

    public function doIdNoBanco(string $idNoBanco): ?Apoio
    {
        return $this->findOneBy(['idCora' => $idNoBanco]);
    }

    public function doEscritorio(int $id, int $tenantId): ?Apoio
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')
            ->andWhere('IDENTITY(a.tenant) = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
