<?php

declare(strict_types=1);

namespace App\Djen\Repository;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicacaoDjen>
 */
// Não-final: espelha ProcessoRepository e permite substituição por mock nos testes de UseCase.
class PublicacaoDjenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicacaoDjen::class);
    }

    public function salvar(PublicacaoDjen $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PublicacaoDjen $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Persiste as publicações acumuladas de uma sincronização em um único flush. */
    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?PublicacaoDjen
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Deduplicação em lote: dado um conjunto de djenId, retorna os que JÁ existem no escritório.
     * Uma query só (evita N consultas na sincronização). Escopa por tenant explicitamente.
     *
     * @param string[] $djenIds
     * @return string[] subconjunto de $djenIds já presentes (como string, tipo do bigint)
     */
    public function filtrarDjenIdsExistentesDoTenant(array $djenIds, Tenant $tenant): array
    {
        if ($djenIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('p.djenId')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.djenId IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $djenIds)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['djenId'], $rows);
    }

    /**
     * Lista PROJETADA das publicações do escritório (só as colunas exibidas na tela — NÃO hidrata
     * `texto`/`payloadDjen`). Escopa por tenant EXPLICITAMENTE (defesa em profundidade).
     *
     * @param array<string, string> $filtros  busca, tribunal, meio, vinculo (avulsa|vinculada), data_de, data_ate
     * @return PublicacaoDjenListaItem[]
     */
    public function listarItensDoTenant(Tenant $tenant, array $filtros, int $pagina = 1, int $porPagina = 25): array
    {
        $linhas = $this->buildQbFiltros($tenant, $filtros)
            ->select(
                'p.id AS id',
                'p.siglaTribunal AS siglaTribunal',
                'p.tipoComunicacao AS tipoComunicacao',
                'p.numeroProcessoComMascara AS numeroProcessoComMascara',
                'p.numeroProcesso AS numeroProcesso',
                'p.dataDisponibilizacao AS dataDisponibilizacao',
                'p.nomeOrgao AS nomeOrgao',
                'p.lida AS lida',
                'IDENTITY(p.processo) AS processoId',
            )
            ->orderBy('p.dataDisponibilizacao', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($pagina - 1) * $porPagina)
            ->setMaxResults($porPagina)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $linha): PublicacaoDjenListaItem => PublicacaoDjenListaItem::fromRow($linha),
            $linhas,
        );
    }

    /**
     * @param array<string, string> $filtros
     */
    public function countByFiltros(Tenant $tenant, array $filtros): int
    {
        return (int) $this->buildQbFiltros($tenant, $filtros)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Siglas de tribunal presentes nas publicações do escritório (opções da faceta).
     *
     * @return string[]
     */
    public function listarTribunaisDoTenant(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.siglaTribunal')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.siglaTribunal != :vazio')
            ->setParameter('tenant', $tenant)
            ->setParameter('vazio', '')
            ->orderBy('p.siglaTribunal', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => (string) $row['siglaTribunal'], $rows);
    }

    /**
     * @param array<string, string> $filtros
     */
    private function buildQbFiltros(Tenant $tenant, array $filtros): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $qb->andWhere(
                'UNACCENT(LOWER(p.numeroProcesso)) LIKE UNACCENT(LOWER(:busca))'
                . ' OR UNACCENT(LOWER(p.numeroProcessoComMascara)) LIKE UNACCENT(LOWER(:busca))'
                . ' OR UNACCENT(LOWER(p.siglaTribunal)) LIKE UNACCENT(LOWER(:busca))'
                . ' OR UNACCENT(LOWER(p.tipoComunicacao)) LIKE UNACCENT(LOWER(:busca))'
            )->setParameter('busca', '%' . $busca . '%');
        }

        if (!empty($filtros['tribunal'])) {
            $qb->andWhere('p.siglaTribunal = :tribunal')
               ->setParameter('tribunal', $filtros['tribunal']);
        }

        if (!empty($filtros['meio'])) {
            $qb->andWhere('p.meio = :meio')
               ->setParameter('meio', $filtros['meio']);
        }

        $vinculo = (string) ($filtros['vinculo'] ?? '');
        if ($vinculo === 'vinculada') {
            $qb->andWhere('p.processo IS NOT NULL');
        } elseif ($vinculo === 'avulsa') {
            $qb->andWhere('p.processo IS NULL');
        }

        $dataDe = $this->parseDataFiltro($filtros['data_de'] ?? '');
        if ($dataDe !== null) {
            $qb->andWhere('p.dataDisponibilizacao >= :dataDe')
               ->setParameter('dataDe', $dataDe);
        }

        $dataAte = $this->parseDataFiltro($filtros['data_ate'] ?? '');
        if ($dataAte !== null) {
            $qb->andWhere('p.dataDisponibilizacao <= :dataAte')
               ->setParameter('dataAte', $dataAte);
        }

        return $qb;
    }

    private function parseDataFiltro(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $data;
    }
}
