<?php

declare(strict_types=1);

namespace App\Indicacao\Repository;

use App\Indicacao\Entity\IndicacaoConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A linha única de limites (id 1, criada pela migration).
 *
 * @extends ServiceEntityRepository<IndicacaoConfig>
 */
class IndicacaoConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicacaoConfig::class);
    }

    /** A configuração vigente; sem a linha (banco montado sem a migration), o padrão do desenho, ainda não gravado. */
    public function obter(): IndicacaoConfig
    {
        return $this->find(1) ?? new IndicacaoConfig();
    }

    public function salvar(IndicacaoConfig $config, bool $flush = false): void
    {
        $this->getEntityManager()->persist($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
