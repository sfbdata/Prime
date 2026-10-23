<?php

namespace App\Cliente\Repository;

use App\Cliente\Entity\ClientePJ;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClientePJ>
 */
class ClientePJRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientePJ::class);
    }

    /** Como o {@see ClientePFRepository::findOneByCpfDoTenant}: aceita CNPJ com máscara e só dígitos, tenant explícito. */
    public function findOneByCnpjDoTenant(string $digitos, Tenant $tenant): ?ClientePJ
    {
        $mascarado = \strlen($digitos) === 14
            ? sprintf('%s.%s.%s/%s-%s', substr($digitos, 0, 2), substr($digitos, 2, 3), substr($digitos, 5, 3), substr($digitos, 8, 4), substr($digitos, 12, 2))
            : $digitos;

        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.cnpj IN (:formas)')
            ->setParameter('tenant', $tenant)
            ->setParameter('formas', array_unique([$digitos, $mascarado]))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(ClientePJ $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ClientePJ $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
