<?php

namespace App\Repository\Ponto;

use App\Entity\Ponto\BlocoEscalaUsuario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlocoEscalaUsuarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlocoEscalaUsuario::class);
    }
}
