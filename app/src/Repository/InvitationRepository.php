<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Auth\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function encontrarPorToken(string $token): ?Invitation
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return Invitation[] */
    public function encontrarPendentesPorEmail(string $email): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.email = :email')
            ->andWhere('i.status = :status')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('email', $email)
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
