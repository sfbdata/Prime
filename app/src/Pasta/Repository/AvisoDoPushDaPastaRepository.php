<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AvisoDoPushDaPasta;
use App\Pasta\Entity\Pasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As chaves do "Avisar quando chegar" por pasta (spec push-avisar-quando-chegar §1). Toda consulta
 * leva o tenant explícito: a captação roda no CLI, sem TenantFilter.
 *
 * @extends ServiceEntityRepository<AvisoDoPushDaPasta>
 */
class AvisoDoPushDaPastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvisoDoPushDaPasta::class);
    }

    public function salvar(AvisoDoPushDaPasta $aviso, bool $flush = false): void
    {
        $this->getEntityManager()->persist($aviso);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function daPastaDoTenant(Pasta $pasta, Tenant $tenant): ?AvisoDoPushDaPasta
    {
        return $this->findOneBy(['pasta' => $pasta, 'tenant' => $tenant]);
    }

    /**
     * As linhas das pastas informadas, por id da pasta (pasta sem linha fica de fora: usa os padrões).
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, AvisoDoPushDaPasta>
     */
    public function porPastasDoTenant(Tenant $tenant, array $pastaIds): array
    {
        if ($pastaIds === []) {
            return [];
        }

        /** @var list<AvisoDoPushDaPasta> $linhas */
        $linhas = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('IDENTITY(a.pasta) IN (:pastas)')
            ->setParameter('tenant', $tenant)
            ->setParameter('pastas', $pastaIds)
            ->getQuery()
            ->getResult();

        $porPasta = [];
        foreach ($linhas as $linha) {
            $porPasta[(int) $linha->getPasta()->getId()] = $linha;
        }

        return $porPasta;
    }
}
