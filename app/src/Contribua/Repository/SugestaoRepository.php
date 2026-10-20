<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\Sugestao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sugestao>
 */
// Não-final: os testes de UseCase mockam o repositório.
class SugestaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sugestao::class);
    }

    public function salvar(Sugestao $sugestao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($sugestao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * As do escritório, mais recentes primeiro; `null` = todos (só pela `VisaoDoMaster`).
     *
     * @return list<Sugestao>
     */
    public function doEscritorio(?int $tenantId): array
    {
        $qb = $this->createQueryBuilder('s')->orderBy('s.criadaEm', 'DESC')->addOrderBy('s.id', 'DESC');
        if ($tenantId !== null) {
            $qb->andWhere('IDENTITY(s.tenant) = :tenant')->setParameter('tenant', $tenantId);
        }

        return $qb->getQuery()->getResult();
    }
}
