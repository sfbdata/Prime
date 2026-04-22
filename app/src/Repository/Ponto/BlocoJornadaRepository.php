<?php

namespace App\Repository\Ponto;

use App\Entity\Ponto\BlocoJornada;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlocoJornadaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlocoJornada::class);
    }
}
