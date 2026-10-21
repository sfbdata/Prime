<?php

declare(strict_types=1);

namespace App\Termo\Repository;

use App\Termo\Entity\CienciaPolitica;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CienciaPolitica>
 */
class CienciaPoliticaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CienciaPolitica::class);
    }

    public function salvar(CienciaPolitica $ciencia, bool $flush = false): void
    {
        $this->getEntityManager()->persist($ciencia);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
