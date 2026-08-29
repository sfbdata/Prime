<?php

namespace App\Pasta\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
