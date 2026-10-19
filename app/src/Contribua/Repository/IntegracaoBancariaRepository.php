<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\IntegracaoBancaria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<IntegracaoBancaria> */
class IntegracaoBancariaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntegracaoBancaria::class);
    }

    /** A linha única (criada vazia, desligada, na primeira consulta). */
    public function atual(): IntegracaoBancaria
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? new IntegracaoBancaria();
    }

    public function salvar(IntegracaoBancaria $integracao): void
    {
        $this->getEntityManager()->persist($integracao);
        $this->getEntityManager()->flush();
    }
}
