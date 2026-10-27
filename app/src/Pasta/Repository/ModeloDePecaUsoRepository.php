<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\ModeloDePecaUso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModeloDePecaUso>
 */
class ModeloDePecaUsoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeloDePecaUso::class);
    }

    public function salvar(ModeloDePecaUso $uso, bool $flush = false): void
    {
        $this->getEntityManager()->persist($uso);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
