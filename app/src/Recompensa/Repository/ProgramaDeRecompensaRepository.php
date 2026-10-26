<?php

declare(strict_types=1);

namespace App\Recompensa\Repository;

use App\Recompensa\Entity\ProgramaDeRecompensa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A linha única de `programa_de_recompensa`. Sem filtro de tenant de propósito: é configuração GLOBAL da plataforma
 * (ver {@see ProgramaDeRecompensa}).
 *
 * @extends ServiceEntityRepository<ProgramaDeRecompensa>
 */
class ProgramaDeRecompensaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramaDeRecompensa::class);
    }

    public function buscarUnico(): ?ProgramaDeRecompensa
    {
        return $this->find(ProgramaDeRecompensa::ID_UNICO);
    }

    public function salvar(ProgramaDeRecompensa $programa, bool $flush = false): void
    {
        $this->getEntityManager()->persist($programa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
