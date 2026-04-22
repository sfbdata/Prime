<?php

namespace App\Repository\Ponto;

use App\Entity\Ponto\JornadaTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class JornadaTenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JornadaTenant::class);
    }
}
