<?php

namespace App\Repository;

use App\Entity\Auth\User;
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
                ->andWhere('u.tenant = :tenantId')
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
