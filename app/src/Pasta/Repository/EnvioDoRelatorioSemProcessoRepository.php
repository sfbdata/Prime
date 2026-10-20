<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EnvioDoRelatorioSemProcesso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnvioDoRelatorioSemProcesso>
 */
class EnvioDoRelatorioSemProcessoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvioDoRelatorioSemProcesso::class);
    }

    public function salvar(EnvioDoRelatorioSemProcesso $envio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($envio);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Os últimos envios do escritório, do mais antigo para o mais novo (a ordem da tabela
     * "Evolução", L4547: `H.slice(-6)`).
     *
     * @return list<EnvioDoRelatorioSemProcesso>
     */
    public function ultimos(Tenant $tenant, int $limite): array
    {
        $recentes = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('e.em', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return array_values(array_reverse($recentes));
    }

    public function existeNoMes(Tenant $tenant, string $mes): bool
    {
        return $this->count(['tenant' => $tenant, 'mes' => $mes]) > 0;
    }
}
