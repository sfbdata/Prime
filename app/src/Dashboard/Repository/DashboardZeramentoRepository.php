<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Dashboard\Entity\DashboardZeramento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Zeramentos de coluna do Dashboard (DASH-014). Toda consulta recebe o escritório e filtra por ele
 * (além do TenantFilter).
 *
 * @extends ServiceEntityRepository<DashboardZeramento>
 */
// Não-final: os testes de UseCase mockam o repositório do domínio.
class DashboardZeramentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DashboardZeramento::class);
    }

    public function salvar(DashboardZeramento $zeramento, bool $flush = false): void
    {
        $this->getEntityManager()->persist($zeramento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Guarda de posse: zeramento de outro escritório volta nulo (o chamador responde 404). */
    public function buscarDoEscritorio(Tenant $tenant, int $id): ?DashboardZeramento
    {
        return $this->createQueryBuilder('z')
            ->andWhere('z.tenant = :tenant')
            ->andWhere('z.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os zeramentos que valem agora (não desfeitos) do escritório, do mais novo ao mais antigo.
     *
     * @return list<DashboardZeramento>
     */
    public function listarAtivos(Tenant $tenant): array
    {
        return $this->createQueryBuilder('z')
            ->andWhere('z.tenant = :tenant')
            ->andWhere('z.desfeitoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('z.criadoEm', 'DESC')
            ->addOrderBy('z.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
