<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\Conversa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Conversa> */
class ConversaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversa::class);
    }

    /** A conversa individual do par, em qualquer ordem — o par é guardado ordenado. */
    public function individualEntre(Tenant $tenant, User $a, User $b): ?Conversa
    {
        [$menor, $maior] = (int) $a->getId() < (int) $b->getId() ? [$a, $b] : [$b, $a];

        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuarioMenor = :menor')
            ->andWhere('c.usuarioMaior = :maior')
            ->setParameter('tenant', $tenant)
            ->setParameter('menor', $menor)
            ->setParameter('maior', $maior)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function salvar(Conversa $conversa): void
    {
        $this->getEntityManager()->persist($conversa);
    }
}
