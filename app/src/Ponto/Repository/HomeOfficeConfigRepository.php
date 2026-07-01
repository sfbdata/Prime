<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\HomeOfficeConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HomeOfficeConfig>
 */
final class HomeOfficeConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HomeOfficeConfig::class);
    }

    public function salvar(HomeOfficeConfig $config, bool $flush = false): void
    {
        $this->getEntityManager()->persist($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(HomeOfficeConfig $config, bool $flush = false): void
    {
        $this->getEntityManager()->remove($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Config de home office de um colaborador em um tenant. Filtro de tenant EXPLÍCITO — o
     * TenantFilter fica desligado em CLI/teste, então nunca confiar só nele (é bypass de geofencing).
     */
    public function findByUserETenant(User $user, Tenant $tenant): ?HomeOfficeConfig
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.user = :user')
            ->andWhere('h.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
