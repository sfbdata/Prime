<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\TrabalhoExterno\Entity\HistoricoVaga;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O histórico só é lido pela vaga (`Vaga::getHistorico`).
 *
 * @extends ServiceEntityRepository<HistoricoVaga>
 */
class HistoricoVagaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HistoricoVaga::class);
    }
}
