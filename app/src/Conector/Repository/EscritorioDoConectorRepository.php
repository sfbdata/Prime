<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\EscritorioDoConector;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Liberação e aceite do conector por escritório: uma linha por escritório, sempre buscada pelo escritório.
 *
 * @extends ServiceEntityRepository<EscritorioDoConector>
 */
class EscritorioDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscritorioDoConector::class);
    }

    public function salvar(EscritorioDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarDoEscritorio(Tenant $tenant): ?EscritorioDoConector
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }
}
