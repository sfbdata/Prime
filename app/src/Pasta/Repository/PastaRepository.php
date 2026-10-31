<?php

namespace App\Pasta\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Entity\CasoCobranca;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Criterio\PastaFinalizadaNoPeriodo;
use App\Pasta\DTO\OcorrenciaDaBuscaRapida;
use App\Pasta\DTO\PastaVinculadaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\NivelNoCadeado;
use App\Pasta\Entity\PastaFavorita;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Processo\Entity\ParteProcesso;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Entity\PrioridadePasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Processo\Entity\Processo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pasta>
 */
class PastaRepository extends ServiceEntityRepository
{
    /**
     * Chave de ordenação das setas ‹ › e do "N de M" (`vizinha()` e `posicaoNoAcervo()`),
     * sobre o alias `p`: prefixo numérico do NUP com o NULL neutralizado em -1, e o NUP cru
     * com o NULL neutralizado em ''. Fonte única: se as duas consultas divergissem, o
     * contador e as setas discordariam. NÃO é a chave de `aplicarOrdenacao` — lá a variante
     * `THEN 1 ELSE 0` é outra expressão, de propósito.
     */
    private const EXPR_PREFIXO_NUP = 'CASE WHEN CAST_INT_PREFIXO(p.nup) IS NULL THEN -1 ELSE CAST_INT_PREFIXO(p.nup) END';
    private const EXPR_NUP_CRU     = "CASE WHEN p.nup IS NULL THEN '' ELSE p.nup END";

    /**
     * Filtros do sistema da seção EXPEDIENTES do trilho (desenho 1.1 da Lista, E12/E12b). Os prazos vêm das metas
     * e dos prazos e audiências registrados à mão na pasta; Protocolar vem do andamento da pasta.
     */
    public const FILTROS_EXPEDIENTE = ['resp10', 'findou', 'aud', 'prazo', 'metas', 'protocolar'];

    /**
     * Filtros de ATRIBUTO da pasta (spec pasta-cadeado D1): com qualquer um deles, a pasta oculta pelo cadeado
     * para quem consulta fica FORA do resultado, senão o filtro viraria oráculo ("a oculta tem audiência",
     * "é do Fulano"). Número (`nup`), cliente (`cliente`) e a busca livre não estão aqui: a oculta continua
     * casando por eles. O marcador também é atributo (ver `buildQbPorMarcador`).
     */
    private const FILTROS_DE_ATRIBUTO = ['responsavel', 'status', 'prioridade', 'criado_por', 'concluida', 'data_de', 'data_ate', 'expediente', 'acao'];

    public function __construct(
        ManagerRegistry $registry,
        private readonly ?NivelNoCadeado $nivelNoCadeado = null,
    ) {
        parent::__construct($registry, Pasta::class);
    }

    /**
     * O polo passivo da parte do processo: `ParteProcesso.papel` é texto livre (em maiúsculas), por isso o casamento
     * por radical (PASSIV, RÉU/REU, REQUERID, EXECUTAD, RECLAMAD). Vale para a coluna Réu, a busca e a ordenação.
     */
    private static function poloPassivo(string $alias): string
    {
        return '(' . implode(' OR ', array_map(
            static fn (string $radical): string => sprintf("UPPER(%s.papel) LIKE '%%%s%%'", $alias, $radical),
            ['PASSIV', 'RÉU', 'REU', 'REQUERID', 'EXECUTAD', 'RECLAMAD'],
        )) . ')';
    }

    /**
     * A busca livre acha a pasta pelo réu (desenho do Expediente VS 18.10.26: `nup + cliente + reu + acao`), com a
     * precedência da coluna (`reusPorPasta`): o cadastrado na pasta; o polo passivo dos processos vinculados só na
     * pasta sem nenhum cadastrado. Sem acento e sem caixa.
     */
    private static function existeReuCasando(string $parametro): string
    {
        return sprintf(
            '(EXISTS (SELECT 1 FROM %s reu_b WHERE reu_b.pasta = p AND UNACCENT(LOWER(reu_b.nome)) LIKE UNACCENT(:%s))'
            . ' OR EXISTS (SELECT 1 FROM %s ppr_b JOIN ppr_b.processo prr_b JOIN %s par_b WITH par_b.processo = prr_b'
            . ' WHERE ppr_b.pasta = p AND %s AND UNACCENT(LOWER(par_b.nome)) LIKE UNACCENT(:%s)'
            . ' AND NOT EXISTS (SELECT 1 FROM %s reu_c WHERE reu_c.pasta = p)))',
            \App\Pasta\Entity\PastaReu::class,
            $parametro,
            PastaProcesso::class,
            ParteProcesso::class,
            self::poloPassivo('par_b'),
            $parametro,
            \App\Pasta\Entity\PastaReu::class,
        );
    }

