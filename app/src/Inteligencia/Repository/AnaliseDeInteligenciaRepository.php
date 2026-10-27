<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda consulta recebe o Tenant EXPLICITAMENTE: o worker e o console rodam sem sessão, o
 * TenantFilter fica inerte lá, e quem segura o isolamento é este repositório sozinho.
 *
 * As consultas "por alvo" são também por TIPO (e por agente, quando houver): a mesma pasta tem
 * análises do Push (`resumo_push`) e dos agentes (`analise_pasta`, uma trilha por agente), e uma
 * pendente de um agente não pode travar o pedido do Push nem virar "análise anterior" dele. O
 * padrão `ResumoPush` mantém os chamadores da fatia 1 como estavam. Com `comando` (drawer, F1/F2), a
 * consulta é por COMANDO: dois comandos do mesmo agente têm trilhas separadas.
 *
 * @extends ServiceEntityRepository<AnaliseDeInteligencia>
 */
// Não-final: espelha PublicacaoDjenRepository e permite substituição por mock nos testes de UseCase.
class AnaliseDeInteligenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnaliseDeInteligencia::class);
    }

    public function salvar(AnaliseDeInteligencia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * O EntityManager ainda aceita escrita? Depois de uma exceção do banco (o transport `doctrine`
     * divide a conexão com a aplicação) ele fecha, e um flush em EM fechado lança de novo — quem
     * trata o erro precisa perguntar antes de tentar registrar a falha.
     */
    public function emAberto(): bool
    {
        return $this->getEntityManager()->isOpen();
    }

    /**
     * Análises de um alvo (pasta) e tipo, mais recente primeiro, sem as excluídas. Com `agente`
     * nulo e tipo `AnalisePasta`, devolve as de TODOS os agentes (painel do drawer).
     *
     * @return AnaliseDeInteligencia[]
     */
    public function listarPorAlvo(
        Tenant $tenant,
        string $alvoTipo,
        int $alvoId,
        int $limite = 20,
        TipoDeAnalise $tipo = TipoDeAnalise::ResumoPush,
        ?Agente $agente = null,
    ): array {
        return $this->qbDoAlvo($tenant, $alvoTipo, $alvoId, $tipo, $agente)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * As análises de agente da pasta que a tela mostra num comando do drawer: as do comando e as
     * antigas, de antes dos comandos (`comando` nulo), dos agentes que {@see Comando::doAgenteLegado}
     * põe nele. Mais recente primeiro, sem as excluídas, tenant explícito.
     *
     * @return AnaliseDeInteligencia[]
     */
    public function listarPorComando(Tenant $tenant, string $alvoTipo, int $alvoId, Comando $comando, int $limite = 20): array
    {
        $qb = $this->qbDoAlvo($tenant, $alvoTipo, $alvoId, TipoDeAnalise::AnalisePasta, null);
        $legados = $comando->agentesLegados();
        if ($legados === []) {
            $qb->andWhere('a.comando = :comando');
        } else {
            $qb->andWhere('(a.comando = :comando OR (a.comando IS NULL AND a.agente IN (:legados)))')
                ->setParameter('legados', array_map(static fn (Agente $a): string => $a->value, $legados), ArrayParameterType::STRING);
        }

        return $qb->setParameter('comando', $comando)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * A trilha de IA de um alvo (rodapé "Registro de auditoria" do drawer): TODOS os tipos, todos
     * os agentes e INCLUSIVE as excluídas — exclusão é só visual, a trilha fica. Mais recente
     * primeiro, com o solicitante já carregado (sem N+1 no nome de quem pediu).
     *
     * @return AnaliseDeInteligencia[]
     */
    public function listarTrilhaDoAlvo(Tenant $tenant, string $alvoTipo, int $alvoId, int $limite): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.solicitante', 's')
            ->addSelect('s')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.alvoTipo = :alvoTipo')
            ->andWhere('a.alvoId = :alvoId')
            ->setParameter('tenant', $tenant)
            ->setParameter('alvoTipo', $alvoTipo)
            ->setParameter('alvoId', $alvoId)
            ->orderBy('a.criadaEm', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** Quantas linhas a trilha do alvo tem ao todo (o N do "Registro de auditoria (N)"). */
    public function contarTrilhaDoAlvo(Tenant $tenant, string $alvoTipo, int $alvoId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.alvoTipo = :alvoTipo')
            ->andWhere('a.alvoId = :alvoId')
            ->setParameter('tenant', $tenant)
            ->setParameter('alvoTipo', $alvoTipo)
            ->setParameter('alvoId', $alvoId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Downloads e impressões das análises de um alvo (C6-IA-01), lidos do `audit_log`: só as
     * `$acoes` pedidas, só `entity_class` = análise, só o `tenant_id` informado e só os ids das
     * análises DESTE alvo deste escritório. A linha de outro escritório não entra nem se apontar
     * para um id daqui. Mais recente primeiro, com o nome de quem fez e o rótulo da análise.
     *
     * A ligação com a análise é pelo id em texto (`entity_id` é VARCHAR): os ids do alvo são lidos
     * antes, com tenant explícito, e entram como lista de strings — sem comparar texto com inteiro.
     *
     * @param list<string> $acoes
     *
     * @return list<array{acao: string, analiseId: int, quando: \DateTimeImmutable, autorNome: ?string, agente: ?Agente, tipo: TipoDeAnalise, comando: ?Comando}>
     */
    public function listarExportacoesDoAlvo(Tenant $tenant, string $alvoTipo, int $alvoId, array $acoes, int $limite): array
    {
        $analises = $this->analisesDoAlvoPorId($tenant, $alvoTipo, $alvoId);
        if ($analises === [] || $acoes === []) {
            return [];
        }

        $linhas = $this->qbDasExportacoes($tenant, array_map('strval', array_keys($analises)), $acoes)
            ->select('l.action AS acao', 'l.entityId AS analiseId', 'l.createdAt AS quando', 'u.fullName AS autorNome', 'l.actorEmail AS autorEmail')
            ->leftJoin(User::class, 'u', 'WITH', 'u.id = l.actorUserId')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getArrayResult();

        $saida = [];
        foreach ($linhas as $linha) {
            $analise = $analises[(string) $linha['analiseId']] ?? null;
            if ($analise === null) {
                continue;
            }
            $quando = $linha['quando'];
            $saida[] = [
                'acao' => (string) $linha['acao'],
                'analiseId' => (int) $linha['analiseId'],
                'quando' => $quando instanceof \DateTimeImmutable ? $quando : new \DateTimeImmutable((string) $quando),
                'autorNome' => $linha['autorNome'] ?? $linha['autorEmail'] ?? null,
                'agente' => $analise['agente'],
                'tipo' => $analise['tipo'],
                'comando' => $analise['comando'],
            ];
        }

        return $saida;
    }

    /**
     * Quantos downloads e impressões as análises do alvo têm ao todo (somam no N do "Registro de
     * auditoria (N)"). Mesmos recortes de {@see self::listarExportacoesDoAlvo()}.
     *
     * @param list<string> $acoes
     */
    public function contarExportacoesDoAlvo(Tenant $tenant, string $alvoTipo, int $alvoId, array $acoes): int
    {
        $analises = $this->analisesDoAlvoPorId($tenant, $alvoTipo, $alvoId);
        if ($analises === [] || $acoes === []) {
            return 0;
        }

        return (int) $this->qbDasExportacoes($tenant, array_map('strval', array_keys($analises)), $acoes)
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A análise em andamento (pendente ou processando) do alvo/tipo/agente/comando, se houver — idempotência do pedido.
     * `$criadaApos` (opcional): só vale a criada a partir desse instante, para o chamador que não quer ficar preso a
     * uma análise que o worker abandonou (o chat da Central). Sem ele, o comportamento é o de sempre.
     */
    public function findPendenteDoAlvo(
        Tenant $tenant,
        string $alvoTipo,
        int $alvoId,
        TipoDeAnalise $tipo = TipoDeAnalise::ResumoPush,
        ?Agente $agente = null,
        ?Comando $comando = null,
        ?\DateTimeImmutable $criadaApos = null,
    ): ?AnaliseDeInteligencia {
        $qb = $this->qbDoAlvo($tenant, $alvoTipo, $alvoId, $tipo, $agente, $comando, semComandoQuandoNulo: true)
            ->andWhere('a.status IN (:emAndamento)')
            ->setParameter('emAndamento', [StatusDaAnalise::Pendente, StatusDaAnalise::Processando])
            ->setMaxResults(1);
        if ($criadaApos !== null) {
            $qb->andWhere('a.criadaEm >= :criadaApos')->setParameter('criadaApos', $criadaApos);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** A última concluída do alvo/tipo/agente/comando — base do "nada novo desde a última" e do [NOVA] do prompt. */
    public function findUltimaConcluidaDoAlvo(
        Tenant $tenant,
        string $alvoTipo,
        int $alvoId,
        TipoDeAnalise $tipo = TipoDeAnalise::ResumoPush,
        ?Agente $agente = null,
        ?Comando $comando = null,
    ): ?AnaliseDeInteligencia {
        return $this->qbDoAlvo($tenant, $alvoTipo, $alvoId, $tipo, $agente, $comando, semComandoQuandoNulo: true)
            ->andWhere('a.status = :concluida')
            ->setParameter('concluida', StatusDaAnalise::Concluida)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Busca tenant-safe por id — find() por PK não passa pelo filtro. */
    public function findOneDoTenant(int $id, Tenant $tenant): ?AnaliseDeInteligencia
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Guarda de IDOR das ações por id: a análise tem de ser do escritório E do alvo da URL. A
     * restrição fica na consulta, não numa comparação depois.
     */
    public function findOneDoTenantEAlvo(int $id, Tenant $tenant, string $alvoTipo, int $alvoId): ?AnaliseDeInteligencia
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant, 'alvoTipo' => $alvoTipo, 'alvoId' => $alvoId]);
    }

    /**
     * Prefixo do motivo de quem o worker cancelou ANTES de chamar o provedor (IA-034L: a IA foi
     * desligada — plataforma, escritório, permissão — ou quem pediu não existe mais). É a única
     * marca que a linha guarda desse desfecho; `contarDesde` o usa para devolver a cota.
     */
    public const MOTIVO_CANCELADA_ANTES_DO_PROVEDOR = 'cancelada antes do provedor: ';

    /**
     * Quantas análises o escritório pediu desde um instante (cota diária/mensal). Conta quase
     * TODAS — inclusive falhas e excluídas: a cota mede o que foi pedido, e a exclusão é só
     * visual. A exceção é a cancelada antes do provedor (`indisponivel`/`falhou` com o motivo
     * `MOTIVO_CANCELADA_ANTES_DO_PROVEDOR`): o provedor não foi chamado, não gastou nada.
     * O `erroMotivo IS NULL` vem antes: sem ele o NOT LIKE de um NULL descartaria a linha.
     */
    public function contarDesde(Tenant $tenant, \DateTimeImmutable $desde): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.criadaEm >= :desde')
            ->andWhere('(a.status NOT IN (:semProvedor) OR a.erroMotivo IS NULL OR a.erroMotivo NOT LIKE :cancelada)')
            ->setParameter('tenant', $tenant)
            ->setParameter('desde', $desde)
            ->setParameter('semProvedor', [StatusDaAnalise::Indisponivel->value, StatusDaAnalise::Falhou->value])
            ->setParameter('cancelada', self::MOTIVO_CANCELADA_ANTES_DO_PROVEDOR . '%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Tokens gastos pelo escritório desde um instante, somados por modelo (o orçamento mensal em US$, PR C de
     * ia-anthropic-ligar). Entram TODAS as análises com tokens, inclusive as excluídas e as que falharam: a Anthropic
     * cobrou. Tenant explícito (o worker e o console rodam sem TenantFilter); o índice `(tenant_id, criada_em)` cobre.
     *
     * @return list<array{modelo: ?string, tokensEntrada: int, tokensSaida: int}>
     */
    public function somarTokensPorModeloDesde(Tenant $tenant, \DateTimeImmutable $desde): array
    {
        $linhas = $this->createQueryBuilder('a')
            ->select('a.modelo AS modelo', 'COALESCE(SUM(a.tokensEntrada), 0) AS tokensEntrada', 'COALESCE(SUM(a.tokensSaida), 0) AS tokensSaida')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.criadaEm >= :desde')
            ->andWhere('(a.tokensEntrada IS NOT NULL OR a.tokensSaida IS NOT NULL)')
            ->setParameter('tenant', $tenant)
            ->setParameter('desde', $desde)
            ->groupBy('a.modelo')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $l): array => [
            'modelo' => $l['modelo'] === null ? null : (string) $l['modelo'],
            'tokensEntrada' => (int) $l['tokensEntrada'],
            'tokensSaida' => (int) $l['tokensSaida'],
        ], $linhas);
    }

    /**
     * O consumo de TODOS os escritórios desde um instante, numa consulta só (a tabela da aba do Master, sem N+1):
     * por escritório e modelo, as análises que contam na cota (a mesma regra do {@see contarDesde}) e os tokens de
     * todas, inclusive excluídas e falhas. Varre todos os tenants de propósito: quem chama é o Master.
     *
     * @return list<array{tenantId: int, modelo: ?string, analises: int, tokensEntrada: int, tokensSaida: int}>
     */
    public function consumoDosEscritoriosDesde(\DateTimeImmutable $desde): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT tenant_id, modelo,
                    COUNT(*) FILTER (WHERE status NOT IN (:indisponivel, :falhou) OR erro_motivo IS NULL OR erro_motivo NOT LIKE :cancelada) AS analises,
                    COALESCE(SUM(tokens_entrada), 0) AS tokens_entrada,
                    COALESCE(SUM(tokens_saida), 0) AS tokens_saida
               FROM inteligencia_analise
              WHERE criada_em >= :desde
           GROUP BY tenant_id, modelo',
            [
                'indisponivel' => StatusDaAnalise::Indisponivel->value,
                'falhou' => StatusDaAnalise::Falhou->value,
                'cancelada' => self::MOTIVO_CANCELADA_ANTES_DO_PROVEDOR . '%',
                'desde' => $desde->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(static fn (array $l): array => [
            'tenantId' => (int) $l['tenant_id'],
            'modelo' => $l['modelo'] === null ? null : (string) $l['modelo'],
            'analises' => (int) $l['analises'],
            'tokensEntrada' => (int) $l['tokens_entrada'],
            'tokensSaida' => (int) $l['tokens_saida'],
        ], $linhas);
    }

    /**
     * Contagem por escritório e status, para o comando de status (CLI, sem TenantFilter — lista
     * todos os tenants de propósito).
     *
     * @return list<array{tenantId: int, tenantNome: string, status: string, total: int}>
     */
    public function contarPorTenantEStatus(): array
    {
        $linhas = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.tenant) AS tenantId', 't.name AS tenantNome', 'a.status AS status', 'COUNT(a.id) AS total')
            ->join('a.tenant', 't')
            ->groupBy('a.tenant', 't.name', 'a.status')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $linha): array {
            $status = $linha['status'];

            return [
                'tenantId' => (int) $linha['tenantId'],
                'tenantNome' => (string) $linha['tenantNome'],
                'status' => $status instanceof StatusDaAnalise ? $status->value : (string) $status,
                'total' => (int) $linha['total'],
            ];
        }, $linhas);
    }

    /**
     * Retenção (decisão de 24/10/2026, `docs/specs/ia-anthropic-ligar.md` §10, Q5): análise terminada antes de
     * `$limite` perde o conteúdo (resposta, pontos, edição e o texto das perguntas da conversa) e sai das telas
     * (`excluida_em`). A linha fica com status, modelo, tokens e datas: é o que o custo e a auditoria usam.
     * Varre todos os escritórios de propósito: o filtro é a data, nada de um escritório vai para outro.
     *
     * @return array{analises: int, perguntas: int}
     */
    public function expirarConteudoAntesDe(\DateTimeImmutable $limite, \DateTimeImmutable $agora): array
    {
        $em = $this->getEntityManager();
        $terminadas = array_map(
            static fn (StatusDaAnalise $s): string => $s->value,
            [StatusDaAnalise::Concluida, StatusDaAnalise::Falhou, StatusDaAnalise::Indisponivel],
        );

        return $em->wrapInTransaction(static function () use ($em, $limite, $agora, $terminadas): array {
            $perguntas = $em->createQuery(
                "UPDATE App\\Inteligencia\\Entity\\PerguntaDaConversa p SET p.texto = ''
                 WHERE p.texto <> '' AND p.analise IN (
                     SELECT a.id FROM App\\Inteligencia\\Entity\\AnaliseDeInteligencia a
                     WHERE a.criadaEm < :limite AND a.status IN (:terminadas)
                 )",
            )->setParameter('limite', $limite)->setParameter('terminadas', $terminadas)->execute();

            $analises = $em->createQuery(
                'UPDATE App\\Inteligencia\\Entity\\AnaliseDeInteligencia a
                 SET a.resumo = NULL, a.pontos = NULL, a.quemAge = NULL, a.textoDaAnalise = NULL, a.textoBruto = NULL,
                     a.resumoEditado = NULL, a.pontosEditados = NULL,
                     a.excluidaEm = COALESCE(a.excluidaEm, :agora)
                 WHERE a.criadaEm < :limite AND a.status IN (:terminadas)
                   AND (a.resumo IS NOT NULL OR a.pontos IS NOT NULL OR a.quemAge IS NOT NULL OR a.textoDaAnalise IS NOT NULL
                        OR a.textoBruto IS NOT NULL OR a.resumoEditado IS NOT NULL OR a.pontosEditados IS NOT NULL
                        OR a.excluidaEm IS NULL)',
            )->setParameter('limite', $limite)->setParameter('agora', $agora)->setParameter('terminadas', $terminadas)->execute();

            return ['analises' => (int) $analises, 'perguntas' => (int) $perguntas];
        });
    }

    /**
     * As análises do alvo (inclusive excluídas: a trilha fica), indexadas pelo id em texto, com o
     * que o rótulo do registro precisa. Tenant explícito.
     *
     * @return array<string, array{agente: ?Agente, tipo: TipoDeAnalise, comando: ?Comando}>
     */
    private function analisesDoAlvoPorId(Tenant $tenant, string $alvoTipo, int $alvoId): array
    {
        $linhas = $this->createQueryBuilder('a')
            ->select('a.id AS id', 'a.agente AS agente', 'a.tipo AS tipo', 'a.comando AS comando')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.alvoTipo = :alvoTipo')
            ->andWhere('a.alvoId = :alvoId')
            ->setParameter('tenant', $tenant)
            ->setParameter('alvoTipo', $alvoTipo)
            ->setParameter('alvoId', $alvoId)
            ->getQuery()
            ->getArrayResult();

        $porId = [];
        foreach ($linhas as $linha) {
            $agente = $linha['agente'];
            $tipo = $linha['tipo'];
            $comando = $linha['comando'] ?? null;
            $porId[(string) $linha['id']] = [
                'agente' => $agente instanceof Agente ? $agente : ($agente === null ? null : Agente::tryFrom((string) $agente)),
                'tipo' => $tipo instanceof TipoDeAnalise ? $tipo : TipoDeAnalise::from((string) $tipo),
                'comando' => $comando instanceof Comando ? $comando : ($comando === null ? null : Comando::tryFrom((string) $comando)),
            ];
        }

        return $porId;
    }

    /**
     * @param list<string> $idsDasAnalises
     * @param list<string> $acoes
     */
    private function qbDasExportacoes(Tenant $tenant, array $idsDasAnalises, array $acoes): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->from(AuditLog::class, 'l')
            ->andWhere('l.tenantId = :tenantId')
            ->andWhere('l.entityClass = :classe')
            ->andWhere('l.action IN (:acoes)')
            ->andWhere('l.entityId IN (:ids)')
            ->setParameter('tenantId', $tenant->getId())
            ->setParameter('classe', AnaliseDeInteligencia::class)
            ->setParameter('acoes', $acoes, ArrayParameterType::STRING)
            // Chaves numéricas de array viram int no PHP: o tipo explícito mantém `entity_id` (texto)
            // comparado com texto, e não com inteiro (o PostgreSQL recusaria varchar = integer).
            ->setParameter('ids', $idsDasAnalises, ArrayParameterType::STRING);
    }

    private function qbDoAlvo(Tenant $tenant, string $alvoTipo, int $alvoId, TipoDeAnalise $tipo, ?Agente $agente, ?Comando $comando = null, bool $semComandoQuandoNulo = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.alvoTipo = :alvoTipo')
            ->andWhere('a.alvoId = :alvoId')
            ->andWhere('a.tipo = :tipo')
            ->andWhere('a.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('alvoTipo', $alvoTipo)
            ->setParameter('alvoId', $alvoId)
            ->setParameter('tipo', $tipo)
            ->orderBy('a.criadaEm', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ($agente !== null) {
            $qb->andWhere('a.agente = :agente')->setParameter('agente', $agente);
        }
        if ($comando !== null) {
            $qb->andWhere('a.comando = :comando')->setParameter('comando', $comando);
        } elseif ($semComandoQuandoNulo) {
            // Pedido por agente (rota antiga, Push): não trava nem herda como "anterior" a linha de um comando.
            $qb->andWhere('a.comando IS NULL');
        }

        return $qb;
    }
}
