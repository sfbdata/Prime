<?php

declare(strict_types=1);

namespace App\Indicacao\Repository;

use App\Indicacao\Entity\IndicacaoPausa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As pessoas com os convites pausados pelo Master.
 *
 * @extends ServiceEntityRepository<IndicacaoPausa>
 */
class IndicacaoPausaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicacaoPausa::class);
    }

    public function doUsuario(int $userId): ?IndicacaoPausa
    {
        return $this->findOneBy(['user' => $userId]);
    }

    public function estaPausado(int $userId): bool
    {
        return $this->doUsuario($userId) !== null;
    }

    public function salvar(IndicacaoPausa $pausa, bool $flush = false): void
    {
        $this->getEntityManager()->persist($pausa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(IndicacaoPausa $pausa, bool $flush = false): void
    {
        $this->getEntityManager()->remove($pausa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
