<?php

namespace App\Processo\Repository;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Processo>
 */
class ProcessoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Processo::class);
    }

    public function save(Processo $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Processo $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByNumeroProcesso(string $numeroProcesso): ?Processo
    {
        return $this->findOneBy(['numeroProcesso' => $numeroProcesso]);
    }

    /**
     * Busca tenant-safe por id — para resolver um processo existente sem depender do
     * filtro SQL do Doctrine, que NÃO se aplica a find() por PK (risco cross-tenant).
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Processo
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Busca por número escopada explicitamente ao tenant (não depende do filtro de sessão).
     */
    public function findByNumeroProcessoDoTenant(string $numeroProcesso, Tenant $tenant): ?Processo
    {
        return $this->findOneBy(['numeroProcesso' => $numeroProcesso, 'tenant' => $tenant]);
    }

    /**
     * Busca processos do escritório por termo livre (número, classe, assunto ou tribunal),
     * para o autocomplete de "vincular processo existente". Filtra por tenant explicitamente
     * e permite excluir processos já vinculados.
     *
     * @param int[] $idsExcluir
     * @return Processo[]
     */
    public function buscarPorTermoDoTenant(string $termo, Tenant $tenant, array $idsExcluir = [], int $limite = 20): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $termo = trim($termo);
        if ($termo !== '') {
            $qb->andWhere(
                'UNACCENT(LOWER(p.numeroProcesso)) LIKE UNACCENT(LOWER(:termo))'
                . ' OR UNACCENT(LOWER(p.classeProcessual)) LIKE UNACCENT(LOWER(:termo))'
                . ' OR UNACCENT(LOWER(p.assuntoProcessual)) LIKE UNACCENT(LOWER(:termo))'
                . ' OR UNACCENT(LOWER(p.siglaTribunal)) LIKE UNACCENT(LOWER(:termo))'
            )->setParameter('termo', '%' . $termo . '%');
        }

        if ($idsExcluir !== []) {
            $qb->andWhere('p.id NOT IN (:excluir)')
               ->setParameter('excluir', $idsExcluir);
        }

        return $qb->orderBy('p.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Processo[]
     */
    public function findByFilters(array $filters): array
    {
        $qb = $this->createQueryBuilder('p');

        if (!empty($filters['numero_processo'])) {
            $qb->andWhere('p.numeroProcesso LIKE :numero')
               ->setParameter('numero', '%' . $filters['numero_processo'] . '%');
        }

        if (!empty($filters['tribunal'])) {
            $qb->andWhere('p.siglaTribunal = :tribunal')
               ->setParameter('tribunal', $filters['tribunal']);
        }

        if (!empty($filters['classe'])) {
            $qb->andWhere('p.classeProcessual LIKE :classe')
               ->setParameter('classe', '%' . $filters['classe'] . '%');
        }

        if (!empty($filters['assunto'])) {
            $qb->andWhere('p.assuntoProcessual LIKE :assunto')
               ->setParameter('assunto', '%' . $filters['assunto'] . '%');
        }

        if (!empty($filters['situacao'])) {
            $qb->andWhere('p.situacaoProcesso = :situacao')
               ->setParameter('situacao', $filters['situacao']);
        }

        return $qb->orderBy('p.id', 'DESC')->getQuery()->getResult();
    }

    /**
     * Lista processos do escritório com busca livre + facetas + paginação, para a tela
     * de índice. Escopa por tenant EXPLICITAMENTE (defesa em profundidade) além do
     * TenantFilter global — o método continua seguro se chamado com o filtro desligado
     * (CLI, super-admin, teste).
     *
     * @param array<string, string> $filtros  busca, tribunal, situacao, data_de, data_ate
     * @return Processo[]
     */
    public function findByFiltrosPaginado(
        Tenant $tenant,
        array $filtros,
        int $pagina = 1,
        int $porPagina = 25,
        string $ordenar = '',
        string $direcao = 'desc'
    ): array {
        $qb = $this->buildQbFiltros($tenant, $filtros);
        $this->aplicarOrdenacaoProcesso($qb, $ordenar, $direcao);

        return $qb
            ->setFirstResult(($pagina - 1) * $porPagina)
            ->setMaxResults($porPagina)
            ->getQuery()
            ->getResult();
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
                . ' OR UNACCENT(LOWER(p.classeProcessual)) LIKE UNACCENT(LOWER(:busca))'
                . ' OR UNACCENT(LOWER(p.assuntoProcessual)) LIKE UNACCENT(LOWER(:busca))'
                . ' OR UNACCENT(LOWER(p.siglaTribunal)) LIKE UNACCENT(LOWER(:busca))'
            )->setParameter('busca', '%' . $busca . '%');
        }

        if (!empty($filtros['tribunal'])) {
            $qb->andWhere('p.siglaTribunal = :tribunal')
               ->setParameter('tribunal', $filtros['tribunal']);
        }

        if (!empty($filtros['situacao'])) {
            $qb->andWhere('p.situacaoProcesso = :situacao')
               ->setParameter('situacao', $filtros['situacao']);
        }

        $dataDe = $this->parseDataFiltro($filtros['data_de'] ?? '', false);
        if ($dataDe !== null) {
            $qb->andWhere('p.dataDistribuicao >= :dataDe')
               ->setParameter('dataDe', $dataDe);
        }

        $dataAte = $this->parseDataFiltro($filtros['data_ate'] ?? '', true);
        if ($dataAte !== null) {
            $qb->andWhere('p.dataDistribuicao <= :dataAte')
               ->setParameter('dataAte', $dataAte);
        }

        return $qb;
    }

    /**
     * Converte um valor 'Y-m-d' (input date) em DateTimeImmutable, ou null se vazio/inválido.
     * Na borda final ('data_ate') usa o fim do dia para o intervalo ser inclusivo.
     */
    private function parseDataFiltro(string $valor, bool $fimDoDia): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $fimDoDia ? $data->setTime(23, 59, 59) : $data;
    }

    private function aplicarOrdenacaoProcesso(QueryBuilder $qb, string $ordenar, string $direcao): void
    {
        $dir = strtoupper($direcao) === 'ASC' ? 'ASC' : 'DESC';

        $coluna = match ($ordenar) {
            'numero'   => 'p.numeroProcesso',
            'tribunal' => 'p.siglaTribunal',
            'classe'   => 'p.classeProcessual',
            'assunto'  => 'p.assuntoProcessual',
            'situacao' => 'p.situacaoProcesso',
            default    => null,
        };

        if ($coluna === null) {
            $qb->orderBy('p.id', 'DESC');

            return;
        }

        $qb->orderBy($coluna, $dir)->addOrderBy('p.id', 'DESC');
    }

    /**
     * Siglas de tribunal do escritório, para as opções da faceta. Escopa por tenant
     * EXPLICITAMENTE (defesa em profundidade) além do TenantFilter global.
     *
     * @return array<string, string>
     */
    public function findAllTribunais(Tenant $tenant): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.siglaTribunal')
            ->where('p.siglaTribunal IS NOT NULL')
            ->andWhere('p.siglaTribunal != :empty')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('empty', '')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.siglaTribunal', 'ASC')
            ->getQuery()
            ->getResult();

        $tribunais = [];
        foreach ($result as $row) {
            $tribunais[$row['siglaTribunal']] = $row['siglaTribunal'];
        }
        return $tribunais;
    }

    /**
     * @return array<string, string>
     */
    public function findAllClasses(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.classeProcessual')
            ->where('p.classeProcessual IS NOT NULL')
            ->andWhere('p.classeProcessual != :empty')
            ->setParameter('empty', '')
            ->orderBy('p.classeProcessual', 'ASC')
            ->getQuery()
            ->getResult();

        $classes = [];
        foreach ($result as $row) {
            $classes[$row['classeProcessual']] = $row['classeProcessual'];
        }
        return $classes;
    }

    /**
     * @return array<string, string>
     */
    public function findAllAssuntos(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.assuntoProcessual')
            ->where('p.assuntoProcessual IS NOT NULL')
            ->andWhere('p.assuntoProcessual != :empty')
            ->setParameter('empty', '')
            ->orderBy('p.assuntoProcessual', 'ASC')
            ->getQuery()
            ->getResult();

        $assuntos = [];
        foreach ($result as $row) {
            $assuntos[$row['assuntoProcessual']] = $row['assuntoProcessual'];
        }
        return $assuntos;
    }

    /**
     * @return array<string, string>
     */
    public function findAllNumerosProcesso(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.numeroProcesso')
            ->where('p.numeroProcesso IS NOT NULL')
            ->andWhere('p.numeroProcesso != :empty')
            ->setParameter('empty', '')
            ->orderBy('p.numeroProcesso', 'ASC')
            ->getQuery()
            ->getResult();

        $numeros = [];
        foreach ($result as $row) {
            $numeros[$row['numeroProcesso']] = $row['numeroProcesso'];
        }
        return $numeros;
    }
}
