<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\CampanhaAlteracao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampanhaAlteracao>
 */
// Não-final: os testes de UseCase mockam o repositório.
class CampanhaAlteracaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampanhaAlteracao::class);
    }

    public function salvar(CampanhaAlteracao $alteracao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($alteracao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
