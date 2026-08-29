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
     * Publicações do escritório ainda sem vínculo com Processo — a entrada da reconciliação.
     * Devolve ENTIDADES (e não projeção) porque quem chama vai gravar a FK nelas.
     *
     * @return PublicacaoDjen[]
     */
    public function listarAvulsasDoTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.processo IS NULL')
            ->andWhere("p.numeroProcesso != ''")
            ->setParameter('tenant', $tenant)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lista PROJETADA das publicações do escritório cujo número CNJ está entre os informados —
     * é o que alimenta a aba Push Processual da pasta, com os números dos processos dela.
     *
     * Casa por NÚMERO, e não pela FK `processo`, de propósito: a FK só é gravada durante a
     * sincronização, então publicação captada ANTES de o processo entrar no cadastro ficaria
     * invisível para a pasta. (Em produção eram 8 das 59 publicações que pertencem a alguma
     * pasta — em todas, o processo foi criado depois da captura.) O `ReconciliarPublicacoes`
     * conserta a FK dessas; esta consulta não depende dele para dizer a verdade.
     *
     * @param string[] $numeros  números CNJ com ou sem máscara (normalizados aqui)
     * @return PublicacaoDjenListaItem[]
     */
    public function listarItensPorNumerosDoTenant(Tenant $tenant, array $numeros, int $limite = 100): array
    {
        $numeros = self::normalizarNumeros($numeros);
        if ($numeros === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('p')
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
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.numeroProcesso IN (:numeros)')
            ->setParameter('tenant', $tenant)
            ->setParameter('numeros', $numeros)
            ->orderBy('p.dataDisponibilizacao', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $linha): PublicacaoDjenListaItem => PublicacaoDjenListaItem::fromRow($linha),
            $linhas,
        );
    }

    /**
     * Busca uma publicação por id exigindo que ela seja de UM dos números informados — o guarda
     * de IDOR da leitura pelo lado da pasta: id de publicação do escritório que não pertença a
     * processo daquela pasta não pode virar leitura. A restrição fica na consulta, não numa
     * comparação depois, para não haver caminho que a esqueça.
     *
     * @param string[] $numeros números CNJ dos processos da pasta
     */
    public function findOneByIdENumerosDoTenant(int $id, Tenant $tenant, array $numeros): ?PublicacaoDjen
    {
        $numeros = self::normalizarNumeros($numeros);
        if ($numeros === []) {
            return null;
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.numeroProcesso IN (:numeros)')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->setParameter('numeros', $numeros)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Números CNJ comparáveis: a publicação grava só dígitos (DjenPublicacaoMapper::soDigitos), e
     * o processo pode chegar mascarado. Sem normalizar dos dois lados o casamento falha calado —
     * lista vazia com cara de "não há publicação".
     *
     * @param string[] $numeros
     * @return string[]
     */
    private static function normalizarNumeros(array $numeros): array
    {
        $digitos = [];
        foreach ($numeros as $numero) {
            $so = preg_replace('/\D/', '', (string) $numero) ?? '';
            if ($so !== '') {
                $digitos[$so] = true;
            }
        }

        return array_keys($digitos);
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
