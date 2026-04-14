<?php

namespace App\Repository;

use App\Entity\Audit\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return AuditLog[]
     */
    public function findByFilters(
        ?string $entityClass,
        ?string $userFilter,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        ?int $tenantId,
        int $page = 1,
        int $perPage = 50
    ): array {
        $offset = max(0, ($page - 1) * $perPage);

        $queryBuilder = $this->buildFilteredQueryBuilder(
            $entityClass,
            $userFilter,
            $dateFrom,
            $dateTo,
            $tenantId
        )
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        return $queryBuilder->getQuery()->getResult();
    }

    public function countByFilters(
        ?string $entityClass,
        ?string $userFilter,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        ?int $tenantId
    ): int {
        $queryBuilder = $this->buildFilteredQueryBuilder(
            $entityClass,
            $userFilter,
            $dateFrom,
            $dateTo,
            $tenantId
        )
            ->select('COUNT(a.id)');

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * @return string[]
     */
    public function findDistinctEntityClasses(?int $tenantId): array
    {
        $queryBuilder = $this->createQueryBuilder('a')
            ->select('DISTINCT a.entityClass AS entityClass')
            ->andWhere('a.entityClass IS NOT NULL')
            ->orderBy('a.entityClass', 'ASC');

        if (is_int($tenantId)) {
            $queryBuilder
                ->andWhere('a.tenantId = :tenantId')
                ->setParameter('tenantId', $tenantId);
        }

        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_values(array_filter(array_map(
            static fn (array $row): ?string => isset($row['entityClass']) && is_string($row['entityClass']) ? $row['entityClass'] : null,
            $rows
        )));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function findDistinctActorOptions(?int $tenantId): array
    {
        $queryBuilder = $this->createQueryBuilder('a')
            ->select('DISTINCT a.actorUserId AS actorUserId, a.actorEmail AS actorEmail')
            ->andWhere('a.actorUserId IS NOT NULL OR a.actorEmail IS NOT NULL')
            ->orderBy('a.actorEmail', 'ASC');

        if (is_int($tenantId)) {
            $queryBuilder
                ->andWhere('a.tenantId = :tenantId')
                ->setParameter('tenantId', $tenantId);
        }

        $rows = $queryBuilder->getQuery()->getArrayResult();
        $options = [];

        foreach ($rows as $row) {
            $actorUserId = isset($row['actorUserId']) && is_numeric($row['actorUserId']) ? (int) $row['actorUserId'] : null;
            $actorEmail = isset($row['actorEmail']) && is_string($row['actorEmail']) ? trim($row['actorEmail']) : '';

            if (is_int($actorUserId)) {
                $value = (string) $actorUserId;
                $label = $actorEmail !== '' ? sprintf('%s (%d)', $actorEmail, $actorUserId) : sprintf('ID %d', $actorUserId);
            } elseif ($actorEmail !== '') {
                $value = $actorEmail;
                $label = $actorEmail;
            } else {
                continue;
            }

            $options[$value] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        ksort($options);

        return array_values($options);
    }

    /**
     * Busca todos os eventos de auditoria relevantes para a timeline de uma Pasta.
     * Usa UNION ALL via DBAL para poder usar operadores JSON do PostgreSQL.
     *
     * @return array<int, array{id:int, action:string, entity_class:string, entity_id:string|null, changes:string|null, actor_email:string|null, created_at:string}>
     */
    public function findForPastaTimeline(
        int $pastaId,
        int $tenantId,
        ?int $processoId,
        int $limit = 150
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        $processoClause = '';
        if ($processoId !== null) {
            $processoClause = "
            UNION ALL
            SELECT id, action, entity_class, entity_id, changes, actor_email, created_at
            FROM audit_log
            WHERE entity_class = 'App\\Entity\\Processo\\Processo'
              AND tenant_id = :tenantId
              AND entity_id = :processoIdStr";
        }

        $sql = "
            SELECT id, action, entity_class, entity_id, changes, actor_email, created_at
            FROM audit_log
            WHERE entity_class = 'App\\Entity\\Pasta\\Pasta'
              AND tenant_id = :tenantId
              AND entity_id = :pastaIdStr
            UNION ALL
            SELECT id, action, entity_class, entity_id, changes, actor_email, created_at
            FROM audit_log
            WHERE entity_class = 'App\\Entity\\Pasta\\PastaDocumento'
              AND tenant_id = :tenantId
              AND (
                entity_id IN (SELECT id::text FROM pasta_documento WHERE pasta_id = :pastaId)
                OR (changes->'diff'->'after'->'pasta'->>'id')::text = :pastaIdStr
                OR (changes->'diff'->'before'->'pasta'->>'id')::text = :pastaIdStr
              )
            UNION ALL
            SELECT id, action, entity_class, entity_id, changes, actor_email, created_at
            FROM audit_log
            WHERE entity_class = 'App\\Entity\\Pasta\\ParteContraria'
              AND tenant_id = :tenantId
              AND (
                entity_id IN (SELECT id::text FROM parte_contraria WHERE pasta_id = :pastaId)
                OR (changes->'diff'->'after'->'pasta'->>'id')::text = :pastaIdStr
              )
            {$processoClause}
            ORDER BY created_at DESC
            LIMIT :limit
        ";

        $params = [
            'pastaId'    => $pastaId,
            'pastaIdStr' => (string) $pastaId,
            'tenantId'   => $tenantId,
            'limit'      => $limit,
        ];

        if ($processoId !== null) {
            $params['processoIdStr'] = (string) $processoId;
        }

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    private function buildFilteredQueryBuilder(
        ?string $entityClass,
        ?string $userFilter,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        ?int $tenantId
    ) {
        $queryBuilder = $this->createQueryBuilder('a');

        if (is_string($entityClass) && trim($entityClass) !== '') {
            $queryBuilder
                ->andWhere('a.entityClass = :entityClass')
                ->setParameter('entityClass', trim($entityClass));
        }

        if (is_string($userFilter) && trim($userFilter) !== '') {
            $userFilterTrimmed = trim($userFilter);

            if (ctype_digit($userFilterTrimmed)) {
                $queryBuilder
                    ->andWhere('a.actorUserId = :actorUserId')
                    ->setParameter('actorUserId', (int) $userFilterTrimmed);
            } else {
                $queryBuilder
                    ->andWhere('a.actorEmail LIKE :actorEmail')
                    ->setParameter('actorEmail', '%'.$userFilterTrimmed.'%');
            }
        }

        if ($dateFrom instanceof \DateTimeImmutable) {
            $queryBuilder
                ->andWhere('a.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom->setTime(0, 0, 0));
        }

        if ($dateTo instanceof \DateTimeImmutable) {
            $queryBuilder
                ->andWhere('a.createdAt <= :dateTo')
                ->setParameter('dateTo', $dateTo->setTime(23, 59, 59));
        }

        if (is_int($tenantId)) {
            $queryBuilder
                ->andWhere('a.tenantId = :tenantId')
                ->setParameter('tenantId', $tenantId);
        }

        return $queryBuilder;
    }
}