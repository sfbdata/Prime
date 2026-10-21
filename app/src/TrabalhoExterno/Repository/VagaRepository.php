<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    public function daCentralPorId(int $id): ?Vaga
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * O que um externo enxerga (desenho l.990): as abertas e as que ele divulgou, pelo escritório
     * dele ou, sem escritório (Convidado), por ele mesmo.
     *
     * @return list<Vaga>
     */
    public function visiveisAoExterno(?Tenant $tenant, User $usuario): array
    {
        return $this->doExterno($tenant, $usuario)->orderBy('v.id', 'DESC')->getQuery()->getResult();
    }

    public function visivelAoExternoPorId(int $id, ?Tenant $tenant, User $usuario): ?Vaga
    {
        return $this->doExterno($tenant, $usuario)->andWhere('v.id = :id')->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    private function doExterno(?Tenant $tenant, User $usuario): QueryBuilder
    {
        $qb = $this->createQueryBuilder('v')
            ->setParameter('aberta', SituacaoVaga::Aberta)
            ->setParameter('usuario', $usuario);
        $dele = $qb->expr()->andX('v.daCentral = false', 'v.escritorio IS NULL', 'v.criadaPor = :usuario');
        if ($tenant === null) {
            return $qb->andWhere($qb->expr()->orX('v.situacao = :aberta', $dele));
        }

        return $qb
            ->andWhere($qb->expr()->orX('v.situacao = :aberta', 'v.escritorio = :tenant', $dele))
            ->setParameter('tenant', $tenant);
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
