<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\ConfiguracaoDePresenca;
use App\Central\Entity\Presenca;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A regra de presença do escritório (fase 3). Tenant explícito em toda consulta (regra 4 da Central).
 *
 * @extends ServiceEntityRepository<ConfiguracaoDePresenca>
 */
class ConfiguracaoDePresencaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDePresenca::class);
    }

    public function doEscritorio(Tenant $tenant): ?ConfiguracaoDePresenca
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** O limite do escritório, em minutos; sem escolha, o padrão do desenho (60). */
    public function minutosAusente(Tenant $tenant): int
    {
        return $this->doEscritorio($tenant)?->getMinutosAusente() ?? Presenca::INATIVO_APOS_MINUTOS;
    }

    public function salvar(ConfiguracaoDePresenca $configuracao): void
    {
        $this->getEntityManager()->persist($configuracao);
        $this->getEntityManager()->flush();
    }
}
