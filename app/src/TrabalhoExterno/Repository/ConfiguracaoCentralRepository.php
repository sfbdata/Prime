<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ConfiguracaoCentral> */
class ConfiguracaoCentralRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoCentral::class);
    }

    /**
     * A configuração da Central. Na primeira leitura ainda não existe linha: devolve os padrões do
     * desenho SEM gravar (leitura não escreve); quem altera persiste.
     */
    public function atual(): ConfiguracaoCentral
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? new ConfiguracaoCentral();
    }

    public function salvar(ConfiguracaoCentral $configuracao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($configuracao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
