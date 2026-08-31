<?php

namespace App\Pasta\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Pasta\DTO\PastaVinculadaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Processo\Entity\Processo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pasta>
 */
class PastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pasta::class);
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
        string $direcao = 'desc'
    ): array {
        $qb = $this->buildQbByFilters($filters, $tenant)->groupBy('p.id');
        $this->aplicarOrdenacao($qb, $ordenar, $direcao);

        return $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $filters
     */
    public function countByFilters(array $filters, Tenant $tenant): int
    {
        return (int) $this->buildQbByFilters($filters, $tenant)
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
     * Pastas do escritório que têm ESTE processo vinculado, projetadas para a tela do
     * processo (`processo_show`). É o espelho da aba "Processos vinculados" da pasta.
     *
     * Escopa por tenant EXPLICITAMENTE: `PastaProcesso` não é TenantAware — quem carrega o
     * escritório é a Pasta dona do vínculo. Pasta excluída continua na lista, marcada como
     * lápide, pela mesma razão das listagens do acervo: sumir com ela esconde o vínculo que
     * de fato existe.
     *
     * @return PastaVinculadaOutput[]
     */
    public function listarVinculadasAoProcesso(Processo $processo, Tenant $tenant): array
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

        return array_map(
            static fn (array $linha): PastaVinculadaOutput => PastaVinculadaOutput::fromRow($linha),
            $linhas,
        );
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
        string $direcao = 'desc'
    ): array {
        $qb = $this->buildQbPorMarcador([], $marcador, $tenant)->groupBy('p.id');
        $this->aplicarOrdenacao($qb, $ordenar, $direcao);

        return $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countPorMarcador(Marcador $marcador, Tenant $tenant): int
    {
        return (int) $this->buildQbPorMarcador([], $marcador, $tenant)
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
        string $direcao = 'desc'
    ): array {
        $qb = $this->buildQbPorMarcador($filters, $marcador, $tenant)->groupBy('p.id');
        $this->aplicarOrdenacao($qb, $ordenar, $direcao);

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
        Tenant $tenant
    ): int {
        return (int) $this->buildQbPorMarcador($filters, $marcador, $tenant)
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
    public function countPorMarcadores(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('m.id AS marcador_id, COUNT(p.id) AS total')
            ->join('p.marcadores', 'm')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('m.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['marcador_id']] = (int) $row['total'];
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
     */
    public function countUrgentes(Tenant $tenant, array $filtros = []): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.prioridade = :prioridade')
            ->setParameter('tenant', $tenant)
            ->setParameter('prioridade', PrioridadePasta::Urgente);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @return array<int, int>  userId => total (todas as situações)
     */
    public function countPorResponsavel(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('r.id AS responsavel_id, COUNT(p.id) AS total')
            ->join('p.responsavel', 'r')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('r.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @return array<int, int>  userId => total (só ativas)
     */
    public function countAtivasPorResponsavel(Tenant $tenant, array $filtros = []): array
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
     * @return array<int, int>  userId => total de pastas criadas
     */
    public function countCriadasPorCriador(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('c.id AS criador_id, COUNT(p.id) AS total')
            ->join('p.criadoPor', 'c')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->groupBy('c.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['criador_id']] = (int) $row['total'];
        }

        return $counts;
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
    private function buildQbByFilters(array $filters, Tenant $tenant): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $this->aplicarFiltrosPasta($qb, $filters);

        return $qb;
    }

    /**
     * @param array<string, string> $filters
     */
    private function buildQbPorMarcador(
        array $filters,
        Marcador $marcador,
        Tenant $tenant
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('p')
            ->join('p.marcadores', 'm')
            ->where('m = :marcador')
            ->setParameter('marcador', $marcador)
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $this->aplicarFiltrosPasta($qb, $filters);

        return $qb;
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
    private function aplicarFiltrosPasta(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['nup'])) {
            $qb->andWhere('p.nup LIKE :nup')
               ->setParameter('nup', '%' . $filters['nup'] . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('p.situacao = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['responsavel'])) {
            $qb->join('p.responsavel', 'r')
               ->andWhere('r.id = :responsavel')
               ->setParameter('responsavel', (int) $filters['responsavel']);
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
                )
            )->setParameter('cliente', '%' . mb_strtolower($filters['cliente']) . '%');
        }

        if (!empty($filters['acao'])) {
            $qb->andWhere('p.nomeAcao LIKE :acao OR proc.classeProcessual LIKE :acao')
               ->setParameter('acao', '%' . $filters['acao'] . '%');
        }

        if (!empty($filters['busca'])) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(p.nup)) LIKE UNACCENT(:busca)',
                    'UNACCENT(LOWER(p.nomeCliente)) LIKE UNACCENT(:busca)',
                    'UNACCENT(LOWER(p.nomeAcao)) LIKE UNACCENT(:busca)',
                    'UNACCENT(LOWER(cpf.nomeCompleto)) LIKE UNACCENT(:busca)',
                    'UNACCENT(LOWER(cpj.razaoSocial)) LIKE UNACCENT(:busca)',
                    'UNACCENT(LOWER(proc.classeProcessual)) LIKE UNACCENT(:busca)',
                    'UNACCENT(LOWER(proc.numeroProcesso)) LIKE UNACCENT(:busca)',
                    'UNACCENT(LOWER(proc.assuntoProcessual)) LIKE UNACCENT(:busca)',
                )
            )->setParameter('busca', '%' . mb_strtolower($filters['busca']) . '%');
        }

        if (!empty($filters['prioridade'])) {
            $prioridade = PrioridadePasta::tryFrom((string) $filters['prioridade']);
            if ($prioridade !== null) {
                $qb->andWhere('p.prioridade = :prioridade')
                   ->setParameter('prioridade', $prioridade);
            }
        }

        $dataDe = $this->parseDataFiltro($filters['data_de'] ?? '', false);
        if ($dataDe !== null) {
            $qb->andWhere('p.dataAbertura >= :dataDe')
               ->setParameter('dataDe', $dataDe);
        }

        $dataAte = $this->parseDataFiltro($filters['data_ate'] ?? '', true);
        if ($dataAte !== null) {
            $qb->andWhere('p.dataAbertura <= :dataAte')
               ->setParameter('dataAte', $dataAte);
        }
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
     * @return ?array{id: int, nup: ?string}
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
        $prefixo = 'CASE WHEN CAST_INT_PREFIXO(p.nup) IS NULL THEN -1 ELSE CAST_INT_PREFIXO(p.nup) END';
        $nupCru  = "CASE WHEN p.nup IS NULL THEN '' ELSE p.nup END";

        $linha = $qb
            ->select('p.id', 'p.nup')
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

        return ['id' => (int) $linha['id'], 'nup' => $linha['nup']];
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
     * Ordena a listagem no banco (antes da paginação) pela coluna clicada no cabeçalho.
     *
     * O QueryBuilder já vem com GROUP BY p.id, então colunas de coleção (cliente,
     * marcador) e de relação (responsável, ação/processo) são ordenadas por um valor
     * representativo agregado (MIN) — cada pasta continua sendo uma única linha.
     * Coluna/direção desconhecidas caem no padrão (NUP numérico decrescente).
     */
    private function aplicarOrdenacao(QueryBuilder $qb, string $ordenar, string $direcao): void
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
                $qb->leftJoin('p.clientes', 'cli_ord')
                   ->leftJoin(ClientePF::class, 'cpf_ord', 'WITH', 'cpf_ord.id = cli_ord.id')
                   ->leftJoin(ClientePJ::class, 'cpj_ord', 'WITH', 'cpj_ord.id = cli_ord.id')
                   ->orderBy('MIN(LOWER(COALESCE(cpf_ord.nomeCompleto, cpj_ord.razaoSocial, p.nomeCliente)))', $dir);
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
    }
}
