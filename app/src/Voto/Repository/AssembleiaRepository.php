<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
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

    /**
     * Para as estatísticas do Master: as assembleias PUBLICADAS e que não são simulação, de todos os escritórios
     * (arquivadas entram), da categoria pedida ou de todas. Sem filtro de escritório: quem chama exigiu o Master e
     * suspendeu o TenantFilter durante toda a leitura.
     *
     * @return list<Assembleia>
     */
    public function listarParaEstatisticasDoMaster(?CenarioDaAssembleia $categoria): array
    {
        $consulta = $this->createQueryBuilder('a')
            ->andWhere('a.estado = :publicada')->setParameter('publicada', EstadoDaAssembleia::Publicada)
            ->andWhere('a.simulacao = false')
            ->orderBy('a.criadaEm', 'DESC')->addOrderBy('a.id', 'DESC');
        if (null !== $categoria) {
            $consulta->andWhere('a.cenario = :categoria')->setParameter('categoria', $categoria);
        }
        /** @var list<Assembleia> $assembleias */
        $assembleias = $consulta->getQuery()->getResult();

        return $assembleias;
    }

    /** Guarda de posse: a assembleia pelo id, só se for do escritório (senão null, e quem chama responde 404). */
    public function buscarDoEscritorio(int $id, Tenant $tenant): ?Assembleia
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * As assembleias do escritório com estes ids, numa consulta só (id de outro escritório não vem).
     *
     * @param list<int> $ids
     *
     * @return list<Assembleia>
     */
    public function doEscritorioPorIds(Tenant $tenant, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<Assembleia> $assembleias */
        $assembleias = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.id IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $assembleias;
    }

    /** O código é público e globalmente único; suspende e restaura o filtro da sessão de outro escritório. */
    public function buscarPublicadaPeloCodigo(string $codigo): ?Assembleia
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $codigo)) {
            return null;
        }
        $filtros = $this->getEntityManager()->getFilters();
        $ligado = $filtros->isEnabled('tenant');
        if ($ligado) {
            $filtros->suspend('tenant');
        }
        try {
            return $this->findOneBy(['codigoPublico' => $codigo, 'estado' => EstadoDaAssembleia::Publicada]);
        } finally {
            if ($ligado) {
                $filtros->restore('tenant');
            }
        }
    }
}
