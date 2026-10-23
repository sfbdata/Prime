<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Entity\Tenant\Tenant;
use App\Tenant\Entity\ConfiguracaoDeEdicao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A opção de edição sem prazo do primário, por escritório. Tenant explícito em toda consulta.
 *
 * @extends ServiceEntityRepository<ConfiguracaoDeEdicao>
 */
class ConfiguracaoDeEdicaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDeEdicao::class);
    }

    public function doEscritorio(Tenant $tenant): ?ConfiguracaoDeEdicao
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Sem linha, a opção vale ligada (spec §3). */
    public function primarioSemPrazo(Tenant $tenant): bool
    {
        return $this->doEscritorio($tenant)?->isPrimarioSemPrazo() ?? true;
    }

    public function salvar(ConfiguracaoDeEdicao $configuracao): void
    {
        $this->getEntityManager()->persist($configuracao);
        $this->getEntityManager()->flush();
    }
}
