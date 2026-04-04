<?php

namespace App\Repository\Ponto;

use App\Entity\Ponto\EscalaTrabalho;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EscalaTrabalhoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscalaTrabalho::class);
    }
}
