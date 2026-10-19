<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Único caminho até as vagas, como o `DemandaExternaRepository`: a entidade não é `TenantAware`,
 * então toda consulta é escopada aqui. A Central vê todas; o escritório vê as abertas e as dele
 * (spec VS 18.10.26 §4.1, convenção P2). Não há `find` solto.
 *
 * @extends ServiceEntityRepository<Vaga>
 */
class VagaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vaga::class);
    }

    public function salvar(Vaga $vaga, bool $flush = false): void
    {
        $this->getEntityManager()->persist($vaga);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<Vaga> */
    public function daCentral(): array
    {
        return $this->createQueryBuilder('v')->orderBy('v.id', 'DESC')->getQuery()->getResult();
    }

    /** @return list<Vaga> */
    public function visiveisAoEscritorio(Tenant $tenant): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.situacao = :aberta OR v.escritorio = :tenant')
            ->setParameter('aberta', SituacaoVaga::Aberta)
            ->setParameter('tenant', $tenant)
            ->orderBy('v.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function daCentralPorId(int $id): ?Vaga
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function visivelAoEscritorioPorId(int $id, Tenant $tenant): ?Vaga
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.id = :id')
            ->andWhere('v.situacao = :aberta OR v.escritorio = :tenant')
            ->setParameter('id', $id)
            ->setParameter('aberta', SituacaoVaga::Aberta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As vagas de um link público, só as ABERTAS (as fechadas somem da página, como no desenho).
     * Quem chama ainda confere o vencimento ({@see Vaga::situacaoEm()}).
     *
     * @param list<int> $ids
     *
     * @return list<Vaga>
     */
    public function abertasEntre(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('v')
            ->andWhere('v.id IN (:ids)')
            ->andWhere('v.situacao = :aberta')
            ->setParameter('ids', $ids)
            ->setParameter('aberta', SituacaoVaga::Aberta)
            ->getQuery()
            ->getResult();
    }
}