    /**
     * Pastas com cliente principal cujo nome gravado ainda é um texto composto ("cliente - alguém").
     * Insumo do comando que alinha o nome gravado ao do cliente (`RegravarNomeDasPastasJudiciaisUseCase`).
     *
     * @return list<Pasta>
     */
    public function comNomeCompostoEClientePrincipal(Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.clientePrincipal IS NOT NULL')
            ->andWhere('p.nomeCliente LIKE :composto')
            ->setParameter('tenant', $tenant)
            ->setParameter('composto', '% - %')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Subconsulta que casa a pasta cujo CREDOR (fantasia da carteira) ou DEVEDOR (pessoa cobrada)
     * bate com o termo — é o que faz a busca enxergar o identificador DERIVADO das pastas
     * judicializadas, que não existe gravado em lugar nenhum
     * (spec `pasta-prefixo-do-credor-derivado.md` §6).
     *
     * É `EXISTS`, e não `JOIN`, de propósito: um JOIN multiplicaria as linhas da listagem principal,
     * que serve todos os domínios. O EXISTS não altera a cardinalidade nem o custo das outras buscas.
     */
    private static function existeCredorOuDevedorCasando(string $parametro, bool $acentoInsensivel = false): string
    {
        $campo = static fn (string $expr): string => $acentoInsensivel
            ? sprintf('UNACCENT(LOWER(%s)) LIKE UNACCENT(:%s)', $expr, $parametro)
            : sprintf('LOWER(%s) LIKE :%s', $expr, $parametro);

        return sprintf(
            'EXISTS (SELECT 1 FROM %s cc_b LEFT JOIN cc_b.objeto obj_b LEFT JOIN obj_b.carteira cart_b '
            . 'LEFT JOIN cart_b.cliente clc_b LEFT JOIN %s pjc_b WITH pjc_b.id = clc_b.id '
            . 'LEFT JOIN cc_b.pessoaCobradaAtual pes_b '
            . 'WHERE cc_b.pastaJudicial = p AND (%s OR %s))',
            CasoCobranca::class,
            ClientePJ::class,
            $campo('pjc_b.nomeFantasia'),
            $campo('pes_b.nome'),
        );
    }

    /**
     * @param array<string, string> $filters
     * @return Pasta[]
     */
    public function findByFilters(
        array $filters,
        Tenant $tenant,
        int $page = 1,
        int $perPage = 25,
        string $ordenar = '',
        string $direcao = 'desc',
        ?User $favoritosDe = null,
        ?User $quemBusca = null,
    ): array {
        $qb = $this->buildQbByFilters($filters, $tenant, $quemBusca)->groupBy('p.id');
        $this->aplicarOrdenacao($qb, $ordenar, $direcao, $favoritosDe);

        return $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $filters
     */
    public function countByFilters(array $filters, Tenant $tenant, ?User $quemBusca = null): int
    {
        return (int) $this->buildQbByFilters($filters, $tenant, $quemBusca)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return string[]
     */
    public function findAllNups(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.nup')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.nup', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'nup');
    }

    /**
     * Pasta do escritório pelo número (NUP), fora da lixeira. Usada pela Agenda ao ligar compromisso a
     * pasta. O número é normalizado como em Pasta::setNup (sem espaços nas pontas, maiúsculas).
     */
    public function findDoEscritorioPorNup(string $nup, Tenant $tenant): ?Pasta
    {
        $nup = mb_strtoupper(trim($nup));

        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.nup = :nup')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('nup', $nup)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Pastas do escritório com o MESMO cliente e a MESMA ação — base do aviso de duplicada na
     * criação (spec fase2 §12.5/D12.5).
     *
     * Por que existe: a numeração automática (R1) fechou a colisão de NÚMERO, mas não a dor que a
     * originou. Duas pessoas criando a mesma pasta ao mesmo tempo agora recebem 1232 e 1233 — são
     * duas pastas do mesmo caso, e pior: somem da consulta de detecção do §12.4, que procura NUP
     * repetido. Este método é o que enxerga a duplicata de verdade.
     *
     * Comparação tolerante a acento e caixa (UNACCENT+LOWER, como a busca livre da lista): "AÇÃO
     * DE COBRANÇA" e "acao de cobranca" são a mesma coisa para quem digita com pressa.
     *
     * NÃO bloqueia nada — o mesmo cliente pode legitimamente ter vários casos parecidos. Quem
     * decide é o usuário, na confirmação.
     *
     * @return list<Pasta>
     */
    public function findSemelhantesPorClienteEAcao(Tenant $tenant, ?string $cliente, ?string $acao, int $limite = 5): array
    {
        $qb = $this->qbSemelhantes($tenant, $cliente, $acao);

        if ($qb === null) {
            return [];
        }

        return $qb->orderBy('p.id', 'DESC')->setMaxResults($limite)->getQuery()->getResult();
    }

    /**
     * Quantas pastas semelhantes existem AO TODO — não só as exibidas.
     *
     * Existe porque a lista é truncada em 5 e a tela precisa dizer a verdade: em produção há um
     * par (cliente, ação) com **27 pastas**. Contar `|length` da lista truncada faria a tela
     * anunciar "5 pastas já cadastradas" quando são 27 — número errado com cara de certo, que é
     * exatamente o tipo de defeito que este projeto já pagou caro.
     */
    public function contarSemelhantesPorClienteEAcao(Tenant $tenant, ?string $cliente, ?string $acao): int
    {
        $qb = $this->qbSemelhantes($tenant, $cliente, $acao);

        if ($qb === null) {
            return 0;
        }

        return (int) $qb->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Base comum das duas consultas de semelhança. Devolve `null` quando não há o que procurar —
     * sem cliente sobrariam TODAS as pastas sem cliente do escritório, e o aviso viraria ruído
     * que o usuário aprende a ignorar.
     */
    private function qbSemelhantes(Tenant $tenant, ?string $cliente, ?string $acao): ?QueryBuilder
    {
        $cliente = trim((string) $cliente);

        if ($cliente === '') {
            return null;
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            // Pasta excluída (lápide) não é pasta duplicada: avisar "já existe uma pasta deste
            // cliente" apontando para uma que a pessoa acabou de excluir faria ela desistir de
            // criar a pasta certa.
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('UNACCENT(LOWER(p.nomeCliente)) = UNACCENT(LOWER(:cliente))')
            // Ação ausente conta como ausente dos dois lados: pasta sem ação só é semelhante a
            // outra sem ação. COALESCE evita o NULL != NULL do SQL, que nunca casaria.
            ->andWhere('COALESCE(UNACCENT(LOWER(p.nomeAcao)), :vazio) = COALESCE(UNACCENT(LOWER(:acao)), :vazio)')
            ->setParameter('tenant', $tenant)
            ->setParameter('cliente', $cliente)
            ->setParameter('acao', ($a = trim((string) $acao)) !== '' ? $a : null)
            ->setParameter('vazio', '');
    }

    /**
     * Mapa `label → id` das pastas do tenant, para selects (ex.: judicialização de Cobranças). Escopo
     * multi-tenant obrigatório; label = NUP quando houver, senão "Pasta #id".
     *
     * @return array<string, int>
     */
    public function opcoesDoTenant(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id', 'p.nup')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.nup', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $opcoes = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $label = ($row['nup'] !== null && $row['nup'] !== '') ? (string) $row['nup'] : 'Pasta #' . $id;
            $opcoes[$label] = $id;
        }

        return $opcoes;
    }

    /**
     * Vínculos pasta-processo dos processos dados, só em pastas do escritório que não foram excluídas: dão a
     * ORIGEM de cada processo na busca da janela "Vincular processo" (desenho 02, `procCatalogo`). O principal
     * vem primeiro; depois, a ordem em que foram vinculados.
     *
     * @param list<int> $idsProcesso
     *
     * @return list<PastaProcesso>
     */
    public function vinculosDosProcessos(array $idsProcesso, Tenant $tenant): array
    {
        if ($idsProcesso === []) {
            return [];
        }

        return $this->getEntityManager()->createQueryBuilder()
            ->select('v', 'pa')
            ->from(PastaProcesso::class, 'v')
            ->join('v.pasta', 'pa')
            ->andWhere('pa.tenant = :tenant')
            ->andWhere('pa.excluidaEm IS NULL')
            ->andWhere('IDENTITY(v.processo) IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $idsProcesso)
            ->orderBy('v.principal', 'DESC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pastas do escritório que casam com o termo — alimenta a busca do modal "Vincular uma pasta
     * existente" da judicialização.
     *
     * Substituiu o {@see self::opcoesDoTenant()} naquele modal: aquele carregava TODAS as pastas
     * (1.099 em produção) em toda abertura da página da unidade, rotuladas só pelo número. Sem o
     * nome ninguém reconhecia a pasta, e sem reconhecer ninguém vinculava.
     *
     * Busca em número, nome e ação, ignorando acento e caixa — o mesmo tratamento da busca livre do
     * Expediente. Pasta EXCLUÍDA (lápide) fica de fora: vincular um caso a uma pasta que o escritório
     * já descartou é sempre engano.
     *
     * `$idsExcluidos` é genérico de propósito (a Pasta não conhece `CasoCobranca` — ligação
     * unidirecional, ver `CasoCobranca::$pastaJudicial`): quem decide QUAIS ids excluir é o chamador
     * (hoje, o controller de Cobrança, tirando pastas já judicializadas por outro caso — Problema B).
     *
     * @param list<int> $idsExcluidos
     *
     * @return list<array{id: int, texto: string}>
     */
    public function buscarParaVinculo(string $termo, Tenant $tenant, int $limite = 20, array $idsExcluidos = [], ?User $quemBusca = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id AS id', 'p.nup AS nup', 'p.nomeCliente AS nomeCliente', 'p.nomeAcao AS nomeAcao')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limite);

        if ($idsExcluidos !== []) {
            $qb->andWhere('p.id NOT IN (:idsExcluidos)')->setParameter('idsExcluidos', $idsExcluidos);
        }
        if ($quemBusca !== null) {
            // Cadeado: vincular escreve na pasta, então só é oferecida a que a pessoa EDITA (não a oculta nem a de só visualizar).
            $this->nivelNoCadeado?->restringirAoEditavel($qb, 'p', $quemBusca);
        }

        $termo = trim($termo);

        if ($termo !== '') {
            $qb->andWhere($qb->expr()->orX(
                'UNACCENT(LOWER(p.nup)) LIKE UNACCENT(:termo)',
                'UNACCENT(LOWER(p.nomeCliente)) LIKE UNACCENT(:termo)',
                'UNACCENT(LOWER(p.nomeAcao)) LIKE UNACCENT(:termo)',
            ))->setParameter('termo', '%' . mb_strtolower($termo) . '%');
        }

        $resultados = [];
        foreach ($qb->getQuery()->getArrayResult() as $linha) {
            $partes = array_filter(
                [$linha['nup'], $linha['nomeCliente'], $linha['nomeAcao']],
                static fn (?string $v): bool => $v !== null && trim($v) !== '',
            );

            $resultados[] = [
                'id' => (int) $linha['id'],
                // Hífen, o mesmo separador do nome no Drive e do próprio identificador: um separador
                // só no sistema inteiro. A ambiguidade que o travessão evitaria (onde termina o nome
                // e começa a ação) é resolvida na LEITURA, pela regra do último traço
                // (`AcervoNomesParser::separarNoUltimoTraco`), não por um símbolo diferente na tela.
                'texto' => $partes === [] ? 'Pasta #' . $linha['id'] : implode(' - ', $partes),
            ];
        }

        return $resultados;
    }

    /**
     * Pastas do escritório que têm ESTE processo vinculado, projetadas para a tela do
     * processo (`processo_show`). É o espelho da aba "Processos vinculados" da pasta.
     *
     * Escopa por tenant EXPLICITAMENTE: `PastaProcesso` não é TenantAware — quem carrega o
     * escritório é a Pasta dona do vínculo. Pasta excluída continua na lista, marcada como
     * lápide, pela mesma razão das listagens do acervo: sumir com ela esconde o vínculo que
     * de fato existe.
     *
     * Com `quemVe`, a pasta oculta pelo cadeado para essa pessoa sai só com o número e o cliente
     * (spec pasta-cadeado D1).
     *
     * @return PastaVinculadaOutput[]
     */
    public function listarVinculadasAoProcesso(Processo $processo, Tenant $tenant, ?User $quemVe = null): array
    {
        $linhas = $this->createQueryBuilder('p')
            ->select(
                'p.id AS id',
                'p.nup AS nup',
                'p.nomeCliente AS nomeCliente',
                'p.nomeAcao AS nomeAcao',
                'p.situacao AS situacao',
                'p.excluidaEm AS excluidaEm',
                'pp.principal AS principal',
                'pp.vinculadoEm AS vinculadoEm',
            )
            ->join('p.pastaProcessos', 'pp')
            ->andWhere('pp.processo = :processo')
            ->setParameter('processo', $processo)
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('pp.principal', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(function (array $linha) use ($tenant, $quemVe): PastaVinculadaOutput {
            $pasta = PastaVinculadaOutput::fromRow($linha);
            $oculta = $quemVe !== null && $this->nivelNoCadeado !== null
                && !$this->nivelNoCadeado->nivelPorId($quemVe, $tenant, $pasta->id)->podeVer();

            return $oculta ? $pasta->oculta() : $pasta;
        }, $linhas);
    }

    /**
     * @return Pasta[]
     */
    public function findByCliente(Cliente $cliente, Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.clientes', 'c')
            ->where('c = :cliente')
            ->setParameter('cliente', $cliente)
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.dataAbertura', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Pasta[]
     */
    public function findPorMarcador(
        Marcador $marcador,
        Tenant $tenant,
        int $page = 1,
        int $perPage = 25,
        string $ordenar = '',
        string $direcao = 'desc',
        ?User $favoritosDe = null,
        ?User $quemBusca = null,
    ): array {
        $qb = $this->buildQbPorMarcador([], $marcador, $tenant, $quemBusca)->groupBy('p.id');
        $this->aplicarOrdenacao($qb, $ordenar, $direcao, $favoritosDe);

        return $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countPorMarcador(Marcador $marcador, Tenant $tenant, ?User $quemBusca = null): int
    {
        return (int) $this->buildQbPorMarcador([], $marcador, $tenant, $quemBusca)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<string, string> $filters
     * @return Pasta[]
     */
    public function findByFiltrosEMarcador(
        array $filters,
        Marcador $marcador,
        Tenant $tenant,
        int $page = 1,
        int $perPage = 25,
        string $ordenar = '',
        string $direcao = 'desc',
        ?User $favoritosDe = null,
        ?User $quemBusca = null,
    ): array {
        $qb = $this->buildQbPorMarcador($filters, $marcador, $tenant, $quemBusca)->groupBy('p.id');
        $this->aplicarOrdenacao($qb, $ordenar, $direcao, $favoritosDe);

        return $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $filters
     */
    public function countByFiltrosEMarcador(
        array $filters,
        Marcador $marcador,
        Tenant $tenant,
        ?User $quemBusca = null,
    ): int {
        return (int) $this->buildQbPorMarcador($filters, $marcador, $tenant, $quemBusca)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Pasta[]
     */
    public function findAtivasPorResponsavel(
        User $responsavel,
        Tenant $tenant,
        ?string $cliente = null,
        ?string $prioridade = null,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->where('p.responsavel = :responsavel')
            ->andWhere('p.situacao = :situacao')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('responsavel', $responsavel)
            ->setParameter('situacao', Pasta::SITUACAO_ATIVA)
            ->setParameter('tenant', $tenant)
            ->orderBy('p.prioridade', 'DESC')
            ->addOrderBy('p.dataAbertura', 'DESC');

        if ($cliente !== null && $cliente !== '') {
            $qb->andWhere('p.nomeCliente LIKE :cliente')
               ->setParameter('cliente', '%' . $cliente . '%');
        }

        if ($prioridade !== null && $prioridade !== '') {
            $qb->andWhere('p.prioridade = :prioridade')
               ->setParameter('prioridade', PrioridadePasta::from($prioridade));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<int, int>  marcadorId => total
     */
    public function countPorMarcadores(Tenant $tenant, ?User $quemBusca = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('m.id AS marcador_id, COUNT(p.id) AS total')
            ->join('p.marcadores', 'm')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('m.id');
        $this->soAsVisiveis($qb, $quemBusca);
        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['marcador_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Pastas distintas de cada marcador de topo somando as dos seus submarcadores: a contagem do trilho no
     * desenho 1.1 (E12). Distinta porque a pasta pode ter o marcador e um submarcador dele ao mesmo tempo.
     *
     * @return array<int, int> marcadorDeTopoId => total
     */
    public function countPorMarcadoresDeTopoComFilhos(Tenant $tenant, ?User $quemBusca = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COALESCE(IDENTITY(m.pai), m.id) AS raiz, COUNT(DISTINCT p.id) AS total')
            ->join('p.marcadores', 'm')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('raiz');
        $this->soAsVisiveis($qb, $quemBusca);
        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['raiz']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countAtivas(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.situacao = :situacao')
            ->setParameter('tenant', $tenant)
            ->setParameter('situacao', Pasta::SITUACAO_ATIVA)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate, responsavel)
     * @param list<int>            $pastasOcultas  pastas ocultas pelo cadeado para quem olha não contam (D1)
     */
    public function countUrgentes(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.prioridade = :prioridade')
            ->setParameter('tenant', $tenant)
            ->setParameter('prioridade', PrioridadePasta::Urgente);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);
        $this->semPastasOcultasNoDashboard($qb, $pastasOcultas);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @param list<int>            $pastasOcultas  pastas ocultas pelo cadeado para quem olha não contam (D1)
     * @return array<int, int>  userId => total (todas as situações)
     */
    public function countPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('r.id AS responsavel_id, COUNT(p.id) AS total')
            ->join('p.responsavel', 'r')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('r.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);
        $this->semPastasOcultasNoDashboard($qb, $pastasOcultas);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @param list<int>            $pastasOcultas  pastas ocultas pelo cadeado para quem olha não contam (D1)
     * @return array<int, int>  userId => total (só ativas)
     */
    public function countAtivasPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('r.id AS responsavel_id, COUNT(p.id) AS total')
            ->join('p.responsavel', 'r')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.situacao = :situacao')
            ->setParameter('tenant', $tenant)
            ->setParameter('situacao', Pasta::SITUACAO_ATIVA)
            ->groupBy('r.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);
        $this->semPastasOcultasNoDashboard($qb, $pastasOcultas);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Pastas ABERTAS por cada colaborador — a coluna "Pastas Criadas" do Dashboard.
     *
     * Mede coisa diferente do `countPorResponsavel`: aquele conta a pasta para quem responde
     * por ela hoje, este conta para quem a abriu. Em produção, 614 das 1.083 pastas têm criador
     * diferente do responsável (medido em 31/08/2026) — as duas colunas não são redundantes.
     *
     * Pasta-lápide fica de fora (decisão do dono, 31/08/2026): pasta excluída não conta como
     * trabalho entregue. Pasta sem criador (legado) não entra na contagem de ninguém — o JOIN
     * interno com `p.criadoPor` já a descarta.
     *
     * O período usa a mesma régua das demais colunas do painel (`p.dataAbertura`, via
     * aplicarFiltrosDashboard) para que um mesmo filtro signifique a mesma janela na linha
     * inteira. Em produção as duas datas coincidem: `data_abertura` e `created_at` caem no mesmo
     * dia nas 1.083 pastas.
     *
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @param list<int>|null       $pastasOcultas  ids de `AcessoAPasta::idsOcultosPara` de quem olha, já
     *                                             calculados pelo Dashboard; null = busca pela sessão
     * @return array<int, int>  userId => total de pastas criadas
     */
    public function countCriadasPorCriador(Tenant $tenant, array $filtros = [], ?array $pastasOcultas = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('c.id AS criador_id, COUNT(p.id) AS total')
            ->join('p.criadoPor', 'c')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->groupBy('c.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);
        // Cadeado: o número leva à lista "pastas criadas por X", que não mostra a oculta com esse filtro (D1).
        $ocultas = $pastasOcultas ?? $this->nivelNoCadeado?->idsDasPastasOcultasDeQuemEstaLogado() ?? [];
        if ($ocultas !== []) {
            $qb->andWhere('p.id NOT IN (:criadasOcultas)')->setParameter('criadasOcultas', $ocultas);
        }

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['criador_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Cadeado (spec pasta-cadeado D1): nas colunas de pastas do Dashboard, a pasta oculta para quem olha não
     * conta, porque a lista do Expediente a que o número leva, filtrada por responsável ou prioridade, não a
     * mostra. A raiz é `p`; os ids vêm de `AcessoAPasta::idsOcultosPara`, uma consulta por requisição.
     *
     * @param list<int> $pastasOcultas
     */
    private function semPastasOcultasNoDashboard(QueryBuilder $qb, array $pastasOcultas): void
    {
        if ($pastasOcultas !== []) {
            $qb->andWhere('p.id NOT IN (:pastasOcultasDashboard)')->setParameter('pastasOcultasDashboard', $pastasOcultas);
        }
    }

    /**
     * Aplica os filtros globais do Dashboard a uma query cujo alias raiz é 'p':
     * período por `p.dataAbertura` e, quando $filtrarResponsavel, o responsável.
     *
     * @param array<string, mixed> $filtros  data_de, data_ate, responsavel
     */
    private function aplicarFiltrosDashboard(QueryBuilder $qb, array $filtros, bool $filtrarResponsavel): void
    {
        $dataDe = $this->parseDataFiltro((string) ($filtros['data_de'] ?? ''), false);
        if ($dataDe !== null) {
            $qb->andWhere('p.dataAbertura >= :fDataDe')->setParameter('fDataDe', $dataDe);
        }

        $dataAte = $this->parseDataFiltro((string) ($filtros['data_ate'] ?? ''), true);
        if ($dataAte !== null) {
            $qb->andWhere('p.dataAbertura <= :fDataAte')->setParameter('fDataAte', $dataAte);
        }

        // "Zerar" do Dashboard (DASH-014): só as pastas abertas a partir do ponto de corte.
        $contarDesde = $filtros['contar_desde'] ?? null;
        if ($contarDesde instanceof \DateTimeImmutable) {
            $qb->andWhere('p.dataAbertura >= :fContarDesde')->setParameter('fContarDesde', $contarDesde);
        }

        if ($filtrarResponsavel) {
            $resp = (int) ($filtros['responsavel'] ?? 0);
            if ($resp > 0) {
                $qb->join('p.responsavel', 'r_dash')
                   ->andWhere('r_dash.id = :fResp')
                   ->setParameter('fResp', $resp);
            }
        }
    }

    /**
     * @param array<string, string> $filters
     */
    private function buildQbByFilters(array $filters, Tenant $tenant, ?User $quemBusca = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $this->aplicarFiltrosPasta($qb, $filters, $quemBusca);

        return $qb;
    }

    /**
     * @param array<string, string> $filters
     */
    private function buildQbPorMarcador(
        array $filters,
        Marcador $marcador,
        Tenant $tenant,
        ?User $quemBusca = null,
    ): QueryBuilder {
        // Marcador de topo traz também as pastas dos submarcadores (desenho 1.1, dc `filtrados`, E12).
        $qb = $this->createQueryBuilder('p')
            ->join('p.marcadores', 'm')
            ->where('m = :marcador OR m.pai = :marcador')
            ->setParameter('marcador', $marcador)
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // O marcador é atributo: a pasta oculta para quem consulta não entra no painel dele (D1).
        $this->soAsVisiveis($qb, $quemBusca);
        $this->aplicarFiltrosPasta($qb, $filters, $quemBusca);

        return $qb;
    }

    /** Cadeado (spec pasta-cadeado D1): só as pastas que `quemBusca` vê. Sem `quemBusca`, nada muda. */
    private function soAsVisiveis(QueryBuilder $qb, ?User $quemBusca): void
    {
        if ($quemBusca !== null) {
            $this->nivelNoCadeado?->restringirAoVisivel($qb, 'p', $quemBusca);
        }
    }

    /** @param array<string, string> $filters */
    private static function temFiltroDeAtributo(array $filters): bool
    {
        foreach (self::FILTROS_DE_ATRIBUTO as $chave) {
            if (trim((string) ($filters[$chave] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Aplica as cláusulas de filtro comuns às listagens de pasta.
     *
     * Os joins de cliente (PF/PJ) e do processo principal são compartilhados por
     * 'cliente', 'acao' e 'busca'; por isso são adicionados uma única vez, evitando
     * colisão de alias quando mais de um desses filtros está presente.
     *
     * @param array<string, string> $filters
     */
    private function aplicarFiltrosPasta(QueryBuilder $qb, array $filters, ?User $quemBusca = null): void
    {
        if (self::temFiltroDeAtributo($filters)) {
            $this->soAsVisiveis($qb, $quemBusca);
        }
        if (!empty($filters['expediente']) && in_array($filters['expediente'], self::FILTROS_EXPEDIENTE, true)) {
            $this->aplicarFiltroDoExpediente($qb, (string) $filters['expediente']);
        }

        if (!empty($filters['nup'])) {
            $qb->andWhere('p.nup LIKE :nup')
               ->setParameter('nup', '%' . $filters['nup'] . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('p.situacao = :status')
               ->setParameter('status', $filters['status']);
        }

        // "Sem responsável" (E8 da fidelidade, decisão do dono 14/10; o desenho de 13/10 tem a opção no
        // filtro de responsável): a pasta sem ninguém atribuído. Antes, `responsavel=sem` virava
        // `(int) 0` e devolvia lista vazia — nenhum chamador dependia disso.
        // `responsavel=equipe[:cargo]`: destino do Total de Pastas concluídas do Dashboard (ver filtrarPorEquipe).
        $respFiltro = trim((string) ($filters['responsavel'] ?? ''));
        if ($respFiltro === 'sem') {
            $qb->andWhere('p.responsavel IS NULL');
        } elseif ($respFiltro === 'equipe' || str_starts_with($respFiltro, 'equipe:')) {
            $this->filtrarPorEquipe($qb, $respFiltro);
        } elseif (!empty($filters['responsavel'])) {
            $qb->join('p.responsavel', 'r')
               ->andWhere('r.id = :responsavel')
               ->setParameter('responsavel', (int) $filters['responsavel']);
        }

        // "Pastas criadas por X" — destino do número clicável do Dashboard. Usa EXATAMENTE o
        // critério de countCriadasPorCriador: JOIN interno com o criador (pasta sem criador
        // fica de fora) e SEM a lápide (`excluidaEm IS NULL`). A exclusão da lápide só vale
        // quando este filtro vem: sem ele, o acervo segue listando a lápide riscada, como
        // sempre (decisão do dono, 31/08). Sem isso o número clicado não bateria com a lista.
        //
        // `criado_por=equipe` (e `equipe:<cargo>`) é o destino do "ver pastas" do CARD Pastas
        // criadas (DASH-006): o card soma as linhas da tabela, ou seja, conta as pastas cujo
        // criador é colaborador ATIVO do escritório (vínculo `UserTenant.isActive`), opcionalmente
        // só os do cargo escolhido no painel (`__sem__` = sem cargo, como no Dashboard). O vínculo
        // é procurado no escritório da própria consulta (`:tenant`, que os dois chamadores já
        // definem): criador de outro escritório não entra. Cargo vem dentro do valor para que o
        // filtro viaje como um campo só pelo deep-link e pelo estado salvo do Acervo.
        $criadoPor = trim((string) ($filters['criado_por'] ?? ''));
        if ($criadoPor === 'equipe' || str_starts_with($criadoPor, 'equipe:')) {
            $cargoCriador = $criadoPor === 'equipe' ? '' : trim(substr($criadoPor, strlen('equipe:')));

            $sub = 'SELECT 1 FROM ' . UserTenant::class . ' ut_cpe LEFT JOIN ut_cpe.cargo cg_cpe'
                . ' WHERE ut_cpe.user = criador_f AND ut_cpe.tenant = :tenant AND ut_cpe.isActive = true';
            // '__sem__' = ObterDadosDashboardUseCase::CARGO_SEM (literal aqui para o repositório
            // não depender do UseCase do Dashboard).
            if ($cargoCriador === '__sem__') {
                $sub .= " AND (cg_cpe.id IS NULL OR TRIM(cg_cpe.nome) = '')";
            } elseif ($cargoCriador !== '') {
                $sub .= ' AND cg_cpe.nome = :cargoCriador';
                $qb->setParameter('cargoCriador', $cargoCriador);
            }

            $qb->join('p.criadoPor', 'criador_f')
               ->andWhere('EXISTS (' . $sub . ')')
               ->andWhere('p.excluidaEm IS NULL');
        } elseif (!empty($filters['criado_por'])) {
            $qb->join('p.criadoPor', 'criador_f')
               ->andWhere('criador_f.id = :criadoPor')
               ->andWhere('p.excluidaEm IS NULL')
               ->setParameter('criadoPor', (int) $filters['criado_por']);
        }

        $precisaJoinCliente  = !empty($filters['cliente']) || !empty($filters['busca']);
        $precisaJoinProcesso = !empty($filters['acao']) || !empty($filters['busca']);

        if ($precisaJoinCliente) {
            $qb->leftJoin('p.clientes', 'c_cli')
               ->leftJoin(ClientePF::class, 'cpf', 'WITH', 'cpf.id = c_cli.id')
               ->leftJoin(ClientePJ::class, 'cpj', 'WITH', 'cpj.id = c_cli.id');
        }

        if ($precisaJoinProcesso) {
            $qb->leftJoin('p.pastaProcessos', 'pp_acao', 'WITH', 'pp_acao.principal = true')
               ->leftJoin('pp_acao.processo', 'proc');
        }

        if (!empty($filters['cliente'])) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(cpf.nomeCompleto) LIKE :cliente',
                    'LOWER(cpj.razaoSocial) LIKE :cliente',
                    'LOWER(p.nomeCliente) LIKE :cliente',
                    self::existeCredorOuDevedorCasando('cliente'),
                )
            )->setParameter('cliente', '%' . mb_strtolower($filters['cliente']) . '%');
        }

        if (!empty($filters['acao'])) {
            $qb->andWhere('p.nomeAcao LIKE :acao OR proc.classeProcessual LIKE :acao')
               ->setParameter('acao', '%' . $filters['acao'] . '%');
        }

        if (!empty($filters['busca'])) {
            $this->aplicarBusca($qb, (string) $filters['busca'], $quemBusca);
        }

        if (!empty($filters['prioridade'])) {
            $prioridade = PrioridadePasta::tryFrom((string) $filters['prioridade']);
            if ($prioridade !== null) {
                $qb->andWhere('p.prioridade = :prioridade')
                   ->setParameter('prioridade', $prioridade);
            }
        }

        $dataDe  = $this->parseDataFiltro($filters['data_de'] ?? '', false);
        $dataAte = $this->parseDataFiltro($filters['data_ate'] ?? '', true);

        // "Concluídas no período" (destino do número da coluna Pastas concluídas do Dashboard, regra B12):
        // as datas passam a valer sobre a CHEGADA À FINALIZADA, e não sobre a abertura, com o critério
        // compartilhado com a coluna (PastaFinalizadaNoPeriodo). Sem o filtro, nada muda.
        if (($filters['concluida'] ?? '') === '1') {
            PastaFinalizadaNoPeriodo::aplicar($qb, 'p', $dataDe, $dataAte);
        } else {
            if ($dataDe !== null) {
                $qb->andWhere('p.dataAbertura >= :dataDe')
                   ->setParameter('dataDe', $dataDe);
            }

            if ($dataAte !== null) {
                $qb->andWhere('p.dataAbertura <= :dataAte')
                   ->setParameter('dataAte', $dataAte);
            }
        }
    }

    /**
     * `responsavel=equipe` (ou `equipe:<cargo>`): pastas cujo responsável é colaborador ATIVO do escritório da
     * consulta (`:tenant`), opcionalmente de um cargo (`__sem__` = sem cargo). O Total do Dashboard soma só as linhas exibidas (colaboradores ativos), então a lista conta o mesmo:
     * pasta sem responsável ou de quem saiu do escritório fica fora dos dois lados. Mesma lógica do `criado_por=equipe`.
     */
    private function filtrarPorEquipe(QueryBuilder $qb, string $respFiltro): void
    {
        $cargoResp = $respFiltro === 'equipe' ? '' : trim(substr($respFiltro, strlen('equipe:')));

        $sub = 'SELECT 1 FROM ' . UserTenant::class . ' ut_rse LEFT JOIN ut_rse.cargo cg_rse'
            . ' WHERE ut_rse.user = resp_eq AND ut_rse.tenant = :tenant AND ut_rse.isActive = true';
        if ($cargoResp === '__sem__') {
            $sub .= " AND (cg_rse.id IS NULL OR TRIM(cg_rse.nome) = '')";
        } elseif ($cargoResp !== '') {
            $sub .= ' AND cg_rse.nome = :cargoResp';
            $qb->setParameter('cargoResp', $cargoResp);
        }

        $qb->join('p.responsavel', 'resp_eq')
           ->andWhere('EXISTS (' . $sub . ')');
    }

    /**
     * A busca livre da Lista. Pasta OCULTA pelo cadeado para quem busca (spec pasta-cadeado D1) casa só
     * pelo que a linha dela mostra: o número da pasta e o cliente (nome da pasta, cadastro, credor ou
     * devedor). Ação, processo (número, classe, assunto) e réus só casam nas pastas que a pessoa vê. Sem
     * `quemBusca` (consultas internas) ou para quem vê tudo (Master, primário), a busca é a de sempre.
     */
    private function aplicarBusca(QueryBuilder $qb, string $busca, ?User $quemBusca): void
    {
        $doQueVe = [
            'UNACCENT(LOWER(p.nomeAcao)) LIKE UNACCENT(:busca)',
            'UNACCENT(LOWER(proc.classeProcessual)) LIKE UNACCENT(:busca)',
            'UNACCENT(LOWER(proc.numeroProcesso)) LIKE UNACCENT(:busca)',
            'UNACCENT(LOWER(proc.assuntoProcessual)) LIKE UNACCENT(:busca)',
            self::existeReuCasando('busca'),
        ];
        $visivel = $quemBusca !== null ? $this->nivelNoCadeado?->criterioDeVisivel($qb, 'p', $quemBusca) : null;

        $qb->andWhere($qb->expr()->orX(
            'UNACCENT(LOWER(p.nup)) LIKE UNACCENT(:busca)',
            'UNACCENT(LOWER(p.nomeCliente)) LIKE UNACCENT(:busca)',
            self::existeCredorOuDevedorCasando('busca', acentoInsensivel: true),
            'UNACCENT(LOWER(cpf.nomeCompleto)) LIKE UNACCENT(:busca)',
            'UNACCENT(LOWER(cpj.razaoSocial)) LIKE UNACCENT(:busca)',
            $visivel === null ? $qb->expr()->orX(...$doQueVe) : $qb->expr()->andX($visivel, $qb->expr()->orX(...$doQueVe)),
        ))->setParameter('busca', '%' . mb_strtolower($busca) . '%');
    }

    /**
     * Filtro do sistema escolhido na seção EXPEDIENTES (E12). Pasta excluída (lápide) nunca entra.
     *
     * - resp10: houve atividade nos últimos 10 dias (mensagem, observação de detalhes ou financeira, meta
     *   criada ou concluída, resposta de meta). EXISTS: cada pasta conta uma vez.
     * - findou: meta não concluída cujo prazo venceu nos últimos 10 dias (hoje fora) e, depois do prazo,
     *   nenhuma atividade na pasta.
     * - prazo: meta não concluída com prazo hoje ou depois.
     * - metas: meta não concluída sem nenhuma resposta.
     *
     * - aud: audiência registrada na pasta, não cumprida, de hoje em diante.
     * - protocolar: pasta ativa, não administrativa, que ainda não passou pela fase Protocolo do andamento.
     * Prazo = meta com prazo ou linha do `pasta_prazo` de qualquer origem: registrado à mão, lido dos registros ou
     * calculado do Push processual (origem `push`, docs/specs/prazo-do-push.md), todos pelo mesmo caminho. Como
     * ATIVIDADE (resp10 e o "depois da data" do findou), a linha do Push não conta: é publicação do juízo, não resposta.
     */
    private function aplicarFiltroDoExpediente(QueryBuilder $qb, string $filtro): void
    {
        $hoje = new \DateTimeImmutable('today');
        $d10  = $hoje->modify('-10 days');
        $qb->andWhere('p.excluidaEm IS NULL');

        $atividadeDepois = static fn (string $limite, string $sufixo): string => sprintf(
            '(EXISTS (SELECT 1 FROM %1$s m_%6$s WHERE m_%6$s.pasta = p AND m_%6$s.sistema IS NULL AND m_%6$s.criadaEm > %7$s)'
            . ' OR EXISTS (SELECT 1 FROM %2$s od_%6$s WHERE od_%6$s.pasta = p AND od_%6$s.criadaEm > %7$s)'
            . ' OR EXISTS (SELECT 1 FROM %3$s of_%6$s WHERE of_%6$s.pasta = p AND of_%6$s.criadaEm > %7$s)'
            . ' OR EXISTS (SELECT 1 FROM %4$s t_%6$s WHERE t_%6$s.pasta = p AND (t_%6$s.dataCriacao > %7$s OR t_%6$s.dataConclusao > %7$s))'
            . ' OR EXISTS (SELECT 1 FROM %5$s tm_%6$s JOIN tm_%6$s.tarefa tt_%6$s WHERE tt_%6$s.pasta = p AND tm_%6$s.criadoEm > %7$s)'
            . ' OR EXISTS (SELECT 1 FROM %8$s zp_%6$s WHERE zp_%6$s.pasta = p AND zp_%6$s.origem <> \'%9$s\' AND (zp_%6$s.criadoEm > %7$s OR zp_%6$s.cumpridoEm > %7$s)))',
            PastaMensagem::class,
            PastaObservacaoDetalhes::class,
            PastaObservacaoFinanceira::class,
            Tarefa::class,
            TarefaMensagem::class,
            $sufixo,
            $limite,
            PastaPrazo::class,
            PastaPrazo::ORIGEM_PUSH, // a publicação do juízo não é resposta do escritório
        );

        switch ($filtro) {
            case 'resp10':
                $qb->andWhere($atividadeDepois(':exp_d10_ini', 'r10'))
                   ->setParameter('exp_d10_ini', $d10->modify('-1 second'));
                break;
            case 'findou':
                $qb->andWhere(sprintf(
                    '(EXISTS (SELECT 1 FROM %s t_fd WHERE t_fd.pasta = p AND t_fd.status <> :exp_concluida'
                    . ' AND t_fd.prazo >= :exp_d10 AND t_fd.prazo < :exp_hoje AND NOT %s)'
                    . ' OR EXISTS (SELECT 1 FROM %s z_fd WHERE z_fd.pasta = p AND z_fd.cumpridoEm IS NULL'
                    . ' AND z_fd.data >= :exp_d10 AND z_fd.data < :exp_hoje AND NOT %s))',
                    Tarefa::class,
                    str_replace(':exp_lim', 't_fd.prazo', $atividadeDepois(':exp_lim', 'fda')),
                    PastaPrazo::class,
                    str_replace(':exp_lim', 'z_fd.data', $atividadeDepois(':exp_lim', 'fdz')),
                ))
                   ->setParameter('exp_concluida', Tarefa::STATUS_CONCLUIDA)
                   ->setParameter('exp_d10', $d10)
                   ->setParameter('exp_hoje', $hoje);
                break;
            case 'prazo':
                $qb->andWhere(sprintf(
                    '(EXISTS (SELECT 1 FROM %s t_pz WHERE t_pz.pasta = p AND t_pz.status <> :exp_concluida AND t_pz.prazo >= :exp_hoje)'
                    . ' OR EXISTS (SELECT 1 FROM %s z_pz WHERE z_pz.pasta = p AND z_pz.cumpridoEm IS NULL AND z_pz.data >= :exp_hoje))',
                    Tarefa::class,
                    PastaPrazo::class,
                ))
                   ->setParameter('exp_concluida', Tarefa::STATUS_CONCLUIDA)
                   ->setParameter('exp_hoje', $hoje);
                break;
            case 'aud':
                $qb->andWhere(sprintf(
                    'EXISTS (SELECT 1 FROM %s z_au WHERE z_au.pasta = p AND z_au.tipo = :exp_audiencia AND z_au.cumpridoEm IS NULL AND z_au.data >= :exp_hoje)',
                    PastaPrazo::class,
                ))
                   ->setParameter('exp_audiencia', PastaPrazo::TIPO_AUDIENCIA)
                   ->setParameter('exp_hoje', $hoje);
                break;
            case 'protocolar':
                // Desenho 1.1 (`pzAll`): pasta ativa (nem arquivada nem cancelada), que não é "Administrativo sem
                // processo" e que não passou pela fase Protocolo do andamento. Sem andamento gravado, vale a fase
                // estimada: com processo vinculado a pasta já está em "Em andamento", depois de Protocolo.
                $qb->andWhere('p.situacao NOT IN (:exp_sit_fora)')
                   ->andWhere('p.administrativa = false')
                   ->andWhere(sprintf(
                       'NOT EXISTS (SELECT 1 FROM %1$s a_pr WHERE a_pr.pasta = p AND a_pr.via = :exp_via AND a_pr.passouProtocolo = true)'
                       . ' AND NOT (NOT EXISTS (SELECT 1 FROM %1$s a_pr2 WHERE a_pr2.pasta = p AND a_pr2.via = :exp_via)'
                       . ' AND EXISTS (SELECT 1 FROM %2$s pp_pr WHERE pp_pr.pasta = p))',
                       PastaAndamento::class,
                       PastaProcesso::class,
                   ))
                   ->setParameter('exp_sit_fora', [Pasta::SITUACAO_ARQUIVADA, Pasta::SITUACAO_CANCELADA])
                   ->setParameter('exp_via', PastaAndamento::VIA_JUDICIAL);
                break;
            case 'metas':
                $qb->andWhere(sprintf(
                    'EXISTS (SELECT 1 FROM %s t_mt WHERE t_mt.pasta = p AND t_mt.status <> :exp_concluida'
                    . ' AND NOT EXISTS (SELECT 1 FROM %s tm_mt WHERE tm_mt.tarefa = t_mt))',
                    Tarefa::class,
                    TarefaMensagem::class,
                ))
                   ->setParameter('exp_concluida', Tarefa::STATUS_CONCLUIDA);
                break;
        }
    }

    /**
     * Contagem de cada filtro do sistema da seção EXPEDIENTES do trilho (E12).
     *
     * @return array<string, int>
     */
    public function contagensDosFiltrosDoExpediente(Tenant $tenant, ?User $quemBusca = null): array
    {
        $contagens = [];
        foreach (self::FILTROS_EXPEDIENTE as $filtro) {
            $contagens[$filtro] = $this->countByFilters(['expediente' => $filtro], $tenant, $quemBusca);
        }

        return $contagens;
    }

    /**
     * Réus das pastas da página, para a coluna "Réu" da Lista (E12): partes de polo passivo dos processos
     * vinculados à pasta, o processo principal primeiro. O polo é texto livre (`ParteProcesso.papel`, em
     * maiúsculas), por isso o casamento por radical: PASSIV, RÉU/REU, REQUERID, EXECUTAD, RECLAMAD.
     *
     * @param int[] $pastaIds
     * @return array<int, string[]> pastaId => nomes sem repetição
     */
    public function reusPorPasta(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(pp.pasta) AS pasta_id', 'pa.nome')
            ->from(PastaProcesso::class, 'pp')
            ->join('pp.processo', 'pr')
            ->join(ParteProcesso::class, 'pa', 'WITH', 'pa.processo = pr')
            ->join('pp.pasta', 'pz')
            ->where('pz.tenant = :tenant')
            ->andWhere('pz.id IN (:ids)')
            ->andWhere(self::poloPassivo('pa'))
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $pastaIds)
            ->orderBy('pp.principal', 'DESC')
            ->addOrderBy('pp.id', 'ASC')
            ->addOrderBy('pa.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // E12h (decisão do dono): o réu cadastrado na pasta (aba Réu do Cadastro) manda; o Datajud só vale para a
        // pasta que não tem nenhum cadastrado.
        $reus = $this->getEntityManager()->getRepository(\App\Pasta\Entity\PastaReu::class)->nomesPorPasta($pastaIds, $tenant);
        $cadastrados = array_keys($reus);
        foreach ($linhas as $l) {
            $id   = (int) $l['pasta_id'];
            if (in_array($id, $cadastrados, true)) {
                continue;
            }
            $nome = trim((string) $l['nome']);
            if ($nome !== '' && !in_array($nome, $reus[$id] ?? [], true)) {
                $reus[$id][] = $nome;
            }
        }

        return $reus;
    }

    /**
     * Quantas pastas (sem lápide) cada cliente principal tem no escritório: o selo "Nx" ao lado do cliente
     * na Lista (E12). O cliente principal é o cadastro com CPF/CNPJ, então "mesmo nome e CPF" é ele.
     *
     * @param int[] $clienteIds
     * @return array<int, int> clienteId => total
     */
    public function totalDePastasPorClientePrincipal(array $clienteIds, Tenant $tenant): array
    {
        if ($clienteIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.clientePrincipal) AS cliente_id', 'COUNT(p.id) AS total')
            ->where('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('p.clientePrincipal IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $clienteIds)
            ->groupBy('p.clientePrincipal')
            ->getQuery()
            ->getArrayResult();

        $totais = [];
        foreach ($linhas as $l) {
            $totais[(int) $l['cliente_id']] = (int) $l['total'];
        }

        return $totais;
    }

    /**
     * Clientes já usados em pastas cujo nome contém o termo, com quantas pastas cada um tem: as sugestões e o
     * aviso "Cliente já cadastrado em N pasta(s)" da Nova Pasta (E12). Casa pelo identificador da pasta.
     *
     * @return array<string, int> nome => total
     */
    public function nomesDeClienteParecidos(string $termo, Tenant $tenant, int $limite = 8): array
    {
        $termo = mb_strtoupper(trim($termo));
        if (mb_strlen($termo) < 2) {
            return [];
        }

        $linhas = $this->createQueryBuilder('p')
            ->select('p.nomeCliente AS nome', 'COUNT(p.id) AS total')
            ->where('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('UPPER(p.nomeCliente) LIKE :termo')
            ->setParameter('tenant', $tenant)
            ->setParameter('termo', '%' . addcslashes($termo, '%_') . '%')
            ->groupBy('p.nomeCliente')
            ->orderBy('total', 'DESC')
            ->addOrderBy('p.nomeCliente', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getArrayResult();

        $nomes = [];
        foreach ($linhas as $l) {
            $nomes[(string) $l['nome']] = (int) $l['total'];
        }

        return $nomes;
    }

    /**
     * O cadastro de cliente de cada nome, quando as pastas com aquele nome apontam para UM cadastro só: é o
     * "A nova pasta será vinculada ao mesmo cadastro" da Nova Pasta (desenho 03, L276). Nome sem cadastro, ou
     * com dois cadastros diferentes (homônimos), fica de fora: a pasta nova não tem a quem se ligar sem
     * adivinhar. Casa pelo identificador da pasta em maiúsculas; o cadastro considerado é o principal.
     *
     * @param list<string> $nomes
     *
     * @return array<string, int> nome em maiúsculas => id do cliente
     */
    public function cadastroUnicoPorNomeDeCliente(array $nomes, Tenant $tenant): array
    {
        $nomes = array_values(array_unique(array_filter(array_map(
            static fn (string $n): string => mb_strtoupper(trim($n)),
            $nomes,
        ), static fn (string $n): bool => $n !== '')));
        if ($nomes === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('p')
            ->select('UPPER(p.nomeCliente) AS nome', 'MIN(c.id) AS cliente')
            ->join('p.clientePrincipal', 'c')
            ->where('p.tenant = :tenant')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('UPPER(p.nomeCliente) IN (:nomes)')
            ->setParameter('tenant', $tenant)
            ->setParameter('nomes', $nomes)
            ->groupBy('nome')
            ->having('COUNT(DISTINCT c.id) = 1')
            ->getQuery()
            ->getArrayResult();

        $cadastros = [];
        foreach ($linhas as $l) {
            $cadastros[(string) $l['nome']] = (int) $l['cliente'];
        }

        return $cadastros;
    }

    /**
     * Converte um valor 'Y-m-d' (input date do HTML) em DateTimeImmutable, ou null
     * se vazio/ inválido. Para a borda final ('data_ate') usa o fim do dia (23:59:59)
     * para o intervalo ser inclusivo.
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

    /**
     * As pastas vizinhas no acervo — o que as setas ‹ › do cabeçalho percorrem.
     *
     * A ordem é a MESMA da lista padrão do Expediente (`aplicarOrdenacao`, ramo
     * `default`): número da pasta decrescente, com o `id` desempatando. Por isso
     * "anterior" é a linha de CIMA na lista (número maior) e "próxima" a de baixo
     * (número menor) — quem percorre o acervo com as setas vê a mesma sequência
     * que veria descendo a lista.
     *
     * O desempate por `id` não é enfeite: há NUP repetido em produção (no dev,
     * três pastas com o número 1214). Sem ele, duas pastas de mesmo número
     * apontariam uma para a outra e a navegação entraria em looping.
     *
     * Entram TODAS as pastas do escritório — ativas, arquivadas e a lápide da
     * excluída (decisão do dono, 31/08): é o mesmo conjunto que a lista mostra.
     *
     * Custo medido no acervo do dev (1.055 pastas): ~2,6 ms por lado, varredura sequencial
     * mais `top-N heapsort`. A chave é uma expressão sobre o NUP e nenhum índice a atende;
     * num acervo desta ordem de grandeza isso é ruído perto do resto da tela. Se um dia o
     * número de pastas por escritório mudar de patamar, o conserto é um índice funcional
     * sobre o prefixo — não uma reescrita da consulta.
     *
     * @return array{anterior: ?array{id: int, nup: ?string}, proxima: ?array{id: int, nup: ?string}}
     */
    public function vizinhasNoAcervo(Pasta $pasta): array
    {
        $tenant = $pasta->getTenant();
        $id     = $pasta->getId();

        // Pasta sem tenant ou ainda não persistida não tem vizinhança definível, e devolver as
        // duas pontas nulas desliga as duas setas — que é o comportamento honesto.
        if ($tenant === null || $id === null) {
            return ['anterior' => null, 'proxima' => null];
        }

        $nup = (string) $pasta->getNup();

        return [
            'anterior' => $this->vizinha($tenant, $nup, $id, 'ASC'),
            'proxima'  => $this->vizinha($tenant, $nup, $id, 'DESC'),
        ];
    }

    /**
     * Um lado da vizinhança, por chave composta (prefixo numérico do NUP, NUP cru, id).
     *
     * `DESC` procura a maior chave MENOR que a atual (a próxima, linha de baixo); `ASC` procura a
     * menor chave MAIOR (a anterior, linha de cima) — a comparação e a ordenação viram juntas,
     * senão a consulta devolveria a ponta do acervo em vez do vizinho.
     *
     * @return ?array{id: int, nup: ?string, nomeCliente: ?string}
     */
    private function vizinha(Tenant $tenant, string $nup, int $id, string $direcao): ?array
    {
        $qb = $this->createQueryBuilder('p');

        $comparar = $direcao === 'ASC'
            ? static fn (string $campo, string $parametro) => $qb->expr()->gt($campo, $parametro)
            : static fn (string $campo, string $parametro) => $qb->expr()->lt($campo, $parametro);

        // Os mesmos três níveis do ORDER BY da lista, com os NULLs neutralizados: NUP sem prefixo
        // numérico vira -1 e cai no fim da ordem decrescente, exatamente onde o `CASE ... IS NULL`
        // da listagem o põe. Sem isso a comparação com NULL não casaria com nada e a pasta de NUP
        // não numérico ficaria sem vizinhos dos dois lados.
        // `CASE WHEN` e não `COALESCE`: o parser do DQL aceita COALESCE no WHERE mas o recusa no
        // ORDER BY ("Expected known function, got 'COALESCE'"), e as duas cláusulas têm de usar
        // exatamente a mesma expressão — é a mesma chave dos dois lados.
        $prefixo = self::EXPR_PREFIXO_NUP;
        $nupCru  = self::EXPR_NUP_CRU;

        $linha = $qb
            ->select('p.id', 'p.nup', 'p.nomeCliente')
            ->andWhere('p.tenant = :tenant')
            // `orX`/`andX` (e não uma string com OR solto) para o parêntese existir de fato: sem ele
            // o OR escaparia do filtro de tenant e a seta atravessaria escritórios.
            ->andWhere($qb->expr()->orX(
                $comparar($prefixo, ':prefixo'),
                $qb->expr()->andX(
                    $qb->expr()->eq($prefixo, ':prefixo'),
                    $comparar($nupCru, ':nup'),
                ),
                $qb->expr()->andX(
                    $qb->expr()->eq($prefixo, ':prefixo'),
                    $qb->expr()->eq($nupCru, ':nup'),
                    $comparar('p.id', ':id'),
                ),
            ))
            ->setParameter('tenant', $tenant)
            ->setParameter('prefixo', self::prefixoNumerico($nup))
            ->setParameter('nup', $nup)
            ->setParameter('id', $id)
            ->orderBy($prefixo, $direcao)
            ->addOrderBy($nupCru, $direcao)
            ->addOrderBy('p.id', $direcao)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);

        if ($linha === null) {
            return null;
        }

        return ['id' => (int) $linha['id'], 'nup' => $linha['nup'], 'nomeCliente' => $linha['nomeCliente']];
    }

    /**
     * O "N de M" entre as setas ‹ › do cabeçalho: em que linha da lista do Expediente a
     * pasta está e quantas linhas a lista tem.
     *
     * Mesmo conjunto e mesma chave das setas (`vizinhasNoAcervo` / `vizinha`): todas as
     * pastas do escritório — ativas, arquivadas e lápides —, ordenadas por (prefixo numérico
     * do NUP, NUP cru, id) DECRESCENTE. A posição é quantas pastas têm chave MAIOR (as que
     * ficam acima na lista) + 1; o total é o COUNT do mesmo conjunto. Se as expressões
     * divergissem das de `vizinha()`, o número diria "3 de 7" com a seta ‹ levando a uma
     * pasta que não é a 2ª — por isso as duas leem as mesmas constantes da classe.
     *
     * Devolve nulo quando a pasta não tem tenant ou ainda não foi persistida: não há
     * acervo em que ela ocupe uma linha.
     *
     * @return ?array{posicao: int, total: int}
     */
    public function posicaoNoAcervo(Pasta $pasta): ?array
    {
        $tenant = $pasta->getTenant();
        $id     = $pasta->getId();

        if ($tenant === null || $id === null) {
            return null;
        }

        $nup = (string) $pasta->getNup();

        // As MESMAS expressões de `vizinha()` (constantes da classe) — ver o porquê do CASE lá.
        $prefixo = self::EXPR_PREFIXO_NUP;
        $nupCru  = self::EXPR_NUP_CRU;

        $qb = $this->createQueryBuilder('p');

        $acima = (int) $qb
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenant')
            // `orX`/`andX` para o parêntese existir: um OR solto escaparia do filtro de tenant.
            ->andWhere($qb->expr()->orX(
                $qb->expr()->gt($prefixo, ':prefixo'),
                $qb->expr()->andX(
                    $qb->expr()->eq($prefixo, ':prefixo'),
                    $qb->expr()->gt($nupCru, ':nup'),
                ),
                $qb->expr()->andX(
                    $qb->expr()->eq($prefixo, ':prefixo'),
                    $qb->expr()->eq($nupCru, ':nup'),
                    $qb->expr()->gt('p.id', ':id'),
                ),
            ))
            ->setParameter('tenant', $tenant)
            ->setParameter('prefixo', self::prefixoNumerico($nup))
            ->setParameter('nup', $nup)
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();

        $total = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return ['posicao' => $acima + 1, 'total' => $total];
    }

    /**
     * Espelho em PHP do `CAST_INT_PREFIXO` do banco: o prefixo numérico do NUP ('10' → 10,
     * '10A' → 10), ou -1 quando não há prefixo. As duas leituras precisam concordar — é a
     * mesma chave dos dois lados da comparação. O limite de 18 dígitos é o do BIGINT, o
     * mesmo da função DQL.
     */
    private static function prefixoNumerico(string $nup): int
    {
        return preg_match('/^[0-9]{1,18}/', $nup, $casou) === 1 ? (int) $casou[0] : -1;
    }

    /**
     * Números dos processos vinculados à pasta, numa consulta só (a timeline inteligente cruza
     * esses números com o Push). Andar por `getPastaProcessos()` faria uma consulta por processo.
     * Presa ao escritório pelos dois lados: a pasta E o processo são do tenant informado.
     *
     * @return list<string>
     */
    public function numerosDosProcessosVinculados(Pasta $pasta, Tenant $tenant): array
    {
        $linhas = $this->getEntityManager()->createQueryBuilder()
            ->select('proc.numeroProcesso AS numero')
            ->from(PastaProcesso::class, 'pp')
            ->innerJoin('pp.pasta', 'pa')
            ->innerJoin('pp.processo', 'proc')
            ->andWhere('pp.pasta = :pasta')
            ->andWhere('pa.tenant = :tenant')
            ->andWhere('proc.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(
            array_map(static fn (array $l): string => (string) $l['numero'], $linhas),
            static fn (string $n): bool => $n !== '',
        ));
    }

    /**
     * Os vínculos pasta-processo dos processos informados, só das pastas VIVAS (sem lápide) do
     * escritório, com a pasta junto: quem recebe o "Avisar quando chegar" do Push (spec
     * push-avisar-quando-chegar §3). Presa ao escritório pelos dois lados e com o tenant explícito
     * (roda no CLI, sem TenantFilter). Carrega o vínculo, e não a pasta com a coleção filtrada, para
     * não deixar `getPastaProcessos()` parcial no EntityManager.
     *
     * @param list<int> $processoIds
     *
     * @return list<PastaProcesso>
     */
    public function listarVinculosDePastasVivasDosProcessos(Tenant $tenant, array $processoIds): array
    {
        if ($processoIds === []) {
            return [];
        }

        return $this->getEntityManager()->createQueryBuilder()
            ->select('pp', 'pa')
            ->from(PastaProcesso::class, 'pp')
            ->innerJoin('pp.pasta', 'pa')
            ->innerJoin('pp.processo', 'proc')
            ->andWhere('pa.tenant = :tenant')
            ->andWhere('proc.tenant = :tenant')
            ->andWhere('pa.excluidaEm IS NULL')
            ->andWhere('proc.id IN (:processos)')
            ->setParameter('tenant', $tenant)
            ->setParameter('processos', array_values(array_unique($processoIds)))
            ->orderBy('pa.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * "Média por CPF" da aba Financeiro: o ticket médio do cliente — a soma do
     * valor da causa de todas as pastas dele dividida pela quantidade dessas
     * pastas.
     *
     * Só entram as pastas com valor preenchido: pasta sem valor não é R$ 0,00 e
     * não pode puxar a média para baixo. Pasta arquivada entra normalmente —
     * arquivar não apaga o histórico do cliente.
     *
     * Devolve nulo quando o cliente não tem nenhuma pasta com valor: aí a tela
     * mostra travessão, porque R$ 0,00 seria um número inventado.
     *
     * O float aparece só no arredondamento final de um número que já é derivado
     * (uma média nunca é exata em centavos); os valores somados vêm e ficam em
     * decimal no banco.
     */
    public function mediaValorCausaPorCliente(Cliente $cliente, Tenant $tenant): ?string
    {
        $media = $this->createQueryBuilder('p')
            ->select('AVG(p.valorCausa)')
            ->innerJoin('p.clientes', 'cli_media')
            ->andWhere('cli_media = :cliente')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.valorCausa IS NOT NULL')
            ->setParameter('cliente', $cliente)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        if ($media === null) {
            return null;
        }

        return number_format((float) $media, 2, '.', '');
    }

    /**
     * Ordena pelo réu que a coluna MOSTRA (`reusPorPasta`, o primeiro da lista, antes do " e outros"), não pelo menor
     * em ordem alfabética: senão a lista ordena por um nome que o usuário não vê. O cadastrado manda (o primeiro por
     * `ordem, id`); sem nenhum cadastrado, o primeiro polo passivo do Datajud (processo principal primeiro, depois
     * `pp.id` e `pa.id`), sem nome vazio. Cada JOIN deixa passar só esse primeiro: as demais linhas do produto
     * cartesiano vêm nulas e o MIN fica com o único nome que sobra.
     */
    private function ordenarPeloReu(QueryBuilder $qb, string $dir): void
    {
        $reu = \App\Pasta\Entity\PastaReu::class;
        $qb->leftJoin($reu, 'reu_ord', 'WITH', 'reu_ord.pasta = p AND NOT EXISTS (SELECT 1 FROM ' . $reu . ' reu_ant'
                . ' WHERE reu_ant.pasta = p AND (reu_ant.ordem < reu_ord.ordem OR (reu_ant.ordem = reu_ord.ordem AND reu_ant.id < reu_ord.id)))')
           ->leftJoin('p.pastaProcessos', 'ppr_ord')
           ->leftJoin('ppr_ord.processo', 'prr_ord')
           ->leftJoin(ParteProcesso::class, 'par_ord', 'WITH', 'par_ord.processo = prr_ord AND ' . self::poloPassivo('par_ord')
                . " AND TRIM(par_ord.nome) <> '' AND NOT EXISTS (" . self::parteAnterior() . ')')
           ->orderBy('MIN(LOWER(COALESCE(reu_ord.nome, par_ord.nome)))', $dir);
    }

    /** Outra parte do polo passivo da mesma pasta que vem antes de `par_ord` na ordem da coluna Réu. */
    private static function parteAnterior(): string
    {
        return 'SELECT 1 FROM ' . PastaProcesso::class . ' ppr_ant JOIN ppr_ant.processo prr_ant JOIN ' . ParteProcesso::class
            . ' par_ant WITH par_ant.processo = prr_ant WHERE ppr_ant.pasta = p AND ' . self::poloPassivo('par_ant')
            . " AND TRIM(par_ant.nome) <> '' AND ("
            . '(ppr_ant.principal = true AND ppr_ord.principal = false)'
            . ' OR (ppr_ant.principal = ppr_ord.principal AND ppr_ant.id < ppr_ord.id)'
            . ' OR (ppr_ant.id = ppr_ord.id AND par_ant.id < par_ord.id))';
    }

    /**
     * Ordena a listagem no banco (antes da paginação) pela coluna clicada no cabeçalho.
     *
     * O QueryBuilder já vem com GROUP BY p.id, então colunas de coleção (cliente,
     * marcador) e de relação (responsável, ação/processo) são ordenadas por um valor
     * representativo agregado (MIN) — cada pasta continua sendo uma única linha.
     * Coluna/direção desconhecidas caem no padrão (NUP numérico decrescente).
     *
     * Com `$favoritosDe`, as pastas que ESSE usuário fixou nos favoritos sobem para o topo
     * (desenho 1.2.3, `prefPasta('fav')`) e, dentro de cada grupo, vale a ordem escolhida. Sem
     * favorito nenhum a ordem é idêntica à de sem o parâmetro: o critério novo empata em todas as
     * linhas. O favorito de outro usuário não entra no JOIN.
     */
    private function aplicarOrdenacao(QueryBuilder $qb, string $ordenar, string $direcao, ?User $favoritosDe = null): void
    {
        $dir = strtoupper($direcao) === 'ASC' ? 'ASC' : 'DESC';

        switch ($ordenar) {
            case 'nup':
                $qb->orderBy('CASE WHEN CAST_INT_PREFIXO(p.nup) IS NULL THEN 1 ELSE 0 END', 'ASC')
                   ->addOrderBy('CAST_INT_PREFIXO(p.nup)', $dir)
                   ->addOrderBy('p.nup', $dir);
                break;

            case 'situacao':
                $qb->orderBy('p.situacao', $dir);
                break;

            case 'prioridade':
                $qb->orderBy("CASE p.prioridade WHEN 'urgente' THEN 3 WHEN 'prioridade' THEN 2 ELSE 1 END", $dir);
                break;

            case 'responsavel':
                $qb->leftJoin('p.responsavel', 'r_ord')
                   ->orderBy('MIN(LOWER(r_ord.fullName))', $dir);
                break;

            case 'cliente':
                $qb->leftJoin('p.clientePrincipal', 'cli_ord')
                   ->leftJoin(ClientePF::class, 'cpf_ord', 'WITH', 'cpf_ord.id = cli_ord.id')
                   ->leftJoin(ClientePJ::class, 'cpj_ord', 'WITH', 'cpj_ord.id = cli_ord.id')
                   // Caminho da cobrança, só neste ramo: é o que permite ordenar pelo
                   // identificador DERIVADO das pastas judicializadas.
                   ->leftJoin(CasoCobranca::class, 'cc_ord', 'WITH', 'cc_ord.pastaJudicial = p')
                   ->leftJoin('cc_ord.objeto', 'obj_ord')
                   ->leftJoin('obj_ord.carteira', 'cart_ord')
                   ->leftJoin('cart_ord.cliente', 'clc_ord')
                   ->leftJoin(ClientePJ::class, 'pjc_ord', 'WITH', 'pjc_ord.id = clc_ord.id')
                   ->leftJoin(ClientePF::class, 'pfc_ord', 'WITH', 'pfc_ord.id = clc_ord.id')
                   // A precedência espelha a da TELA (`identificador_pasta`): o cliente da carteira
                   // (pasta judicializada), depois o cliente principal da pasta, por último o nome
                   // gravado, que é só reserva. Se divergirem, a lista ordena por um nome que o
                   // usuário não vê na coluna. A queda dentro do cliente (fantasia, senão razão
                   // social ou nome completo) é a do `ComporNomeDaPastaJudicial`.
                   ->orderBy(
                       'MIN(LOWER(COALESCE('
                       . 'NULLIF(TRIM(pjc_ord.nomeFantasia), \'\'), pjc_ord.razaoSocial, pfc_ord.nomeCompleto, '
                       . 'cpf_ord.nomeCompleto, cpj_ord.razaoSocial, p.nomeCliente)))',
                       $dir,
                   );
                break;

            case 'reu':
                $this->ordenarPeloReu($qb, $dir);
                break;

            case 'acao':
                $qb->leftJoin('p.pastaProcessos', 'pp_ord', 'WITH', 'pp_ord.principal = true')
                   ->leftJoin('pp_ord.processo', 'proc_ord')
                   ->orderBy('MIN(LOWER(COALESCE(proc_ord.classeProcessual, p.nomeAcao)))', $dir);
                break;

            case 'marcadores':
                $qb->leftJoin('p.marcadores', 'marc_ord')
                   ->orderBy('MIN(LOWER(marc_ord.nome))', $dir);
                break;

            default:
                $qb->orderBy('CASE WHEN CAST_INT_PREFIXO(p.nup) IS NULL THEN 1 ELSE 0 END', 'ASC')
                   ->addOrderBy('CAST_INT_PREFIXO(p.nup)', 'DESC')
                   ->addOrderBy('p.nup', 'DESC');
                break;
        }

        $qb->addOrderBy('p.id', 'DESC');

        if ($favoritosDe === null) {
            return;
        }

        // O UNIQUE (user_id, pasta_id) garante no máximo uma linha de favorito por pasta, mas
        // os JOINs de coleção dos ramos acima (clientes, marcadores) multiplicam as linhas antes
        // do GROUP BY — por isso MAX de um 0/1, e não COUNT. O critério entra NA FRENTE dos que
        // o ramo escolheu, que seguem intactos como desempate.
        $criteriosDoRamo = $qb->getDQLPart('orderBy');
        $qb->leftJoin(
            PastaFavorita::class,
            'fav_ord',
            'WITH',
            'fav_ord.pasta = p AND fav_ord.usuario = :fav_ord_usuario AND fav_ord.tenant = p.tenant',
        )
            ->setParameter('fav_ord_usuario', $favoritosDe)
            ->orderBy('MAX(CASE WHEN fav_ord.id IS NULL THEN 0 ELSE 1 END)', 'DESC');

        foreach ($criteriosDoRamo as $criterio) {
            $qb->addOrderBy($criterio);
        }
    }

    /**
     * Busca rápida da pasta (Ctrl + K, desenho 02 EXPEDIENTES L405-421): as ocorrências do termo no
     * escritório, já separadas por grupo, das pastas mais recentes para as mais antigas.
     *
     * - Pasta: como o desenho (L6470-6472), casa pelo número da pasta ou por "Pasta <número>". A ação
     *   NÃO entra na busca: é só o subtítulo.
     * - Cliente: casa pelo nome (PF e PJ), pelo CPF/CNPJ como gravado e, com 3 dígitos ou mais no
     *   termo, pelos DÍGITOS do CPF/CNPJ (o banco guarda o CPF ora mascarado, ora cru: ver
     *   `DocumentoBrExtension`). Pasta sem cliente cadastrado casa pelo nome escrito na pasta.
     * - Processo: TODOS os processos vinculados (não só o principal), pelo número como gravado ou
     *   pelos dígitos (3 ou mais).
     * - Termo vazio: só as pastas, as mais recentes.
     *
     * `$somenteIds`: null = sem restrição; lista = só essas pastas (o escopo da pessoa é decidido pelo
     * chamador e aplicado AQUI, antes do LIMIT, para o corte valer sobre o que ela alcança).
     *
     * `$ocultas`: os ids das pastas OCULTAS pelo cadeado para quem busca (`AcessoAPasta::idsOcultosPara`,
     * spec pasta-cadeado D1). Elas continuam no resultado, com `restrito`, mas casam SÓ pelo número da
     * pasta e pelo cliente (nome e CPF/CNPJ): o grupo Processo não as traz (nem pelo número do processo,
     * que viraria oráculo) e a ação delas nem sai do banco (`nome_acao` nulo), para não virar subtítulo.
     *
     * Sem acento e sem caixa (`unaccent` + `lower`). SQL nativo porque a comparação por dígitos
     * precisa do `regexp_replace` do PostgreSQL, que o DQL não tem; o filtro de tenant do Doctrine
     * NÃO vale aqui, por isso o `tenant_id` explícito em pasta, cliente (inclusive o principal) e
     * processo. Pasta excluída (lápide) fica de fora.
     *
     * @param list<int>|null $somenteIds
     * @param list<int>      $ocultas
     *
     * @return list<OcorrenciaDaBuscaRapida>
     */
    public function buscarParaBuscaRapida(string $termo, Tenant $tenant, int $limite = 8, ?array $somenteIds = null, array $ocultas = []): array
    {
        if ($somenteIds === []) {
            return [];
        }

        $termo = mb_strtolower(trim($termo));
        // `IN` com lista vazia vira `IN (NULL)` no DBAL (nulo, não falso): sem oculta, a coluna é `false` fixo.
        $oculta = $ocultas === [] ? 'false' : 'p.id IN (:ocultas)';
        $base   = sprintf(self::SQL_BUSCA_RAPIDA_BASE, $oculta, $oculta)
            . ($somenteIds !== null ? ' AND p.id IN (:ids)' : '');
        $ocorrencias = $termo === '' ? self::SQL_BUSCA_RAPIDA_SO_PASTAS : self::SQL_BUSCA_RAPIDA_OCORRENCIAS;
        $sql = 'WITH base AS (' . $base . '), ocorrencias AS (' . $ocorrencias . ')'
            . ' SELECT o.*, b.nup, b.nome_acao, b.nome_cliente, b.oculta, b.nome_principal'
            . ' FROM ocorrencias o JOIN base b ON b.id = o.pasta_id'
            . ' ORDER BY o.pasta_id DESC, o.ordem ASC, o.principal DESC, o.nome ASC, o.numero ASC'
            . ' LIMIT :limite';

        // Parâmetro nomeado que a consulta não usa (termo vazio, sem restrição) é ignorado: o DBAL
        // converte os nomeados em posicionais pelo que aparece no SQL.
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            self::parametrosDaBuscaRapida($termo, $tenant, $limite, $somenteIds, $ocultas),
            [
                'limite'  => ParameterType::INTEGER,
                'tenant'  => ParameterType::INTEGER,
                'ids'     => ArrayParameterType::INTEGER,
                'ocultas' => ArrayParameterType::INTEGER,
            ],
        );

        return array_map(self::ocorrenciaDaLinha(...), $linhas);
    }

    /**
     * @param list<int>|null $somenteIds
     * @param list<int>      $ocultas
     *
     * @return array<string, mixed>
     */
    private static function parametrosDaBuscaRapida(string $termo, Tenant $tenant, int $limite, ?array $somenteIds, array $ocultas): array
    {
        $digitos = preg_replace('/\D/', '', $termo) ?? '';
        $digitos = \strlen($digitos) >= 3 ? $digitos : '';

        return [
            'tenant'      => $tenant->getId(),
            // `%`, `_` e `\` digitados valem como texto, não como curinga do LIKE.
            'termo'       => '%' . addcslashes($termo, '%_\\') . '%',
            'digitos'     => $digitos,
            'digitosLike' => '%' . $digitos . '%',
            'limite'      => $limite,
            'ids'         => $somenteIds ?? [],
            'ocultas'     => $ocultas,
        ];
    }

    /**
     * As pastas vivas do escritório, com o nome do cliente principal para o subtítulo. Os dois `%s` são
     * o critério de "oculta para quem busca": a coluna `oculta` e a ação apagada dessas pastas (D1).
     */
    private const SQL_BUSCA_RAPIDA_BASE = 'SELECT p.id, p.nup, p.nome_cliente,'
        . ' (%s) AS oculta,'
        . ' CASE WHEN %s THEN NULL ELSE p.nome_acao END AS nome_acao,'
        . ' COALESCE(cpf_pr.nome_completo, cpj_pr.razao_social) AS nome_principal'
        . ' FROM pasta p'
        . ' LEFT JOIN cliente c_pr ON c_pr.id = p.cliente_principal_id AND c_pr.tenant_id = :tenant'
        . ' LEFT JOIN cliente_pf cpf_pr ON cpf_pr.id = c_pr.id'
        . ' LEFT JOIN cliente_pj cpj_pr ON cpj_pr.id = c_pr.id'
        . ' WHERE p.tenant_id = :tenant AND p.excluida_em IS NULL';

    private const SQL_BUSCA_RAPIDA_SO_PASTAS = "SELECT b.id AS pasta_id, 1 AS ordem, 'Pasta' AS grupo, false AS principal,"
        . ' NULL::text AS nome, NULL::text AS documento, NULL::text AS numero, NULL::text AS classe'
        . ' FROM base b';

    /** O grupo Processo só olha as pastas que a pessoa vê (`NOT b.oculta`, D1). */
    private const SQL_BUSCA_RAPIDA_OCORRENCIAS = "SELECT b.id AS pasta_id, 1 AS ordem, 'Pasta' AS grupo, false AS principal,"
        . ' NULL::text AS nome, NULL::text AS documento, NULL::text AS numero, NULL::text AS classe'
        . ' FROM base b'
        . " WHERE unaccent(lower(b.nup)) LIKE unaccent(:termo) OR unaccent(lower('pasta ' || b.nup)) LIKE unaccent(:termo)"
        . " UNION ALL SELECT b.id, 2, 'Cliente', false, COALESCE(cpf.nome_completo, cpj.razao_social), COALESCE(cpf.cpf, cpj.cnpj), NULL, NULL"
        . ' FROM base b JOIN pasta_cliente pc ON pc.pasta_id = b.id'
        . ' JOIN cliente c ON c.id = pc.cliente_id AND c.tenant_id = :tenant'
        . ' LEFT JOIN cliente_pf cpf ON cpf.id = c.id LEFT JOIN cliente_pj cpj ON cpj.id = c.id'
        . " WHERE unaccent(lower(COALESCE(cpf.nome_completo, cpj.razao_social, ''))) LIKE unaccent(:termo)"
        . " OR lower(COALESCE(cpf.cpf, cpj.cnpj, '')) LIKE :termo"
        . " OR (:digitos <> '' AND regexp_replace(COALESCE(cpf.cpf, cpj.cnpj, ''), '\\D', '', 'g') LIKE :digitosLike)"
        . " UNION ALL SELECT b.id, 2, 'Cliente', false, b.nome_cliente, NULL, NULL, NULL FROM base b"
        . ' WHERE b.nome_cliente IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pasta_cliente pc2 WHERE pc2.pasta_id = b.id)'
        . ' AND unaccent(lower(b.nome_cliente)) LIKE unaccent(:termo)'
        . " UNION ALL SELECT b.id, 3, 'Processo', pp.principal, NULL, NULL, pr.numero_processo, pr.classe_processual"
        . ' FROM base b JOIN pasta_processo pp ON pp.pasta_id = b.id'
        . ' JOIN processo pr ON pr.id = pp.processo_id AND pr.tenant_id = :tenant'
        . ' WHERE NOT b.oculta AND (lower(pr.numero_processo) LIKE :termo'
        . " OR (:digitos <> '' AND regexp_replace(pr.numero_processo, '\\D', '', 'g') LIKE :digitosLike))";

    /** @param array<string, mixed> $linha */
    private static function ocorrenciaDaLinha(array $linha): OcorrenciaDaBuscaRapida
    {
        $texto = static fn (string $chave): ?string => isset($linha[$chave]) && trim((string) $linha[$chave]) !== ''
            ? (string) $linha[$chave]
            : null;

        return new OcorrenciaDaBuscaRapida(
            pastaId: (int) $linha['pasta_id'],
            grupo: (string) $linha['grupo'],
            nup: (string) $linha['nup'],
            restrito: (bool) $linha['oculta'],
            nomeAcao: $texto('nome_acao'),
            nomeClienteDaPasta: $texto('nome_cliente'),
            nomeDoClientePrincipal: $texto('nome_principal'),
            nome: $texto('nome'),
            documento: $texto('documento'),
            numeroProcesso: $texto('numero'),
            classeProcessual: $texto('classe'),
        );
    }

    /**
     * As pastas ATIVAS e não excluídas do escritório para o relatório de pastas sem processo, com o
     * responsável e os processos vinculados já carregados (o processo principal sai sem N+1).
     * `$ids` nulo = todas; lista = só essas (o que o leitor pode ver).
     *
     * @param list<int>|null $ids
     *
     * @return list<Pasta>
     */
    public function ativasParaAnaliseSemProcesso(Tenant $tenant, ?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->addSelect('r', 'pp', 'pr')
            ->leftJoin('p.responsavel', 'r')
            ->leftJoin('p.pastaProcessos', 'pp')
            ->leftJoin('pp.processo', 'pr')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.situacao = :ativa')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('ativa', Pasta::SITUACAO_ATIVA)
            ->orderBy('p.id', 'ASC');

        if ($ids !== null) {
            $qb->andWhere('p.id IN (:ids)')->setParameter('ids', $ids);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Outras pastas do escritório com o mesmo `contrato` ou a mesma `unidade` (Controladoria da pasta, fatia B): igual
     * sem diferenciar caixa nem espaços das pontas. Fora da lixeira, sem as `$ocultas` (o cadeado: o alerta não pode
     * entregar o número de uma pasta que a pessoa não vê).
     *
     * @param string    $campo   'contrato' ou 'unidade'
     * @param list<int> $ocultas
     *
     * @return list<array{id: int, nup: string}>
     */
    public function outrasComOMesmo(string $campo, Pasta $pasta, Tenant $tenant, string $valor, array $ocultas): array
    {
        if (!in_array($campo, ['contrato', 'unidade'], true)) {
            throw new \InvalidArgumentException('Campo não comparável: ' . $campo);
        }
        $qb = $this->createQueryBuilder('p')
            ->select('p.id AS id', 'p.nup AS nup')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.id <> :pasta')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('LOWER(TRIM(p.' . $campo . ')) = :valor')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta->getId())
            ->setParameter('valor', mb_strtolower(trim($valor)))
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(5);
        if ($ocultas !== []) {
            $qb->andWhere($qb->expr()->notIn('p.id', ':ocultas'))->setParameter('ocultas', $ocultas);
        }

        return array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'nup' => (string) $l['nup']], $qb->getQuery()->getArrayResult());
    }
}
