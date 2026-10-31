<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\RotuloDeEtapa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RotuloDeEtapa> */
class RotuloDeEtapaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RotuloDeEtapa::class);
    }

    public function daOrigem(Pasta $pasta, string $via, int $origem, Tenant $tenant): ?RotuloDeEtapa
    {
        return $this->findOneBy(['pasta' => $pasta, 'via' => $via, 'origem' => $origem, 'tenant' => $tenant]);
    }

    /** @return array<int, string> índice da fase de origem => rótulo */
    public function daPasta(Pasta $pasta, string $via, Tenant $tenant): array
    {
        $mapa = [];
        foreach ($this->findBy(['pasta' => $pasta, 'via' => $via, 'tenant' => $tenant]) as $rotulo) {
            $mapa[$rotulo->getOrigem()] = $rotulo->getTexto();
        }

        return $mapa;
    }
}
