<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Ponto\Entity\BlocoJornadaColaborador;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class BlocoJornadaColaboradorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlocoJornadaColaborador::class);
    }
}
