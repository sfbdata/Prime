<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Dashboard\Entity\AcompanhamentoDaPessoa;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Acompanhamento de pessoa do painel. Toda consulta recebe o escritório e filtra por ele (além do TenantFilter).
 *
 * @extends ServiceEntityRepository<AcompanhamentoDaPessoa>
 */
// Não-final: os testes de UseCase mockam o repositório do domínio.
class AcompanhamentoDaPessoaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcompanhamentoDaPessoa::class);
    }

    public function salvar(AcompanhamentoDaPessoa $acompanhamento): void
    {
        $this->getEntityManager()->persist($acompanhamento);
    }

    /** O acompanhamento ABERTO da pessoa neste escritório, ou nulo. */
    public function buscarAbertoDaPessoa(Tenant $tenant, int $pessoaId): ?AcompanhamentoDaPessoa
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('IDENTITY(a.pessoa) = :pessoa')
            ->andWhere('a.encerradoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoaId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
