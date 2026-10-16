<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\LeituraDemanda;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LeituraDemanda> */
class LeituraDemandaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeituraDemanda::class);
    }

    public function daDemanda(DemandaExterna $demanda, User $usuario): ?LeituraDemanda
    {
        return $this->findOneBy(['demanda' => $demanda, 'usuario' => $usuario]);
    }

    public function salvar(LeituraDemanda $leitura, bool $flush = false): void
    {
        $this->getEntityManager()->persist($leitura);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
