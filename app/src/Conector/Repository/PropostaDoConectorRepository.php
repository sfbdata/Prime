<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\PropostaDoConector;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Propostas que esperam confirmação. A busca leva o escritório do token explícito, sem depender do `TenantFilter`:
 * proposta de outro escritório não existe para quem pergunta.
 *
 * @extends ServiceEntityRepository<PropostaDoConector>
 */
class PropostaDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropostaDoConector::class);
    }

    public function salvar(PropostaDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarPorIdentificadorHash(string $identificadorHash, Tenant $tenant): ?PropostaDoConector
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.identificadorHash = :hash')
            ->setParameter('tenant', $tenant)
            ->setParameter('hash', $identificadorHash)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
