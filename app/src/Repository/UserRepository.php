<?php

namespace App\Repository;

use App\Auth\Enum\StatusOab;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Busca por e-mail ignorando maiúsculas/minúsculas.
     *
     * O índice único de `user.email` é btree comum, sem `lower()`: para o banco,
     * `Ana@adv.com` e `ana@adv.com` são contas diferentes. Quem gravou o e-mail com
     * maiúscula loga normalmente (o login compara o valor cru), mas some de qualquer
     * busca normalizada — e, na recuperação de senha, sumir em silêncio é ficar
     * trancado para fora.
     *
     * Devolve LISTA, não um único registro, justamente para o chamador poder tratar a
     * ambiguidade: se duas contas diferirem só na caixa, nenhuma delas é "a" conta.
     *
     * @return User[]
     */
    public function encontrarPorEmailIgnorandoCaixa(string $email): array
    {
        return $this->createQueryBuilder('u')
            ->where('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->getQuery()
            ->getResult();
    }

    public function salvar(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->persist($user);

        if ($flush === true) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Opções do dropdown "usuário" da trilha de auditoria.
     *
     * Spec §6.4: com o hard delete do vínculo, quem foi removido some de qualquer JOIN em
     * `user_tenant` — mas o rastro dela continua em `audit_log`. Por isso a lista une DUAS
     * fontes, ambas escopadas ao tenant: quem tem vínculo (join de sempre) e os
     * `actor_user_id` distintos que aparecem no `audit_log` daquele tenant. Os dois JOINs são
     * LEFT (não INNER) porque o critério de entrada é "está em pelo menos uma das fontes", e o
     * GROUP BY tira a duplicata de quem está nas duas.
     *
     * `audit_log` não tem foreign key nenhuma (`tenant_id`/`actor_user_id` são inteiros soltos):
     * a condição `= :tenantId` já descarta sozinha as linhas com `tenant_id` NULL (NULL = valor
     * nunca é verdadeiro), e o mesmo vale para `actor_user_id` NULL — nenhum dos dois precisa de
     * tratamento explícito de nulo.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function findAuditFilterOptions(?int $tenantId): array
    {
        $queryBuilder = $this->createQueryBuilder('u')
            ->select('u.id AS id, u.email AS email')
            ->orderBy('u.email', 'ASC');

        if (is_int($tenantId)) {
            $queryBuilder
                ->leftJoin(
                    'App\Entity\Auth\UserTenant',
                    'ut',
                    'WITH',
                    'ut.user = u AND ut.tenant = :tenantId'
                )
                ->leftJoin(
                    AuditLog::class,
                    'al',
                    'WITH',
                    'al.actorUserId = u.id AND al.tenantId = :tenantId'
                )
                ->andWhere('ut.id IS NOT NULL OR al.id IS NOT NULL')
                ->groupBy('u.id, u.email')
                ->setParameter('tenantId', $tenantId);
        }

        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_values(array_map(
            static fn (array $row): array => [
                'value' => isset($row['id']) ? (string) $row['id'] : '',
                'label' => sprintf('%s (%s)', (string) ($row['email'] ?? '-'), (string) ($row['id'] ?? '-')),
            ],
            array_filter($rows, static fn (array $row): bool => isset($row['id']) && $row['id'] !== null)
        ));
    }

    /** @return User[] */
    public function findTodosPorTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('u')
            ->join(UserTenant::class, 'ut', 'WITH', 'ut.user = u AND ut.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return User[] */
    public function findColaboradoresAtivosPorTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('u')
            ->join(UserTenant::class, 'ut', 'WITH', 'ut.user = u AND ut.tenant = :tenant AND ut.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<int, string|null>  userId => nome do cargo (null se sem cargo) */
    public function findCargoPorColaboradores(Tenant $tenant): array
    {
        $rows = $this->getEntityManager()
            ->createQuery(
                'SELECT u.id AS userId, c.nome AS cargoNome
                 FROM App\Entity\Auth\UserTenant ut
                 JOIN ut.user u
                 LEFT JOIN ut.cargo c
                 WHERE ut.tenant = :tenant AND ut.isActive = true'
            )
            ->setParameter('tenant', $tenant)
            ->getArrayResult();

        $mapa = [];
        foreach ($rows as $row) {
            $mapa[(int) $row['userId']] = $row['cargoNome'];
        }

        return $mapa;
    }

    /** @return array<int, string|null>  userId => fotoUrl (null se sem perfil ou sem foto) */
    public function findFotoPorColaboradores(Tenant $tenant): array
    {
        $rows = $this->getEntityManager()
            ->createQuery(
                'SELECT u.id AS userId, p.fotoUrl AS fotoUrl
                 FROM App\Entity\Auth\UserTenant ut
                 JOIN ut.user u
                 LEFT JOIN u.profile p
                 WHERE ut.tenant = :tenant AND ut.isActive = true'
            )
            ->setParameter('tenant', $tenant)
            ->getArrayResult();

        $mapa = [];
        foreach ($rows as $row) {
            $mapa[(int) $row['userId']] = $row['fotoUrl'];
        }

        return $mapa;
    }

    public function findPorIdETenant(int $id, Tenant $tenant): ?User
    {
        return $this->createQueryBuilder('u')
            ->join(UserTenant::class, 'ut', 'WITH', 'ut.user = u AND ut.tenant = :tenant')
            ->andWhere('u.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Resolve um colaborador ATIVO do tenant por id — mesmo join (UserTenant + isActive) que
     * findColaboradoresAtivosPorTenant, para casar exatamente com o que os dropdowns mostram.
     * Usado para validar atribuições (id de outro escritório → null → 404 no controller).
     */
    public function findColaboradorAtivoPorIdETenant(int $id, Tenant $tenant): ?User
    {
        return $this->createQueryBuilder('u')
            ->join(UserTenant::class, 'ut', 'WITH', 'ut.user = u AND ut.tenant = :tenant AND ut.isActive = true')
            ->andWhere('u.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param int[] $ids
     * @return User[]
     */
    public function findPorIdsETenant(array $ids, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->join(UserTenant::class, 'ut', 'WITH', 'ut.user = u AND ut.tenant = :tenant')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Conta usuários da fila de revisão de OAB (platform/super-admin).
     *
     * @param list<StatusOab> $statuses vazio = todos com OAB (oab_status não nulo)
     */
    public function contarParaRevisaoOab(array $statuses, ?string $busca): int
    {
        return (int) $this->qbRevisaoOab($statuses, $busca)
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<StatusOab> $statuses vazio = todos com OAB (oab_status não nulo)
     * @return User[]
     */
    public function buscarParaRevisaoOab(array $statuses, ?string $busca, int $offset, int $limite): array
    {
        return $this->qbRevisaoOab($statuses, $busca)
            ->orderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Query base da revisão de OAB. **CROSS-TENANT proposital:** a revisão é platform-level
     * (super-admin) e opera sobre a identidade global do `User` — por isso NÃO há filtro de tenant
     * aqui (exceção consciente à regra de tenant obrigatório dos repositories). `User` não é
     * TenantAware, então nenhum filtro automático se aplica.
     *
     * @param list<StatusOab> $statuses
     */
    private function qbRevisaoOab(array $statuses, ?string $busca): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.oabStatus IS NOT NULL');

        if ($statuses !== []) {
            $qb->andWhere('u.oabStatus IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (StatusOab $s): string => $s->value, $statuses));
        }

        $busca = $busca !== null ? trim($busca) : '';
        if ($busca !== '') {
            $qb->andWhere('LOWER(u.fullName) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(u.oabNumero) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($busca) . '%');
        }

        return $qb;
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
