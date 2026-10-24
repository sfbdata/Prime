<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A linha única da plataforma (id 1, criada pela migration). Global, sem tenant: não há dado de escritório nela.
 *
 * @extends ServiceEntityRepository<ConfiguracaoDaPlataformaDeIa>
 */
class ConfiguracaoDaPlataformaDeIaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDaPlataformaDeIa::class);
    }

    /**
     * A configuração vigente. Sem a linha (banco montado sem a migration), os padrões: chave geral desligada e o Haiku
     * 5.5, numa instância NÃO persistida (a leitura nunca grava). O `find` usa o identity map: uma consulta por
     * requisição.
     */
    public function obter(): ConfiguracaoDaPlataformaDeIa
    {
        return $this->find(ConfiguracaoDaPlataformaDeIa::ID_UNICO) ?? new ConfiguracaoDaPlataformaDeIa();
    }

    /** A linha gravada, ou nulo sem ela (o modelo do ambiente só vale como reserva quando ela falta). */
    public function gravada(): ?ConfiguracaoDaPlataformaDeIa
    {
        return $this->find(ConfiguracaoDaPlataformaDeIa::ID_UNICO);
    }

    /** Para quem vai alterar: a linha 1, criada (e marcada para persistir) se faltar. */
    public function obterParaAlterar(): ConfiguracaoDaPlataformaDeIa
    {
        $configuracao = $this->find(ConfiguracaoDaPlataformaDeIa::ID_UNICO);
        if ($configuracao === null) {
            $configuracao = new ConfiguracaoDaPlataformaDeIa();
            $this->getEntityManager()->persist($configuracao);
        }

        return $configuracao;
    }

    public function salvar(ConfiguracaoDaPlataformaDeIa $configuracao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($configuracao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
