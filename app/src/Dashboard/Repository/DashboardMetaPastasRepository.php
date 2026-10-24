<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Dashboard\Entity\DashboardMetaPastas;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Meta de pastas do escritório (DASH-016). Toda consulta recebe o escritório e filtra por ele
 * (além do TenantFilter).
 *
 * @extends ServiceEntityRepository<DashboardMetaPastas>
 */
// Não-final: os testes de UseCase mockam o repositório do domínio.
class DashboardMetaPastasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DashboardMetaPastas::class);
    }

    public function salvar(DashboardMetaPastas $meta, bool $flush = false): void
    {
        $this->getEntityManager()->persist($meta);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * A meta que vale no mês de $data: a de vigência mais recente igual ou anterior a ele; no mesmo
     * mês, a última gravada. Sem meta definida até lá, nula.
     */
    public function buscarVigenteEm(Tenant $tenant, \DateTimeImmutable $data): ?DashboardMetaPastas
    {
        $mes = $data->setDate((int) $data->format('Y'), (int) $data->format('n'), 1)->setTime(0, 0);

        return $this->createQueryBuilder('m')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.mes <= :mes')
            ->setParameter('tenant', $tenant)
            ->setParameter('mes', $mes, 'date_immutable')
            ->orderBy('m.mes', 'DESC')
            ->addOrderBy('m.criadaEm', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
