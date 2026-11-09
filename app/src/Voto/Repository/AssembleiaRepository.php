<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assembleia>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class AssembleiaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assembleia::class);
    }

    public function salvar(Assembleia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Assembleia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * As assembleias do escritório, mais recentes primeiro.
     *
     * @return list<Assembleia>
     */
    public function listarDoEscritorio(Tenant $tenant, int $limite = 100): array
    {
        /** @var list<Assembleia> $assembleias */
        $assembleias = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.criadaEm', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return $assembleias;
    }

    /** Guarda de posse: a assembleia pelo id, só se for do escritório (senão null, e quem chama responde 404). */
    public function buscarDoEscritorio(int $id, Tenant $tenant): ?Assembleia
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }
}
