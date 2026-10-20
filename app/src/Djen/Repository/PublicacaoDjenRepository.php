<?php

declare(strict_types=1);

namespace App\Djen\Repository;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use App\Processo\DTO\HistoricoParaPrevisao;
use App\Processo\DTO\MovimentacaoParaPrevisao;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicacaoDjen>
 */
// Não-final: espelha ProcessoRepository e permite substituição por mock nos testes de UseCase.
class PublicacaoDjenRepository extends ServiceEntityRepository
{
    /** Caracteres do teor (HTML cru) que a lista da PASTA projeta para montar o `textoResumo`. */
    private const TEXTO_INICIO_LISTA = 2000;
    private const TEXTO_PREVISAO = 15000;

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
     * `$numerosOcultos` (dígitos): processos das pastas com cadeado ocultas para quem lista; as publicações
     * deles não entram (spec pasta-cadeado D3).
     *
     * @param array<string, string> $filtros  busca, tribunal, meio, vinculo (avulsa|vinculada), data_de, data_ate
     * @param list<string>          $numerosOcultos
     * @return PublicacaoDjenListaItem[]
     */
    public function listarItensDoTenant(Tenant $tenant, array $filtros, int $pagina = 1, int $porPagina = 25, array $numerosOcultos = []): array
    {
        $linhas = $this->buildQbFiltros($tenant, $filtros, $numerosOcultos)
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
     * @param list<string>          $numerosOcultos
     */
    public function countByFiltros(Tenant $tenant, array $filtros, array $numerosOcultos = []): int
    {
        return (int) $this->buildQbFiltros($tenant, $filtros, $numerosOcultos)
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
     * As publicações desta captação (pelos `djenId` novos) que estão VINCULADAS a um processo do
     * escritório, com o processo junto: a entrada do "Avisar quando chegar" do Push (spec
     * push-avisar-quando-chegar §3). Chamada depois da reconciliação, então pega também a que
     * nasceu avulsa e foi religada na mesma captação. Tenant explícito: roda no CLI.
     *
     * @param list<string> $djenIds
     *
     * @return list<PublicacaoDjen>
     */
    public function listarVinculadasPorDjenIdsDoTenant(Tenant $tenant, array $djenIds): array
    {
        if ($djenIds === []) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->addSelect('pr')
            ->join('p.processo', 'pr')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('pr.tenant = :tenant')
            ->andWhere('p.djenId IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', array_values(array_unique($djenIds)))
            ->orderBy('p.dataDisponibilizacao', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As publicações do escritório VINCULADAS a um processo dele e disponibilizadas no período (datas
     * inclusivas), com o processo junto: a entrada do resumo semanal ao cliente (spec
     * push-envio-automatico §3). Da mais antiga para a mais recente. Tenant explícito: roda no CLI.
     *
     * @return list<PublicacaoDjen>
     */
    public function listarVinculadasDoPeriodoDoTenant(Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('pr')
            ->join('p.processo', 'pr')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('pr.tenant = :tenant')
            ->andWhere('p.dataDisponibilizacao BETWEEN :inicio AND :fim')
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $inicio->setTime(0, 0), 'date_immutable')
            ->setParameter('fim', $fim->setTime(0, 0), 'date_immutable')
            ->orderBy('p.dataDisponibilizacao', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
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
    /** Existe ao menos uma publicação destes números neste escritório? (Sem carregar a lista.) */
    public function existePorNumerosDoTenant(Tenant $tenant, array $numeros): bool
    {
        $numeros = self::normalizarNumeros($numeros);
        if ($numeros === []) {
            return false;
        }

        return $this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.numeroProcesso IN (:numeros)')
            ->setParameter('tenant', $tenant)
            ->setParameter('numeros', $numeros)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

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
                'p.tipoDocumento AS tipoDocumento',
                'p.numeroProcessoComMascara AS numeroProcessoComMascara',
                'p.numeroProcesso AS numeroProcesso',
                'p.dataDisponibilizacao AS dataDisponibilizacao',
                'p.nomeOrgao AS nomeOrgao',
                'p.lida AS lida',
                'IDENTITY(p.processo) AS processoId',
                // Só o COMEÇO do teor, para o título do cartão (`textoResumo`, FPN-012): o
                // teor inteiro continua fora da lista. Folga sobre os 220 caracteres do
                // resumo porque o teor vem em HTML e as tags também ocupam espaço.
                'SUBSTRING(p.texto, 1, ' . self::TEXTO_INICIO_LISTA . ') AS textoInicio',
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
     * A movimentação mais recente da pasta, com o teor, para a regra da "Situação atual" do Push
     * (spec push-painel-monitoramento §3): a primeira da MESMA lista da aba
     * (`listarItensPorNumerosDoTenant`: mesmos números e ordem), sem as que não têm data (sem data
     * não há contagem de prazo nem ordem). O teor vem cortado em `TEXTO_PREVISAO` caracteres de
     * HTML: a previsão só lê os 5.000 primeiros de texto, e as tags ocupam espaço.
     *
     * @param string[] $numeros números CNJ dos processos da pasta
     */
    public function ultimaParaPrevisaoDoTenant(Tenant $tenant, array $numeros): ?MovimentacaoParaPrevisao
    {
        $numeros = self::normalizarNumeros($numeros);
        if ($numeros === []) {
            return null;
        }

        $linha = $this->createQueryBuilder('p')
            ->select('p.dataDisponibilizacao AS data', 'p.tipoComunicacao AS tipo', 'SUBSTRING(p.texto, 1, ' . self::TEXTO_PREVISAO . ') AS teor')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.numeroProcesso IN (:numeros)')
            ->andWhere('p.dataDisponibilizacao IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('numeros', $numeros)
            ->orderBy('p.dataDisponibilizacao', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult()[0] ?? null;

        if ($linha === null || !$linha['data'] instanceof \DateTimeInterface) {
            return null;
        }

        return MovimentacaoParaPrevisao::doTeorHtml(\DateTimeImmutable::createFromInterface($linha['data']), $linha['tipo'], $linha['teor']);
    }

    /**
     * O histórico que a "Situação atual" lê, respondido no banco sem trazer teor nenhum: quantas
     * movimentações há na lista (até o teto da aba) e se alguma, além da mais recente, fala em
     * contestação (`/contesta/` sobre tipo + teor, dc L.5098). ILIKE dispensa tirar acento e caixa:
     * "contesta" não tem acento, e "CONTESTAÇÃO"/"contestação" casam igual.
     *
     * @param string[] $numeros números CNJ dos processos da pasta
     */
    public function historicoParaPrevisaoDoTenant(Tenant $tenant, array $numeros, int $limite = 100): HistoricoParaPrevisao
    {
        $numeros = self::normalizarNumeros($numeros);
        if ($numeros === []) {
            return new HistoricoParaPrevisao(0, false);
        }

        $linha = $this->getEntityManager()->getConnection()->fetchAssociative(
            "WITH lista AS (
                SELECT ROW_NUMBER() OVER (ORDER BY data_disponibilizacao DESC, id DESC) AS ordem,
                       COALESCE(tipo_comunicacao, '') || ' ' || SUBSTRING(COALESCE(texto, ''), 1, " . self::TEXTO_PREVISAO . ") AS n
                  FROM publicacao_djen
                 WHERE tenant_id = :tenant AND numero_processo IN (:numeros) AND data_disponibilizacao IS NOT NULL
                 ORDER BY data_disponibilizacao DESC, id DESC
                 LIMIT :limite
            )
            SELECT COUNT(*) AS total, COALESCE(BOOL_OR(ordem > 1 AND n ILIKE '%contesta%'), false) AS contestacao FROM lista",
            ['tenant' => $tenant->getId(), 'numeros' => array_map('strval', $numeros), 'limite' => $limite],
            ['numeros' => ArrayParameterType::STRING, 'limite' => ParameterType::INTEGER],
        );

        // O driver devolve o booleano do PostgreSQL como bool; 't' cobre um driver que devolva texto.
        $contestacao = $linha['contestacao'] ?? false;

        return new HistoricoParaPrevisao((int) ($linha['total'] ?? 0), $contestacao === true || $contestacao === 't');
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
     * @param list<string>          $numerosOcultos
     */
    private function buildQbFiltros(Tenant $tenant, array $filtros, array $numerosOcultos = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        if ($numerosOcultos !== []) {
            $qb->andWhere('p.numeroProcesso NOT IN (:numerosOcultos)')
               ->setParameter('numerosOcultos', $numerosOcultos);
        }

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

    /**
     * A data de disponibilização mais recente de cada número de processo pedido (o Push da pasta, no
     * relatório de pastas paradas). Os números são normalizados como na lista da pasta (só dígitos);
     * uma consulta para o lote, com o tenant explícito.
     *
     * @param string[] $numeros números CNJ com ou sem máscara
     *
     * @return array<string, \DateTimeImmutable> número em dígitos => data da última publicação
     */
    public function ultimasDisponibilizacoesPorNumero(Tenant $tenant, array $numeros): array
    {
        $numeros = self::normalizarNumeros($numeros);
        if ($numeros === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('p')
            ->select('p.numeroProcesso AS numero', 'MAX(p.dataDisponibilizacao) AS ultima')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.numeroProcesso IN (:numeros)')
            ->andWhere('p.dataDisponibilizacao IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('numeros', $numeros)
            ->groupBy('p.numeroProcesso')
            ->getQuery()
            ->getArrayResult();

        $porNumero = [];
        foreach ($linhas as $l) {
            $porNumero[(string) $l['numero']] = new \DateTimeImmutable((string) $l['ultima']);
        }

        return $porNumero;
    }
}
