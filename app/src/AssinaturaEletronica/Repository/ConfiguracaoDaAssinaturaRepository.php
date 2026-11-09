<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Repository;

use App\AssinaturaEletronica\Entity\ConfiguracaoDaAssinatura;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Uma linha por escritório, criada na primeira vez que alguém escolhe a logo padrão.
 *
 * @extends ServiceEntityRepository<ConfiguracaoDaAssinatura>
 */
class ConfiguracaoDaAssinaturaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDaAssinatura::class);
    }

    public function doEscritorio(Tenant $tenant): ?ConfiguracaoDaAssinatura
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /** Só agenda a gravação: o `flush` é da transação de quem chama. */
    public function salvar(ConfiguracaoDaAssinatura $configuracao): void
    {
        $this->getEntityManager()->persist($configuracao);
    }
}
