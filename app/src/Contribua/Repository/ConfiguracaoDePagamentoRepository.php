<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\ConfiguracaoDePagamento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ConfiguracaoDePagamento> */
class ConfiguracaoDePagamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDePagamento::class);
    }

    public function salvar(ConfiguracaoDePagamento $configuracao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($configuracao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** A linha única; sem a carga da migration, os padrões em memória (ler nunca grava). */
    public function atual(): ConfiguracaoDePagamento
    {
        return $this->find(ConfiguracaoDePagamento::ID_UNICO) ?? new ConfiguracaoDePagamento();
    }
}
