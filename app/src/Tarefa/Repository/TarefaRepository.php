<?php

declare(strict_types=1);

namespace App\Tarefa\Repository;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Processo\Entity\Processo;
use App\Tarefa\DTO\AndamentoDasMetasOutput;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use App\Shared\Prazo\RegraDoPrazo;

/**
 * @extends ServiceEntityRepository<Tarefa>
 */
class TarefaRepository extends ServiceEntityRepository
{
    /**
     * Status que saem da fila de trabalho do responsável: concluída acabou, e em revisão já foi
     * entregue — a bola está com quem criou. Usada pelos KPIs e pelas facetas de prazo, que
     * PRECISAM enxergar o mesmo universo (o KPI é o atalho que abre a faceta).
     */
    private const FORA_DA_FILA = [Tarefa::STATUS_CONCLUIDA, Tarefa::STATUS_EM_REVISAO];

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
     * `$pastasOcultas`: ids das pastas ocultas pelo cadeado para o usuário (spec pasta-cadeado D3); as metas
     * delas não entram aqui nem nas contagens abaixo.
     *
     * `etapa` (o clique numa caixa da esteira, desenho 04 de 22/10): a esteira é do escritório, então a lista
     * passa a ser a das metas ABERTAS daquela etapa de toda a equipe ("Clique numa caixa para ver as metas dela
     * de toda a equipe"), no lugar do papel da aba. Só com o `$escritorio` explícito: o escopo deixa de ser o
     * usuário, e o filtro de tenant não fica só a cargo do TenantFilter.
     *
     * @param array<string, string> $filtros  busca, prioridade (da meta), prazo, etapa
     * @param list<int>             $pastasOcultas
     * @return Tarefa[]
     */
    public function findParaMinhasMetas(User $usuario, AbaMetas $aba, array $filtros, array $pastasOcultas = [], ?Tenant $escritorio = null): array
    {
        $qb = $this->createQueryBuilder('t');

        $etapa = EtapaDaPasta::tryFrom((string) ($filtros['etapa'] ?? ''));
        if ($etapa !== null && $escritorio !== null) {
            $this->escoparPelaEtapa($qb, $etapa, $escritorio);
        } else {
            $qb->setParameter('usuario', $usuario);
            $this->escoparPelaAba($qb, $aba);
        }
        $this->semPastasOcultas($qb, $pastasOcultas);
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
     * @param list<int> $pastasOcultas
     * @return array<string, int>  chave = valor do enum AbaMetas
     */
    public function contarPorAba(User $usuario, array $pastasOcultas = []): array
    {
        $contagens = [];

        foreach (AbaMetas::cases() as $aba) {
            $qb = $this->createQueryBuilder('t')
                ->select('COUNT(DISTINCT t.id)')
                ->andWhere('t.status != :concluida')
                ->setParameter('usuario', $usuario)
                ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);

            $this->escoparPelaAba($qb, $aba);
            $this->semPastasOcultas($qb, $pastasOcultas);

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
     * @param list<int> $pastasOcultas
     * @return array{atrasadas: int, proximas: int, sem_prazo: int, aguardando_revisao: int}
     */
    public function contarPainelMinhasMetas(User $usuario, array $pastasOcultas = []): array
    {
        $hoje   = new \DateTimeImmutable('today');
        $limite = $hoje->modify('+7 days')->setTime(23, 59, 59);

        return [
            'atrasadas'          => $this->contarUrgencia($usuario, static function (QueryBuilder $qb) use ($hoje): void {
                $qb->andWhere('t.prazo IS NOT NULL AND t.prazo < :hoje')->setParameter('hoje', $hoje);
            }, $pastasOcultas),
            'proximas'           => $this->contarUrgencia($usuario, static function (QueryBuilder $qb) use ($hoje, $limite): void {
                $qb->andWhere('t.prazo IS NOT NULL AND t.prazo >= :hoje AND t.prazo <= :limite')
                   ->setParameter('hoje', $hoje)
                   ->setParameter('limite', $limite);
            }, $pastasOcultas),
            'sem_prazo'          => $this->contarUrgencia($usuario, static function (QueryBuilder $qb): void {
                $qb->andWhere('t.prazo IS NULL');
            }, $pastasOcultas),
            'aguardando_revisao' => $this->contarAguardandoRevisao($usuario, $pastasOcultas),
        ];
    }

    /**
     * Números do "Seu andamento" (desenho 04, dc L112-118): sobre TODAS as metas da pessoa, sem a aba
     * aberta e sem os filtros da tela. Uma consulta só, agregada.
     *
     * Mesmo universo da aba Todas: concluídas só dos últimos 30 dias (o "últimos 30 dias" do cartão)
     * e sem as pastas do cadeado. Atrasada pelo mesmo recorte dos KPIs (`FORA_DA_FILA`, prazo antes de
     * hoje), e "hoje" vem do relógio do PHP, como no resto da tela, nunca do `NOW()` do banco.
     *
     * @param list<int> $pastasOcultas
     */
    public function contarAndamento(User $usuario, array $pastasOcultas = []): AndamentoDasMetasOutput
    {
        $qb = $this->universoDoAndamento($usuario, $pastasOcultas)
            ->select('COUNT(t.id) AS total')
            ->addSelect('COALESCE(SUM(CASE WHEN t.status = :concluida THEN 1 ELSE 0 END), 0) AS concluidas')
            ->addSelect('COALESCE(SUM(CASE WHEN t.status = :emRevisao THEN 1 ELSE 0 END), 0) AS emRevisao')
            ->addSelect('COALESCE(SUM(CASE WHEN t.status NOT IN (:foraDaFila) AND t.prazo IS NOT NULL AND t.prazo < :hoje THEN 1 ELSE 0 END), 0) AS atrasadas')
            ->setParameter('emRevisao', Tarefa::STATUS_EM_REVISAO)
            ->setParameter('foraDaFila', self::FORA_DA_FILA)
            ->setParameter('hoje', new \DateTimeImmutable('today'));

        $linha = $qb->getQuery()->getSingleResult();

        return new AndamentoDasMetasOutput(
            concluidas: (int) $linha['concluidas'],
            total: (int) $linha['total'],
            atrasadas: (int) $linha['atrasadas'],
            emRevisao: (int) $linha['emRevisao'],
        );
    }

    /**
     * "Precisa de atenção" (desenho 04, dc `atencao`): as atrasadas de TODAS as metas da pessoa, da
     * que venceu primeiro, até `$limite`. Mesmo universo e mesmo recorte de `contarAndamento`.
     *
     * Duas idas ao banco, nenhuma por linha: os ids com o limite (o LIMIT não convive com junção de
     * coleção) e depois as metas com pasta e criador já carregados, que o cartão lê.
     *
     * @param list<int> $pastasOcultas
     * @return Tarefa[]
     */
    public function findAtrasadasParaAtencao(User $usuario, int $limite, array $pastasOcultas = []): array
    {
        $ids = $this->universoDoAndamento($usuario, $pastasOcultas)
            ->select('t.id')
            // Pasta excluída (lápide) não pede atenção: o item abre a pasta, e ela não existe mais na tela.
            ->innerJoin('t.pasta', 'pastaDaAtencao', 'WITH', 'pastaDaAtencao.excluidaEm IS NULL')
            ->andWhere('t.status NOT IN (:foraDaFila)')
            ->andWhere('t.prazo IS NOT NULL AND t.prazo < :hoje')
            ->setParameter('foraDaFila', self::FORA_DA_FILA)
            ->setParameter('hoje', new \DateTimeImmutable('today'))
            ->orderBy('t.prazo', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getSingleColumnResult();

        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('p', 'c')
            ->leftJoin('t.pasta', 'p')
            ->leftJoin('t.criadoPor', 'c')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('t.prazo', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Base de `contarAndamento` e `findAtrasadasParaAtencao`: a aba Todas, sem cadeado e sem as
     * concluídas antigas. Sem filtro da tela de propósito.
     *
     * @param list<int> $pastasOcultas
     */
    private function universoDoAndamento(User $usuario, array $pastasOcultas): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')->setParameter('usuario', $usuario);

        $this->escoparPelaAba($qb, AbaMetas::TODAS);
        $this->semPastasOcultas($qb, $pastasOcultas);
        $this->cortarConcluidasAntigas($qb);

        return $qb;
    }

    /**
     * Quais destas metas o usuário acompanha.
     *
     * Existe para matar o N+1 do template: `meta.ehAcompanhadaPor(app.user)` inicializava a coleção meta a
     * meta. Aqui é uma consulta, e o Twig só consulta o array.
     *
     * @param int[] $metaIds
     * @return array{acompanhadas: int[]}
     */
    public function carregarMarcadoresDaLista(User $usuario, array $metaIds): array
    {
        if ($metaIds === []) {
            return ['acompanhadas' => []];
        }

        // A raiz da consulta é `Tarefa` (TenantAware) de propósito: assim o TenantFilter prende o escopo, e um id
        // forjado na lista não alcançaria meta de outro escritório.
        $acompanhadas = $this->createQueryBuilder('t')
            ->select('t.id')
            ->andWhere('t.id IN (:ids)')
            ->andWhere(':usuario MEMBER OF t.acompanhantes')
            ->setParameter('ids', $metaIds)
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getSingleColumnResult();

        return [
            'acompanhadas' => array_map('intval', $acompanhadas),
        ];
    }

    /**
     * Base dos três KPIs de prazo: o que está com o usuário e ainda depende dele.
     */
    /** @param list<int> $pastasOcultas */
    private function contarUrgencia(User $usuario, callable $recorte, array $pastasOcultas = []): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->andWhere(':usuario MEMBER OF t.responsaveis')
            ->andWhere('t.status NOT IN (:foraDaFila)')
            ->setParameter('usuario', $usuario)
            ->setParameter('foraDaFila', self::FORA_DA_FILA);

        $recorte($qb);
        $this->semPastasOcultas($qb, $pastasOcultas);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @param list<int> $pastasOcultas */
    private function contarAguardandoRevisao(User $usuario, array $pastasOcultas): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->andWhere('t.criadoPor = :usuario')
            ->andWhere('t.status = :emRevisao')
            ->setParameter('usuario', $usuario)
            ->setParameter('emRevisao', Tarefa::STATUS_EM_REVISAO);
        $this->semPastasOcultas($qb, $pastasOcultas);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Cadeado (spec pasta-cadeado D3): tira as metas das pastas ocultas para quem olha. A raiz é `t`.
     *
     * @param list<int> $pastasOcultas
     */
    private function semPastasOcultas(QueryBuilder $qb, array $pastasOcultas): void
    {
        if ($pastasOcultas !== []) {
            $qb->andWhere('IDENTITY(t.pasta) NOT IN (:pastasOcultas)')
               ->setParameter('pastasOcultas', $pastasOcultas);
        }
    }

    /** As metas abertas de uma caixa da esteira, de todo o escritório. */
    private function escoparPelaEtapa(QueryBuilder $qb, EtapaDaPasta $etapa, Tenant $escritorio): void
    {
        // Pasta excluída (lápide) fica fora, como na contagem da caixa e no Dashboard.
        $qb->innerJoin('t.pasta', 'pastaDaEtapa', 'WITH', 'pastaDaEtapa.excluidaEm IS NULL')
           ->andWhere('t.tenant = :escritorioDaEtapa')
           ->andWhere('t.etapa = :etapaDaEsteira')
           ->andWhere('t.status != :concluidaDaEtapa')
           ->setParameter('escritorioDaEtapa', $escritorio)
           ->setParameter('etapaDaEsteira', $etapa)
           ->setParameter('concluidaDaEtapa', Tarefa::STATUS_CONCLUIDA);
    }

    /**
     * A esteira da pasta (desenho 04 de 22/10, dc L163-167): por caixa, quantas metas ABERTAS o escritório tem
     * (em revisão conta) e quantas delas estão atrasadas, pelo mesmo recorte dos KPIs (`FORA_DA_FILA`, prazo
     * antes de hoje), sem pasta excluída. Uma consulta agregada; caixa sem meta não vem (quem lê completa com zero).
     *
     * @param list<int> $pastasOcultas
     * @return array<string, array{total: int, atrasadas: int}> chave = valor de `EtapaDaPasta`
     */
    public function contarEsteira(Tenant $escritorio, \DateTimeImmutable $hoje, array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.etapa AS etapa')
            ->addSelect('COUNT(t.id) AS total')
            ->addSelect('COALESCE(SUM(CASE WHEN t.status NOT IN (:foraDaFila) AND t.prazo IS NOT NULL AND t.prazo < :hoje THEN 1 ELSE 0 END), 0) AS atrasadas')
            ->innerJoin('t.pasta', 'p', 'WITH', 'p.excluidaEm IS NULL') // pasta excluída (lápide) não conta, como no Dashboard
            ->andWhere('t.tenant = :escritorio')
            ->andWhere('t.status != :concluida')
            ->setParameter('escritorio', $escritorio)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->setParameter('foraDaFila', self::FORA_DA_FILA)
            ->setParameter('hoje', $hoje->setTime(0, 0))
            ->groupBy('t.etapa');
        $this->semPastasOcultas($qb, $pastasOcultas);

        $porEtapa = [];
        foreach ($qb->getQuery()->getArrayResult() as $linha) {
            $etapa = $linha['etapa'] instanceof EtapaDaPasta ? $linha['etapa']->value : (string) $linha['etapa'];
            $porEtapa[$etapa] = ['total' => (int) $linha['total'], 'atrasadas' => (int) $linha['atrasadas']];
        }

        return $porEtapa;
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
        $busca      = trim((string) ($filtros['busca'] ?? ''));
        // Prioridade PRÓPRIA da meta (desenho 04, P:77): uma meta normal numa pasta urgente não entra em
        // "Urgente". Valor fora do enum (o antigo `prioridade` da pasta, num link guardado) é ignorado.
        $prioridade = PrioridadeDaMeta::tryFrom((string) ($filtros['prioridade'] ?? ''));

        if ($busca !== '') {
            // A busca lê a pasta da meta (nº e cliente). É ManyToOne, então o join não duplica linha.
            $qb->leftJoin('t.pasta', 'p_tela');
            $this->aplicarBuscaDaTela($qb, $busca);
        }

        if ($prioridade !== null) {
            $qb->andWhere('t.prioridade = :prioridade')
               ->setParameter('prioridade', $prioridade);
        }

        $prazo = (string) ($filtros['prazo'] ?? '');
        if ($prazo === '') {
            return;
        }

        // As três facetas de prazo usam o MESMO recorte dos KPIs (`self::FORA_DA_FILA` e o
        // corte por dia). O KPI é um atalho: se ele conta um universo e o filtro que ele abre
        // devolve outro, o usuário clica em "Atrasadas: 4" e recebe 7 linhas. Isso acontecia:
        // meta `em_revisao` vencida entrava na lista sem entrar na contagem, e meta concluída
        // sem prazo entrava em "Sem prazo" com o KPI marcando zero.
        if ($prazo === 'vencidas' || $prazo === 'proximas' || $prazo === 'sem') {
            $qb->andWhere('t.status NOT IN (:foraDaFilaFiltro)')
               ->setParameter('foraDaFilaFiltro', self::FORA_DA_FILA);
        }

        $hoje = new \DateTimeImmutable('today');

        if ($prazo === 'vencidas') {
            $qb->andWhere('t.prazo IS NOT NULL AND t.prazo < :hojeFiltro')
               ->setParameter('hojeFiltro', $hoje);
        }

        if ($prazo === 'proximas') {
            $qb->andWhere('t.prazo IS NOT NULL AND t.prazo >= :hojeFiltro AND t.prazo <= :limitePrazo')
               ->setParameter('hojeFiltro', $hoje)
               ->setParameter('limitePrazo', $hoje->modify('+7 days')->setTime(23, 59, 59));
        }

        if ($prazo === 'sem') {
            $qb->andWhere('t.prazo IS NULL');
        }
    }

    /**
     * "Buscar por título, pasta ou cliente" (desenho de 19/10): título, nº da pasta e nome do cliente,
     * sem acento. A descrição continua entrando, como antes do desenho. Usa o alias `p_tela`, que
     * `aplicarFiltrosDaTela` junta uma vez só. `%`, `_` e `\` digitados são escapados (o escape padrão
     * do LIKE no PostgreSQL é a barra), como no NotificacaoRepository: "%" procura um "%" literal nos
     * quatro campos, em vez de casar tudo.
     */
    private function aplicarBuscaDaTela(QueryBuilder $qb, string $busca): void
    {
        $qb->andWhere(
            '(UNACCENT(LOWER(t.titulo)) LIKE UNACCENT(:busca)'
            . ' OR UNACCENT(LOWER(t.descricao)) LIKE UNACCENT(:busca)'
            . ' OR UNACCENT(LOWER(p_tela.nup)) LIKE UNACCENT(:busca)'
            . ' OR UNACCENT(LOWER(p_tela.nomeCliente)) LIKE UNACCENT(:busca))'
        )
           ->setParameter('busca', '%' . addcslashes(mb_strtolower($busca), '%_\\') . '%');
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

    /**
     * Uma meta pelo id, desta pasta E deste escritório (o "Encaminhar via Chat I.A" relê o item no servidor).
     * Filtro explícito: não depende de o TenantFilter estar ligado.
     */
    public function findByIdAndPastaAndTenant(int $id, Pasta $pasta, Tenant $tenant): ?Tarefa
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')
            ->andWhere('t.pasta = :pasta')
            ->andWhere('t.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As metas NÃO concluídas do escritório em que a pessoa é responsável (spec painel-acompanhamento-redistribuir,
     * fatia B), de pasta não excluída e visível a quem olha (cadeado). Filtro de escritório explícito.
     *
     * @param list<int> $pastasOcultas
     *
     * @return list<Tarefa>
     */
    public function ativasDaPessoa(Tenant $tenant, User $pessoa, array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('u = :pessoa')
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->orderBy('t.id', 'ASC');
        $this->semPastasOcultas($qb, $pastasOcultas);

        return array_values($qb->getQuery()->getResult());
    }

    /**
     * As metas pelos ids, só as deste escritório (id de outro escritório simplesmente não volta).
     *
     * @param list<int> $ids
     *
     * @return array<int, Tarefa> id => meta
     */
    public function findPorIdsDoEscritorio(array $ids, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }
        $achadas = $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.id IN (:ids)')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $porId = [];
        foreach ($achadas as $meta) {
            $porId[(int) $meta->getId()] = $meta;
        }

        return $porId;
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
     * @param list<int>            $pastasOcultas  metas de pasta oculta pelo cadeado para quem olha não contam (D1, D3)
     */
    public function countMetasAtivas(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // pasta excluída (lápide) não conta no Dashboard
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);
        $this->semPastasOcultas($qb, $pastasOcultas);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate, responsavel)
     * @param list<int>            $pastasOcultas  metas de pasta oculta pelo cadeado para quem olha não contam (D1, D3)
     * @return array{concluidas: int, total: int}
     */
    public function countMetasGlobal(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // pasta excluída (lápide) não conta no Dashboard
            ->setParameter('tenant', $tenant);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);
        $this->semPastasOcultas($qb, $pastasOcultas);

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
     * @param list<int>            $pastasOcultas  metas de pasta oculta pelo cadeado para quem olha não contam (D1, D3)
     * @return array<int, int>  userId => total (todos os status)
     */
    public function countPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // pasta excluída (lápide) não conta no Dashboard
            ->setParameter('tenant', $tenant)
            ->groupBy('u.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);
        $this->semPastasOcultas($qb, $pastasOcultas);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @param list<int>            $pastasOcultas  metas de pasta oculta pelo cadeado para quem olha não contam (D1, D3)
     * @return array<int, int>  userId => total (status != concluida)
     */
    public function countAtivasPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // pasta excluída (lápide) não conta no Dashboard
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->groupBy('u.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);
        $this->semPastasOcultas($qb, $pastasOcultas);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param list<int> $pastasOcultas metas de pasta oculta pelo cadeado para quem olha não contam (D1, D3)
     * @param \DateTimeImmutable|null $criadasDesde "Zerar" do Dashboard (DASH-014): só metas criadas a partir dele
     * @return array<int, int>  userId => total (ativas com prazo antes do dia de $referencia, RegraDoPrazo)
     */
    public function countVencidasPorResponsavel(Tenant $tenant, \DateTimeImmutable $referencia, array $pastasOcultas = [], ?\DateTimeImmutable $criadasDesde = null): array
    {
        $referencia = RegraDoPrazo::inicioDoDia($referencia);

        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // pasta excluída (lápide) não conta no Dashboard
            ->andWhere('t.status NOT IN (:foraDaFila)') // em revisão não vence nem é prazo próximo, como em Minhas Metas
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo < :referencia')
            ->setParameter('tenant', $tenant)
            ->setParameter('foraDaFila', self::FORA_DA_FILA)
            ->setParameter('referencia', $referencia)
            ->groupBy('u.id');
        $this->semPastasOcultas($qb, $pastasOcultas);
        $this->aplicarContarDesde($qb, $criadasDesde);
        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param list<int> $pastasOcultas metas de pasta oculta pelo cadeado para quem olha não contam (D1, D3)
     * @param \DateTimeImmutable|null $criadasDesde "Zerar" do Dashboard (DASH-014): só metas criadas a partir dele
     * @return array<int, int>  userId => total (ativas com prazo do dia de $referencia até 7 dias depois, RegraDoPrazo)
     */
    public function countPrazosProximosPorResponsavel(Tenant $tenant, \DateTimeImmutable $referencia, array $pastasOcultas = [], ?\DateTimeImmutable $criadasDesde = null): array
    {
        $limite = RegraDoPrazo::fimDoPrazoProximo($referencia);
        $referencia = RegraDoPrazo::inicioDoDia($referencia);

        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // pasta excluída (lápide) não conta no Dashboard
            ->andWhere('t.status NOT IN (:foraDaFila)') // em revisão não vence nem é prazo próximo, como em Minhas Metas
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo >= :referencia')
            ->andWhere('t.prazo < :limite')
            ->setParameter('tenant', $tenant)
            ->setParameter('foraDaFila', self::FORA_DA_FILA)
            ->setParameter('referencia', $referencia)
            ->setParameter('limite', $limite)
            ->groupBy('u.id');
        $this->semPastasOcultas($qb, $pastasOcultas);
        $this->aplicarContarDesde($qb, $criadasDesde);
        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Metas abertas da pessoa com prazo no dia (resumo diário por e-mail, spec docs/specs/resumo-diario-por-email.md):
     * os mesmos critérios dos números acima (pasta do escritório e fora da lixeira, fora da fila em revisão ou
     * concluída) e o cadeado. Tenant explícito: no comando de console o TenantFilter não vale.
     *
     * @param list<int> $pastasOcultas
     *
     * @return list<Tarefa>
     */
    public function abertasDoResponsavelNoDia(Tenant $tenant, User $responsavel, \DateTimeImmutable $dia, array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('p')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('t.status NOT IN (:foraDaFila)')
            ->andWhere(':responsavel MEMBER OF t.responsaveis')
            ->andWhere('t.prazo >= :de AND t.prazo < :ate')
            ->setParameter('tenant', $tenant)
            ->setParameter('foraDaFila', self::FORA_DA_FILA)
            ->setParameter('responsavel', $responsavel)
            ->setParameter('de', RegraDoPrazo::inicioDoDia($dia))
            ->setParameter('ate', RegraDoPrazo::inicioDoDia($dia)->modify('+1 day'))
            ->orderBy('t.prazo', 'ASC')
            ->addOrderBy('t.id', 'ASC');
        $this->semPastasOcultas($qb, $pastasOcultas);

        return $qb->getQuery()->getResult();
    }

    /**
     * Lista "Metas da equipe" (/tarefas/equipe): as metas que cada número da tabela Desempenho
     * do Dashboard conta. Os critérios de status são OS MESMOS dos count*PorResponsavel acima —
     * se um mudar, o outro muda junto, senão o número clicado deixa de bater com a lista
     * (DashboardEquipeContagemTest prova a paridade):
     *
     *  - todas         → todos os status, período por t.dataCriacao (countPorResponsavel);
     *  - ativas        → status != concluida, período (countAtivasPorResponsavel);
     *  - vencidas      → na fila (nem concluída nem em revisão, como Minhas Metas) com prazo antes do dia de
     *                    $referencia, SEM período (countVencidasPorResponsavel);
     *  - prazo_proximo → na fila com prazo do dia de $referencia até 7 dias depois (RegraDoPrazo), SEM período
     *                    (countPrazosProximosPorResponsavel);
     *  - concluidas    → status = concluida, período (coluna extra "Metas concluídas", que é
     *                    countPorResponsavel − countAtivasPorResponsavel);
     *  - em_revisao    → status = em_revisao, período (coluna extra "Em revisão",
     *                    MetricasExtrasDoDashboardRepository::contarEmRevisaoPorResponsavel).
     *
     * $responsavelIds é o universo de colaboradores elegíveis (ativos no escritório, já
     * estreitados por responsável/cargo pelo UseCase). A meta entra se QUALQUER responsável dela
     * estiver no universo — cada meta aparece uma vez, mesmo com vários responsáveis.
     * Universo vazio devolve lista vazia (nunca "sem filtro").
     *
     * @param list<int>            $responsavelIds
     * @param array<string, mixed> $filtros         data_de, data_ate (Y-m-d)
     * @param list<int>            $pastasOcultas   metas de pasta oculta pelo cadeado não entram (D3)
     *
     * @return Paginator<Tarefa>
     */
    public function findMetasDaEquipePaginado(
        Tenant $tenant,
        array $responsavelIds,
        string $status,
        array $filtros,
        \DateTimeImmutable $referencia,
        int $pagina,
        int $porPagina,
        array $pastasOcultas = [],
    ): Paginator {
        // Id 0 não existe: universo vazio vira "nenhuma meta", e não um IN () inválido.
        $ids = $responsavelIds === [] ? [0] : array_values($responsavelIds);

        $qb = $this->createQueryBuilder('t')
            ->addSelect('p', 'r')
            ->join('t.pasta', 'p')
            ->leftJoin('t.responsaveis', 'r')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // pasta excluída (lápide) não conta no Dashboard
            ->andWhere('t.id IN (SELECT t_eq.id FROM ' . Tarefa::class . ' t_eq JOIN t_eq.responsaveis u_eq WHERE u_eq.id IN (:fEquipeIds))')
            ->setParameter('tenant', $tenant)
            ->setParameter('fEquipeIds', $ids)
            ->orderBy('t.prazo', 'ASC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult((max(1, $pagina) - 1) * $porPagina)
            ->setMaxResults($porPagina);
        $this->semPastasOcultas($qb, $pastasOcultas);

        switch ($status) {
            case 'ativas':
                $qb->andWhere('t.status != :concluida')
                   ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);
                $this->aplicarFiltrosDashboard($qb, $filtros, false);
                break;

            case 'vencidas':
                $qb->andWhere('t.status NOT IN (:foraDaFila)')
                   ->andWhere('t.prazo IS NOT NULL')
                   ->andWhere('t.prazo < :referencia')
                   ->setParameter('foraDaFila', self::FORA_DA_FILA)
                   ->setParameter('referencia', RegraDoPrazo::inicioDoDia($referencia));
                break;

            case 'prazo_proximo':
                $qb->andWhere('t.status NOT IN (:foraDaFila)')
                   ->andWhere('t.prazo IS NOT NULL')
                   ->andWhere('t.prazo >= :referencia')
                   ->andWhere('t.prazo < :limite')
                   ->setParameter('foraDaFila', self::FORA_DA_FILA)
                   ->setParameter('referencia', RegraDoPrazo::inicioDoDia($referencia))
                   ->setParameter('limite', RegraDoPrazo::fimDoPrazoProximo($referencia));
                break;

            case 'concluidas':
                $qb->andWhere('t.status = :concluida')
                   ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);
                $this->aplicarFiltrosDashboard($qb, $filtros, false);
                break;

            case 'em_revisao':
                $qb->andWhere('t.status = :emRevisao')
                   ->setParameter('emRevisao', Tarefa::STATUS_EM_REVISAO);
                $this->aplicarFiltrosDashboard($qb, $filtros, false);
                break;

            default: // 'todas'
                $this->aplicarFiltrosDashboard($qb, $filtros, false);
        }

        return new Paginator($qb, true);
    }

    /**
     * Lista do "ver metas" do CARD Metas ativas (DASH-006, `escopo=card` em /tarefas/equipe):
     * EXATAMENTE as metas que countMetasAtivas conta — status != concluída, do escritório (pela
     * pasta), criadas no período e, com `responsavel`, só as daquele responsável. Diferente da
     * findMetasDaEquipePaginado, NÃO recorta pelo universo de colaboradores ativos: o card conta
     * também meta sem responsável e meta de quem já saiu do escritório. Se countMetasAtivas mudar
     * de critério, este muda junto (DashboardCardsVerParidadeTest prova a paridade).
     *
     * @param array<string, mixed> $filtros data_de, data_ate (Y-m-d), responsavel (userId)
     *
     * @return Paginator<Tarefa>
     */
    /** @param list<int> $pastasOcultas metas de pasta oculta pelo cadeado não entram (D3) */
    public function findMetasAtivasDoCardPaginado(Tenant $tenant, array $filtros, int $pagina, int $porPagina, array $pastasOcultas = []): Paginator
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('p', 'r')
            ->join('t.pasta', 'p')
            ->leftJoin('t.responsaveis', 'r')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // pasta excluída (lápide) não conta no Dashboard
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->orderBy('t.prazo', 'ASC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult((max(1, $pagina) - 1) * $porPagina)
            ->setMaxResults($porPagina);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);
        $this->semPastasOcultas($qb, $pastasOcultas);

        return new Paginator($qb, true);
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

        $this->aplicarContarDesde($qb, $filtros['contar_desde'] ?? null);

        if ($filtrarResponsavel) {
            $resp = (int) ($filtros['responsavel'] ?? 0);
            if ($resp > 0) {
                $qb->join('t.responsaveis', 'u_dash')
                   ->andWhere('u_dash.id = :fResp')
                   ->setParameter('fResp', $resp);
            }
        }
    }

    /**
     * "Zerar" do Dashboard (DASH-014): só as metas criadas a partir do ponto de corte. Junto com o
     * `data_de`, a contagem começa no mais recente dos dois.
     */
    private function aplicarContarDesde(QueryBuilder $qb, mixed $desde): void
    {
        if ($desde instanceof \DateTimeImmutable) {
            $qb->andWhere('t.dataCriacao >= :fContarDesde')->setParameter('fContarDesde', $desde);
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

    /**
     * Título e descrição das metas das pastas pedidas, para o relatório de pastas sem processo
     * (desenho 1.2.3, `spAnalisar`: fonte "Meta"). Uma consulta para o lote; tenant pela pasta.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, list<string>> pastaId => "título descrição"
     */
    public function textosParaAnaliseSemProcesso(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.pasta) AS pasta_id', 't.titulo', 't.descricao')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $pastaIds)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $porPasta = [];
        foreach ($linhas as $l) {
            $porPasta[(int) $l['pasta_id']][] = trim($l['titulo'] . ' ' . $l['descricao']);
        }

        return $porPasta;
    }

    /**
     * Metas que o backfill `app:tarefa:herdar-prioridade-da-pasta` mudaria, por escritório: ainda
     * `normal` e numa pasta de prioridade que vira outra coisa (`PrioridadeDaMeta::daPasta`). Roda sem
     * sessão (sem TenantFilter): o recorte por escritório é explícito, na meta e na pasta.
     *
     * @return array<int, int> tenant_id => metas a herdar
     */
    public function contarHerancaDePrioridadePendente(?int $tenantId = null): array
    {
        $dePara = $this->deParaDaPrioridadeDaPasta();
        $sql    = 'SELECT t.tenant_id, COUNT(*) AS total FROM tarefa t JOIN pasta p ON p.id = t.pasta_id AND p.tenant_id = t.tenant_id'
            . ' WHERE t.prioridade = ? AND p.prioridade IN (' . $this->marcadores(count($dePara)) . ')';
        $valores = [PrioridadeDaMeta::Normal->value, ...array_keys($dePara)];
        if ($tenantId !== null) {
            $sql .= ' AND t.tenant_id = ?';
            $valores[] = $tenantId;
        }

        $pendentes = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql . ' GROUP BY t.tenant_id ORDER BY t.tenant_id', $valores) as $linha) {
            $pendentes[(int) $linha['tenant_id']] = (int) $linha['total'];
        }

        return $pendentes;
    }

    /**
     * Grava a herança da prioridade da pasta nas metas de UM escritório que ainda estão `normal`.
     * Idempotente: a meta que já herdou (ou que alguém mudou) não é mais `normal` e não volta a ser
     * tocada. SQL direto, como o `numerar-metas`: não passa pela trilha de auditoria.
     *
     * @return int metas atualizadas
     */
    public function herdarPrioridadeDaPasta(int $tenantId): int
    {
        $dePara = $this->deParaDaPrioridadeDaPasta();
        $quando = [];
        foreach ($dePara as $daPasta => $daMeta) {
            array_push($quando, $daPasta, $daMeta);
        }
        $sql = 'UPDATE tarefa AS t SET prioridade = CASE p.prioridade ' . str_repeat('WHEN ? THEN ? ', count($dePara)) . 'END FROM pasta AS p'
            . ' WHERE p.id = t.pasta_id AND p.tenant_id = t.tenant_id AND t.tenant_id = ? AND t.prioridade = ?'
            . ' AND p.prioridade IN (' . $this->marcadores(count($dePara)) . ')';
        $valores = [...$quando, $tenantId, PrioridadeDaMeta::Normal->value, ...array_keys($dePara)];

        return (int) $this->getEntityManager()->getConnection()->executeStatement($sql, $valores);
    }

    /**
     * O de-para vem do enum (`PrioridadeDaMeta::daPasta`), para o SQL nunca discordar da criação. Só as
     * prioridades de pasta que mudam a meta entram.
     *
     * @return array<string, string> prioridade da pasta => prioridade da meta
     */
    private function deParaDaPrioridadeDaPasta(): array
    {
        $dePara = [];
        foreach (PrioridadePasta::cases() as $daPasta) {
            $daMeta = PrioridadeDaMeta::daPasta($daPasta);
            if ($daMeta !== PrioridadeDaMeta::Normal) {
                $dePara[$daPasta->value] = $daMeta->value;
            }
        }

        return $dePara;
    }

    private function marcadores(int $quantos): string
    {
        return implode(', ', array_fill(0, $quantos, '?'));
    }
}
