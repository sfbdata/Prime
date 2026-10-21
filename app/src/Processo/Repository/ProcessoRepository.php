<?php

namespace App\Processo\Repository;

use App\Entity\Audit\AuditLog;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Twig\NumeroProcessoExtension;
use Doctrine\DBAL\ArrayParameterType;
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
     * Números dos processos que o Push Processual acompanha por número: todos os cadastrados no
     * escritório que ainda não deram baixa (processo baixado não recebe publicação nova) e cujo
     * monitoramento não foi pausado na aba Push da pasta (spec push-painel-monitoramento §1), do mais
     * recente ao mais antigo: no botão, que roda dentro do limite do DJEN, o recém-cadastrado vem
     * primeiro.
     *
     * Escopado pelo tenant explícito: roda também no comando de captação, onde o TenantFilter
     * está desligado.
     *
     * @return list<string>
     */
    public function listarNumerosAcompanhadosDoTenant(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('p')
            ->select('p.numeroProcesso')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.dataBaixa IS NULL')
            ->andWhere('p.monitoramentoPausadoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('strval', $linhas));
    }

    /**
     * Quantos processos o Push Processual acompanha por número (mesmo critério de
     * `listarNumerosAcompanhadosDoTenant`). Alimenta o botão da tela do módulo.
     */
    public function contarAcompanhadosDoTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.dataBaixa IS NULL')
            ->andWhere('p.monitoramentoPausadoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Grava o processo e a linha de auditoria da mesma ação num flush só (P5b: dados manuais do
     * cartão; o `Processo` não é Auditavel, senão cada sincronização do Datajud viraria log).
     */
    public function salvarComAuditoria(Processo $processo, AuditLog $registro): void
    {
        $em = $this->getEntityManager();
        $em->persist($processo);
        $em->persist($registro);
        $em->flush();
    }

    /**
     * Em quantas pastas vivas (fora da lápide) do escritório este processo está vinculado (P5b: o aviso "Valem para todas
     * as pastas com este processo"). Tenant explícito: não depende do filtro de sessão.
     */
    public function contarPastasDoProcesso(Processo $processo, Tenant $tenant): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT pa.id)')
            ->from(PastaProcesso::class, 'pp')
            ->join('pp.pasta', 'pa')
            ->andWhere('pp.processo = :processo')
            ->andWhere('pa.tenant = :tenant')
            ->andWhere('pa.excluidaEm IS NULL')
            ->setParameter('processo', $processo)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Grava a última verificação no DJEN dos processos do escritório com estes números (spec
     * push-painel-monitoramento §1). Os números chegam só com dígitos (é o que a captação consulta)
     * e o cadastro pode guardá-los com a máscara CNJ: a comparação é com as DUAS formas, por `IN`,
     * para usar o índice único (tenant_id, numero_processo) em vez de varrer a tabela.
     *
     * UPDATE direto, e não pela entidade: roda depois da captação (no comando, centenas de números)
     * e não deve disparar o `PreUpdate` do processo, que mudaria o `modificadoEm` sem ninguém ter
     * mexido no cadastro. Escopado pelo tenant explícito (o comando roda sem o TenantFilter).
     *
     * @param list<string> $numerosSoDigitos
     */
    public function registrarVerificacaoDosNumeros(Tenant $tenant, array $numerosSoDigitos, \DateTimeImmutable $em): void
    {
        if ($numerosSoDigitos === []) {
            return;
        }

        $mascara = new NumeroProcessoExtension();
        $formas = [];
        foreach ($numerosSoDigitos as $numero) {
            $formas[$numero] = true;
            $formas[$mascara->numeroCnj($numero)] = true;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE processo SET ultima_verificacao_em = :em WHERE tenant_id = :tenant AND numero_processo IN (:numeros)',
            ['em' => $em->format('Y-m-d H:i:s'), 'tenant' => $tenant->getId(), 'numeros' => array_map('strval', array_keys($formas))],
            ['numeros' => ArrayParameterType::STRING],
        );
    }

    /**
     * Busca por número escopada explicitamente ao tenant (não depende do filtro de sessão).
     */
    public function findByNumeroProcessoDoTenant(string $numeroProcesso, Tenant $tenant): ?Processo
    {
        return $this->findOneBy(['numeroProcesso' => $numeroProcesso, 'tenant' => $tenant]);
    }

    /**
     * Processos do escritório cujos números estão na lista, indexados PELO número — uma consulta
     * no lugar de uma por número. Usado pela reconciliação das publicações do Push Processual.
     *
     * @param string[] $numeros
     *
     * @return array<string, Processo>
     */
    public function findPorNumerosDoTenant(array $numeros, Tenant $tenant): array
    {
        if ($numeros === []) {
            return [];
        }

        $processos = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.numeroProcesso IN (:numeros)')
            ->setParameter('tenant', $tenant)
            ->setParameter('numeros', $numeros)
            ->getQuery()
            ->getResult();

        $porNumero = [];
        foreach ($processos as $processo) {
            $porNumero[$processo->getNumeroProcesso()] = $processo;
        }

        return $porNumero;
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
     * @param list<int>             $processosOcultos processos que não abrem para quem lista (cadeado, K3)
     * @return Processo[]
     */
    public function findByFiltrosPaginado(
        Tenant $tenant,
        array $filtros,
        int $pagina = 1,
        int $porPagina = 25,
        string $ordenar = '',
        string $direcao = 'desc',
        array $processosOcultos = [],
    ): array {
        $qb = $this->buildQbFiltros($tenant, $filtros);
        $this->semOsProcessosOcultos($qb, $processosOcultos);
        $this->aplicarOrdenacaoProcesso($qb, $ordenar, $direcao);

        return $qb
            ->setFirstResult(($pagina - 1) * $porPagina)
            ->setMaxResults($porPagina)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $filtros
     * @param list<int>             $processosOcultos
     */
    public function countByFiltros(Tenant $tenant, array $filtros, array $processosOcultos = []): int
    {
        $qb = $this->buildQbFiltros($tenant, $filtros)->select('COUNT(p.id)');
        $this->semOsProcessosOcultos($qb, $processosOcultos);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Cadeado (spec pasta-cadeado K3): processo cujas pastas são todas ocultas para quem lista não entra.
     *
     * @param list<int> $processosOcultos
     */
    private function semOsProcessosOcultos(QueryBuilder $qb, array $processosOcultos): void
    {
        if ($processosOcultos !== []) {
            $qb->andWhere('p.id NOT IN (:processosOcultos)')->setParameter('processosOcultos', $processosOcultos);
        }
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

    /**
     * Os valores de `orgao_julgador` com a acentuação estragada pelo mapper antigo ("Ã"/"Â" + U+0080..U+00BF, ou o "Μ"
     * grego do "õ"), com a quantidade de cada um. Todos os escritórios: é o reparo do comando
     * `app:processo:corrigir-acentuacao-do-orgao`, e o nome do órgão é público.
     *
     * @return array<string, int> valor → quantidade
     */
    public function orgaosJulgadoresComAcentuacaoEstragada(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT orgao_julgador AS valor, count(*) AS n FROM processo WHERE orgao_julgador ~ ('[ÃÂ][' || chr(128) || '-' || chr(191) || chr(924) || ']') GROUP BY orgao_julgador ORDER BY count(*) DESC, orgao_julgador",
        );

        $valores = [];
        foreach ($linhas as $linha) {
            $valores[(string) $linha['valor']] = (int) $linha['n'];
        }

        return $valores;
    }

    /** Troca um valor de `orgao_julgador` por outro em todos os escritórios (reparo da acentuação); devolve quantos mudaram. */
    public function trocarOrgaoJulgador(string $antes, string $depois): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE App\Processo\Entity\Processo e SET e.orgaoJulgador = :depois WHERE e.orgaoJulgador = :antes',
        )->setParameter('antes', $antes)->setParameter('depois', $depois)->execute();
    }
}
