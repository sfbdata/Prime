<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\Campanha;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Campanha>
 */
// Não-final: os testes de UseCase mockam o repositório.
class CampanhaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campanha::class);
    }

    /** A campanha vigente: a de maior id (só existe uma por enquanto). */
    public function vigente(): ?Campanha
    {
        return $this->createQueryBuilder('c')->orderBy('c.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function salvar(Campanha $campanha, bool $flush = false): void
    {
        $this->getEntityManager()->persist($campanha);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
