<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\ConfiguracaoDoEspaco;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfiguracaoDoEspaco>
 */
// Não-final: os testes de UseCase mockam o repositório.
class ConfiguracaoDoEspacoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDoEspaco::class);
    }

    public function salvar(ConfiguracaoDoEspaco $configuracao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($configuracao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * A linha única. Se ainda não existir (banco sem a carga da migration), devolve os padrões em
     * memória, sem persistir: ler configuração nunca pode gravar.
     */
    public function atual(): ConfiguracaoDoEspaco
    {
        return $this->find(ConfiguracaoDoEspaco::ID_UNICO) ?? new ConfiguracaoDoEspaco();
    }
}
