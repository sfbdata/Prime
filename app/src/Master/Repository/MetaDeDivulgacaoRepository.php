<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\MetaDeDivulgacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A linha única de `meta_de_divulgacao`. Sem filtro de tenant de propósito: é configuração GLOBAL da plataforma
 * (ver {@see MetaDeDivulgacao}). Só o painel do Master lê, então não há cache por requisição.
 *
 * @extends ServiceEntityRepository<MetaDeDivulgacao>
 */
class MetaDeDivulgacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetaDeDivulgacao::class);
    }

    public function buscarUnica(): ?MetaDeDivulgacao
    {
        return $this->find(MetaDeDivulgacao::ID_UNICO);
    }

    public function salvar(MetaDeDivulgacao $meta, bool $flush = false): void
    {
        $this->getEntityManager()->persist($meta);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
