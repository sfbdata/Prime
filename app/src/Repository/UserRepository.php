<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * @return array<int, array{value: string, label: string}>
     */
    public function findAuditFilterOptions(?int $tenantId): array
    {
        $queryBuilder = $this->createQueryBuilder('u')
            ->select('u.id AS id, u.email AS email')
            ->orderBy('u.email', 'ASC');

        if (is_int($tenantId)) {
            $queryBuilder
                ->join(
                    'App\Entity\Auth\UserTenant',
                    'ut',
                    'WITH',
                    'ut.user = u AND ut.tenant = :tenantId'
                )
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
