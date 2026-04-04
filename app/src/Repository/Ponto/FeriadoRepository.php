<?php

namespace App\Repository\Ponto;

use App\Entity\Ponto\Feriado;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FeriadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feriado::class);
    }

    /** @return Feriado[] */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant]);
    }
}
