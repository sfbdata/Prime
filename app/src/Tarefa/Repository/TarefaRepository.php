<?php

declare(strict_types=1);

namespace App\Tarefa\Repository;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PrioridadePasta;
use App\Processo\Entity\Processo;
use App\Tarefa\DTO\PessoaNoTrilhoOutput;
use App\Tarefa\Enum\AbaMetas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tarefa>
 */
class TarefaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tarefa::class);
    }

    /**
     * @return Tarefa[]
     */
    public function findByResponsavel(User $usuario): array
    {
        return $this->createQueryBuilder('t')
            ->where(':usuario MEMBER OF t.responsaveis OR t.criadoPor = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('t.dataCriacao', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lista as metas do usuário para a tela "Minhas Metas", já escopadas pelo PAPEL (a aba).
     *
     * A tela nasceu unindo dois papéis numa lista só — `responsável OR criador` —, e o efeito
     * medido em produção foi a fila de trabalho de quem delega ficar tomada pelo que ele
     * delegou: um usuário responsável por 4 metas via 88. Agora o papel é escolhido, e cada
     * aba responde só pelo seu. Ver `docs/specs/minhas-metas-abas.md`.
     *
     * Tarefa é TenantAware — o escopo de tenant vem do TenantFilter no fluxo web; nenhum id de
     * usuário chega aqui pelo request (evita IDOR entre colegas).
     *
     * @param array<string, string> $filtros  busca, status, prioridade, prazo
     * @return Tarefa[]
     */
    public function findParaMinhasMetas(User $usuario, AbaMetas $aba, array $filtros): array
    {
        $qb = $this->createQueryBuilder('t')->setParameter('usuario', $usuario);

        $this->escoparPelaAba($qb, $aba);
        $this->cortarConcluidasAntigas($qb);
        $this->aplicarFiltrosDaTela($qb, $filtros);

        return $qb->orderBy('t.dataCriacao', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Quantas metas EM ABERTO existem em cada aba — é o número que vai no badge de cada uma.
     *
     * Concluída não conta: o badge mede trabalho pendente, e somar concluídas de meses faria o
     * número crescer para sempre sem dizer nada sobre a carga de hoje.
     *
     * @return array<string, int>  chave = valor do enum AbaMetas
     */
    public function contarPorAba(User $usuario): array
    {
        $contagens = [];

        foreach (AbaMetas::cases() as $aba) {
            $qb = $this->createQueryBuilder('t')
                ->select('COUNT(DISTINCT t.id)')
                ->andWhere('t.status != :concluida')
                ->setParameter('usuario', $usuario)
                ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);

            $this->escoparPelaAba($qb, $aba);

            $contagens[$aba->value] = (int) $qb->getQuery()->getSingleScalarResult();
        }

        return $contagens;
    }

    /**
     * Os quatro KPIs do topo da tela.
     *
     * Os três primeiros olham o que está COM o usuário (papel de responsável); o quarto olha o
     * que ele CRIOU e voltou para a revisão dele. São universos diferentes de propósito — o
     * rótulo na tela diz qual é qual.
     *
     * `em_revisao` fica fora dos três primeiros: a meta já foi entregue e a bola está com quem
     * criou, então contá-la como pendência atrasada do responsável cobraria dele algo que não
     * está mais na mão dele.
     *
     * @return array{atrasadas: int, proximas: int, sem_prazo: int, aguardando_revisao: int}
     */
    public function contarPainelMinhasMetas(User $usuario): array
    {
        $hoje   = new \DateTimeImmutable('today');
        $limite = $hoje->modify('+7 days')->setTime(23, 59, 59);

        return [
            'atrasadas'          => $this->contarUrgencia($usuario, static function (QueryBuilder $qb) use ($hoje): void {
                $qb->andWhere('t.prazo IS NOT NULL AND t.prazo < :hoje')->setParameter('hoje', $hoje);
            }),
            'proximas'           => $this->contarUrgencia($usuario, static function (QueryBuilder $qb) use ($hoje, $limite): void {
                $qb->andWhere('t.prazo IS NOT NULL AND t.prazo >= :hoje AND t.prazo <= :limite')
                   ->setParameter('hoje', $hoje)
                   ->setParameter('limite', $limite);
            }),
            'sem_prazo'          => $this->contarUrgencia($usuario, static function (QueryBuilder $qb): void {
                $qb->andWhere('t.prazo IS NULL');
            }),
            'aguardando_revisao' => (int) $this->createQueryBuilder('t')
                ->select('COUNT(DISTINCT t.id)')
                ->andWhere('t.criadoPor = :usuario')
                ->andWhere('t.status = :emRevisao')
                ->setParameter('usuario', $usuario)
                ->setParameter('emRevisao', Tarefa::STATUS_EM_REVISAO)
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }

    /**
     * As pessoas do trilho lateral, com quantas metas em aberto e quantas atrasadas cada uma.
     *
     * A pergunta muda com a aba, e é essa a razão de o trilho existir:
     *  - "Sou responsável" → QUEM DELEGOU para mim (agrupa pelo criador);
     *  - as demais         → COM QUEM ESTÃO as metas (agrupa pelo responsável).
     *
     * Concluída não entra: o bloco mede carga de hoje. Meta com dois responsáveis conta para
     * os dois — é o que o usuário espera ao ler "com quem estão".
     *
     * @return PessoaNoTrilhoOutput[]  em ordem decrescente de metas em aberto
     */
    public function contarPessoasDoTrilho(User $usuario, AbaMetas $aba): array
    {
        $porCriador = $aba === AbaMetas::RESPONSAVEL;

        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.status != :concluida')
            ->setParameter('usuario', $usuario)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);

        if ($porCriador) {
            $qb->select('u.id AS id, u.fullName AS nome, COUNT(DISTINCT t.id) AS abertas')
               ->join('t.criadoPor', 'u')
               ->andWhere(':usuario MEMBER OF t.responsaveis');
        } else {
            $qb->select('u.id AS id, u.fullName AS nome, COUNT(DISTINCT t.id) AS abertas')
               ->join('t.responsaveis', 'u');
            $this->escoparPelaAba($qb, $aba);
        }

        $linhas = $qb->groupBy('u.id, u.fullName')
            ->orderBy('abertas', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $atrasadas = $this->contarAtrasadasPorPessoa($usuario, $aba, $porCriador);

        return array_map(
            static fn (array $l): PessoaNoTrilhoOutput => new PessoaNoTrilhoOutput(
                id: (int) $l['id'],
                nome: (string) $l['nome'],
                abertas: (int) $l['abertas'],
                atrasadas: $atrasadas[(int) $l['id']] ?? 0,
            ),
            $linhas,
        );
    }

    /**
     * Companheira da consulta acima — sai em query própria porque contar duas condições
     * diferentes no mesmo GROUP BY exigiria CASE dentro do COUNT, que o DQL não expressa
     * bem e que ficaria ilegível para quem vier depois.
     *
     * @return array<int, int>  userId => atrasadas
     */
    private function contarAtrasadasPorPessoa(User $usuario, AbaMetas $aba, bool $porCriador): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS id, COUNT(DISTINCT t.id) AS total')
            ->andWhere('t.status NOT IN (:foraDaFila)')
            ->andWhere('t.prazo IS NOT NULL AND t.prazo < :hoje')
            ->setParameter('usuario', $usuario)
            ->setParameter('foraDaFila', [Tarefa::STATUS_CONCLUIDA, Tarefa::STATUS_EM_REVISAO])
            ->setParameter('hoje', new \DateTimeImmutable('today'));

        if ($porCriador) {
            $qb->join('t.criadoPor', 'u')->andWhere(':usuario MEMBER OF t.responsaveis');
        } else {
            $qb->join('t.responsaveis', 'u');
            $this->escoparPelaAba($qb, $aba);
        }

        $contagens = [];
        foreach ($qb->groupBy('u.id')->getQuery()->getArrayResult() as $linha) {
            $contagens[(int) $linha['id']] = (int) $linha['total'];
        }

        return $contagens;
    }

    /**
     * Base dos três KPIs de prazo: o que está com o usuário e ainda depende dele.
     */
    private function contarUrgencia(User $usuario, callable $recorte): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->andWhere(':usuario MEMBER OF t.responsaveis')
            ->andWhere('t.status NOT IN (:foraDaFila)')
            ->setParameter('usuario', $usuario)
            ->setParameter('foraDaFila', [Tarefa::STATUS_CONCLUIDA, Tarefa::STATUS_EM_REVISAO]);

        $recorte($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Traduz a aba em condição de papel.
     *
     * `MEMBER OF` vira `EXISTS (...)` no SQL, não JOIN — é por isso que TODAS não duplica a
     * meta em que o usuário é criador E responsável ao mesmo tempo.
     */
    private function escoparPelaAba(QueryBuilder $qb, AbaMetas $aba): void
    {
        $qb->andWhere(match ($aba) {
            AbaMetas::RESPONSAVEL  => ':usuario MEMBER OF t.responsaveis',
            AbaMetas::CRIEI        => 't.criadoPor = :usuario',
            AbaMetas::ACOMPANHANDO => ':usuario MEMBER OF t.acompanhantes',
            AbaMetas::TODAS        => '(:usuario MEMBER OF t.responsaveis'
                                      . ' OR t.criadoPor = :usuario'
                                      . ' OR :usuario MEMBER OF t.acompanhantes)',
        });
    }

    /**
     * Concluída só entra se foi concluída nos últimos 30 dias.
     *
     * Sem esse corte a tela carrega o histórico inteiro: em produção há usuário com 318 metas
     * na lista, quase metade concluída há meses.
     *
     * O fallback em `dataAlteracao` existe porque `dataConclusao` é nula nas metas concluídas
     * antes de a coluna existir — sem ele, meta concluída legítima sumiria da tela sem aviso.
     */
    private function cortarConcluidasAntigas(QueryBuilder $qb): void
    {
        // Os parênteses externos são obrigatórios: sem eles o `andWhere` seguinte produz
        // `A OR B AND C`, e a faceta passa a valer só para o último ramo do OR — o mesmo
        // defeito de precedência que a versão anterior desta tela já documentava.
        $qb->andWhere(
            '(t.status != :concluida'
            . ' OR (t.dataConclusao IS NOT NULL AND t.dataConclusao >= :janela)'
            . ' OR (t.dataConclusao IS NULL AND t.dataAlteracao IS NOT NULL AND t.dataAlteracao >= :janela))'
        )
           ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
           ->setParameter('janela', new \DateTimeImmutable('-30 days'));
    }

    /**
     * Busca livre + facetas da barra de filtro. Cada `andWhere` é somado ao escopo da aba, que
     * já foi parentetizado — sem isso um `OR` do escopo vazaria meta de terceiro pela faceta.
     *
     * @param array<string, string> $filtros
     */
    private function aplicarFiltrosDaTela(QueryBuilder $qb, array $filtros): void
    {
        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $qb->andWhere('(UNACCENT(LOWER(t.titulo)) LIKE UNACCENT(:busca) OR UNACCENT(LOWER(t.descricao)) LIKE UNACCENT(:busca))')
               ->setParameter('busca', '%' . mb_strtolower($busca) . '%');
        }

        if (!empty($filtros['status'])) {
            $qb->andWhere('t.status = :statusFiltro')
               ->setParameter('statusFiltro', $filtros['status']);
        }

        if (!empty($filtros['prioridade'])) {
            $prioridade = PrioridadePasta::tryFrom((string) $filtros['prioridade']);
            if ($prioridade !== null) {
                $qb->join('t.pasta', 'p_prio')
                   ->andWhere('p_prio.prioridade = :prioridade')
                   ->setParameter('prioridade', $prioridade);
            }
        }

        $prazo = (string) ($filtros['prazo'] ?? '');
        if ($prazo === '') {
            return;
        }

        $agora = new \DateTimeImmutable();

        if ($prazo === 'vencidas') {
            $qb->andWhere('t.prazo IS NOT NULL AND t.prazo < :agora AND t.status != :naoConcluida')
               ->setParameter('agora', $agora)
               ->setParameter('naoConcluida', Tarefa::STATUS_CONCLUIDA);
        }

        if ($prazo === 'proximas') {
            $qb->andWhere('t.prazo IS NOT NULL AND t.prazo >= :agora AND t.prazo <= :limitePrazo AND t.status != :naoConcluida')
               ->setParameter('agora', $agora)
               ->setParameter('limitePrazo', $agora->modify('+7 days'))
               ->setParameter('naoConcluida', Tarefa::STATUS_CONCLUIDA);
        }

        if ($prazo === 'sem') {
            $qb->andWhere('t.prazo IS NULL');
        }
    }

    /**
     * @return Tarefa[]
     */
    public function findByProcesso(Processo $processo): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->join('p.pastaProcessos', 'pp')
            ->where('pp.processo = :processo')
            ->setParameter('processo', $processo)
            ->orderBy('t.dataCriacao', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(Tarefa $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate, responsavel)
     */
    public function countMetasAtivas(Tenant $tenant, array $filtros = []): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate, responsavel)
     * @return array{concluidas: int, total: int}
     */
    public function countMetasGlobal(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);

        $total = (int) (clone $qb)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $concluidas = (int) (clone $qb)
            ->select('COUNT(t.id)')
            ->andWhere('t.status = :concluida')
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->getQuery()
            ->getSingleScalarResult();

        return ['concluidas' => $concluidas, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @return array<int, int>  userId => total (todos os status)
     */
    public function countPorResponsavel(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('u.id');

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
     * @return array<int, int>  userId => total (status != concluida)
     */
    public function countAtivasPorResponsavel(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->groupBy('u.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, int>  userId => total (ativas com prazo < $referencia)
     */
    public function countVencidasPorResponsavel(Tenant $tenant, \DateTimeImmutable $referencia): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo < :referencia')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->setParameter('referencia', $referencia)
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, int>  userId => total (ativas com prazo entre $referencia e $referencia+7 dias)
     */
    public function countPrazosProximosPorResponsavel(Tenant $tenant, \DateTimeImmutable $referencia): array
    {
        $limite = $referencia->modify('+7 days');

        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo >= :referencia')
            ->andWhere('t.prazo <= :limite')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->setParameter('referencia', $referencia)
            ->setParameter('limite', $limite)
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Aplica os filtros globais do Dashboard a uma query cujo alias raiz é 't':
     * período por `t.dataCriacao` e, quando $filtrarResponsavel, o responsável (via
     * junção em t.responsaveis). Não é usado nas contagens de vencidas/prazos, que são
     * relativas à referência (agora), não ao período escolhido.
     *
     * @param array<string, mixed> $filtros  data_de, data_ate, responsavel
     */
    private function aplicarFiltrosDashboard(QueryBuilder $qb, array $filtros, bool $filtrarResponsavel): void
    {
        $dataDe = $this->parseDataDashboard((string) ($filtros['data_de'] ?? ''), false);
        if ($dataDe !== null) {
            $qb->andWhere('t.dataCriacao >= :fDataDe')->setParameter('fDataDe', $dataDe);
        }

        $dataAte = $this->parseDataDashboard((string) ($filtros['data_ate'] ?? ''), true);
        if ($dataAte !== null) {
            $qb->andWhere('t.dataCriacao <= :fDataAte')->setParameter('fDataAte', $dataAte);
        }

        if ($filtrarResponsavel) {
            $resp = (int) ($filtros['responsavel'] ?? 0);
            if ($resp > 0) {
                $qb->join('t.responsaveis', 'u_dash')
                   ->andWhere('u_dash.id = :fResp')
                   ->setParameter('fResp', $resp);
            }
        }
    }

    private function parseDataDashboard(string $valor, bool $fimDoDia): ?\DateTimeImmutable
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
}
