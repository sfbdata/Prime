<?php

declare(strict_types=1);

namespace App\Djen\Repository;

use App\Djen\Entity\OabMonitorada;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OabMonitorada>
 */
// Não-final: espelha ProcessoRepository e permite substituição por mock nos testes de UseCase.
class OabMonitoradaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OabMonitorada::class);
    }

    public function salvar(OabMonitorada $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(OabMonitorada $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?OabMonitorada
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Verifica duplicata (número+UF) dentro do escritório, escopando por tenant explicitamente
     * (seguro mesmo com o TenantFilter desligado — CLI/teste).
     */
    public function findByNumeroUfDoTenant(string $numero, string $uf, Tenant $tenant): ?OabMonitorada
    {
        return $this->findOneBy(['numero' => $numero, 'uf' => $uf, 'tenant' => $tenant]);
    }

    /**
     * OABs ativas do escritório (fonte da sincronização). Escopa por tenant explicitamente
     * porque o comando CLI roda com o TenantFilter desligado.
     *
     * @return OabMonitorada[]
     */
    public function listarAtivasDoTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.tenant = :tenant')
            ->andWhere('o.ativo = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todas as OABs do escritório (tela de gestão — conjunto pequeno e escopado por tenant).
     *
     * @return OabMonitorada[]
     */
    public function listarDoTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('o.ativo', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
