<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\DTO\MetaDePastasDoMesOutput;
use App\Dashboard\Meta\MontarMetaDePastasDoMes;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use App\Dashboard\Ranking\RankingDeMetasConcluidas;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\Zeramento\ColunasZeraveis;
use App\Dashboard\Zeramento\CortesDoDashboard;
use App\Dashboard\Zeramento\RecontagemComCorte;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;

final class ObterDadosDashboardUseCase
{
    /**
     * Valor reservado do filtro `cargo` para "colaborador sem cargo". Não colide com nome de
     * cargo real e mantém o filtro antigo (`cargo=<nome>`) funcionando igual.
     */
    public const CARGO_SEM = '__sem__';

    /** Teto do termo de busca: o que passar disso não ajuda a achar ninguém. */
    private const BUSCA_MAX = 100;

    /**
     * Colunas que o cabeçalho da tabela deixa ordenar: chave usada no `data-ordenar` do <th>
     * => propriedade da linha. A chave chega pela URL, então tudo que não estiver aqui é
     * ignorado (ver ordenar()).
     */
    private const ORDENAVEIS = [
        'advogado'        => 'nomeAdvogado',
        'cargo'           => 'cargoNome',
        'metas'           => 'totalMetas',
        'metas_ativas'    => 'metasAtivas',
        'metas_vencidas'  => 'metasVencidas',
        'prazos'          => 'prazosProximos',
        'demandas'        => 'totalDemandas',
        'demandas_ativas' => 'demandasAtivas',
        'pastas_criadas'  => 'pastasCriadas',
    ];

    public function __construct(
        private readonly PastaRepository  $pastaRepository,
        private readonly TarefaRepository $tarefaRepository,
        private readonly UserRepository   $userRepository,
        private readonly DashboardFotoRepository $dashboardFotoRepository,
        private readonly MetricasExtrasDoDashboardRepository $metricasExtras,
        private readonly DashboardZeramentoRepository $zeramentos,
        private readonly MontarMetaDePastasDoMes $metaDePastas,
    ) {}

    /**
     * @param array<string, mixed> $filtros  filtro global do painel: data_de, data_ate,
     *                                        responsavel (userId), cargo (nome, ou CARGO_SEM
     *                                        para quem não tem cargo), busca (trecho do nome).
     *                                        Período e responsável recalculam cards + linhas;
     *                                        cargo e responsável estreitam quais colaboradores
     *                                        aparecem; a busca só esconde linhas (não mexe em
     *                                        card nenhum). Vencidas/prazos próximos seguem
     *                                        relativos a $referencia. `ordenar`/`direcao`
     *                                        ordenam a tabela pela coluna clicada no cabeçalho.
     * @param array<mixed>         $colunasExtras colunas do "Adicionar coluna" que o usuário ligou
     *                                        (preferência `dashboard.colunas_extras`), na ordem
     *                                        da tabela. Só essas são calculadas — uma consulta
     *                                        agregada por métrica, no máximo; chave fora do
     *                                        catálogo é ignorada.
     * @param list<int>            $pastasOcultas pastas ocultas pelo cadeado para quem olha
     *                                        (`AcessoAPasta::idsOcultosPara`, uma consulta por
     *                                        requisição): as metas delas saem de todo card e
     *                                        coluna de metas, como da lista /tarefas/equipe
     *                                        (spec pasta-cadeado D1 e D3). Vazio = sem cadeado.
     */
    public function executar(Tenant $tenant, \DateTimeImmutable $referencia, array $filtros = [], array $colunasExtras = [], array $pastasOcultas = []): DashboardOutput
    {
        $colunasExtras = Extras::filtrar($colunasExtras);

        // CARDS
        $totalMetasAtivas  = $this->tarefaRepository->countMetasAtivas($tenant, $filtros, $pastasOcultas);
        $demandasUrgentes  = $this->pastaRepository->countUrgentes($tenant, $filtros, $pastasOcultas);
        $global            = $this->tarefaRepository->countMetasGlobal($tenant, $filtros, $pastasOcultas);
        $metaGlobalPercent = $global['total'] > 0
            ? (int) round($global['concluidas'] / $global['total'] * 100)
            : 0;

        $colaboradores = $this->userRepository->findColaboradoresAtivosPorTenant($tenant); // lista canônica: todos os ativos do tenant
        $mCargo        = $colaboradores === [] ? [] : $this->userRepository->findCargoPorColaboradores($tenant);
        $todosIds      = array_map(static fn ($u): int => (int) $u->getId(), $colaboradores); // universo do troféu: antes de busca, responsável e cargo

        // 7 mapas userId => count (mapasDaTabela), já com o corte do "Zerar" (DASH-014) de quem tem.
        $cortes = CortesDoDashboard::deZeramentos($this->zeramentos->listarAtivos($tenant));
        ['metas' => $mTotalTarefa, 'metas_ativas' => $mAtivasTarefa, 'metas_vencidas' => $mVencidas, 'prazos' => $mPrazos, 'demandas' => $mTotalPasta,
            'demandas_ativas' => $mAtivasPasta, 'pastas_criadas' => $mCriadasPasta, 'bruto' => $bruto] = $this->mapasDaTabela($tenant, $referencia, $filtros, $pastasOcultas, $cortes, $todosIds);
        $conclusao = $this->basesDaConclusao($tenant, $filtros, $pastasOcultas, $cortes, $todosIds, $bruto); // Concluídas e Taxa: só o próprio corte

        // Facetas de linha: responsável reduz a um colaborador; cargo reduz ao cargo escolhido.
        $respId = (int) ($filtros['responsavel'] ?? 0);
        if ($respId > 0) {
            $colaboradores = array_values(array_filter($colaboradores, static fn ($u) => $u->getId() === $respId));
        }

        $colaboradores = $this->filtrarPorCargo($colaboradores, $mCargo, trim((string) ($filtros['cargo'] ?? '')));

        // Período anterior de mesma duração (só com período completo): os MESMOS count* de Total e Criadas, com as datas trocadas.
        $periodoAnterior = $this->periodoAnterior($filtros, $referencia);
        $comAnterior     = $periodoAnterior !== null;
        $mAntTarefa      = [];
        $mAntPasta       = [];
        $mAntCriadas     = [];
        if ($comAnterior && $colaboradores !== []) {
            $filtrosAnteriores = array_merge($filtros, $periodoAnterior);
            $mAntTarefa        = $this->tarefaRepository->countPorResponsavel($tenant, $filtrosAnteriores, $pastasOcultas);
            $mAntPasta         = $this->pastaRepository->countPorResponsavel($tenant, $filtrosAnteriores, $pastasOcultas);
            $mAntCriadas       = $this->pastaRepository->countCriadasPorCriador($tenant, $filtrosAnteriores, $pastasOcultas);
        }

        // Estoque anterior (vencidas, prazos, ativas, revisão, urgentes): não se reconstrói do passado, então vem
        // da foto diária de `data_de − 1`. Sem foto da pessoa (ou coluna NULL), sem tendência. Cadeado: a foto é
        // do escritório inteiro; com pasta oculta compará-la revelaria a diferença (oráculo), então sem seta.
        $mEstoqueAnterior = [];
        if ($comAnterior && $colaboradores !== [] && $pastasOcultas === []) {
            $mEstoqueAnterior = $this->dashboardFotoRepository->buscarPorReferencia(
                $tenant,
                new \DateTimeImmutable($periodoAnterior['data_ate']),
                array_map(static fn ($u): int => (int) $u->getId(), $colaboradores),
            );
        }

        $estoqueHoje = $mEstoqueAnterior === [] ? [] : $this->estoqueDeHoje($tenant, $colunasExtras, $pastasOcultas); // PR-F

        $mFoto = $colaboradores === [] ? [] : $this->userRepository->findFotoPorColaboradores($tenant);

        $mExtras = $conclusao + ($colaboradores === [] ? [] : $this->mapasExtras(
            $tenant,
            $filtros,
            $colunasExtras,
            array_map(static fn ($u): int => (int) $u->getId(), $colaboradores),
            $pastasOcultas,
            $cortes,
        ));

        // Tendência das extras com lastro por data (hoje só Eventos na agenda): a MESMA consulta
        // no período anterior de mesma duração — a regra das setas que já existem. Concluídas,
        // taxa e tempo médio ficam sem seta (DASH-010b, ver ColunasExtrasDoDashboard::TENDENCIA)
        // e, por isso, sem consulta do período anterior.
        $extrasComTendencia = $comAnterior ? Extras::comTendencia($colunasExtras) : [];
        $mExtrasAnt         = [];
        if ($extrasComTendencia !== [] && $colaboradores !== []) {
            $mExtrasAnt = $this->mapasExtras(
                $tenant,
                array_merge($filtros, $periodoAnterior),
                $extrasComTendencia,
                array_map(static fn ($u): int => (int) $u->getId(), $colaboradores),
                $pastasOcultas,
            );
        }

        // Montar linhas — colaborador sem tarefa/pasta aparece com zeros
        $linhas = [];
        foreach ($colaboradores as $user) {
            $id       = $user->getId();
            $estoque  = $mEstoqueAnterior[$id] ?? null;
            $linhas[] = new LinhaAdvogadoDashboardOutput(
                userId:                $id,
                nomeAdvogado:          $user->getFullName(),
                cargoNome:             $mCargo[$id] ?? null,
                fotoUrl:               $mFoto[$id]  ?? null,
                totalMetas:            $mTotalTarefa[$id]  ?? 0,
                metasAtivas:           $mAtivasTarefa[$id] ?? 0,
                metasVencidas:         $mVencidas[$id]     ?? 0,
                prazosProximos:        $mPrazos[$id]       ?? 0,
                totalDemandas:         $mTotalPasta[$id]   ?? 0,
                demandasAtivas:        $mAtivasPasta[$id]  ?? 0,
                pastasCriadas:         $mCriadasPasta[$id] ?? 0,
                totalMetasAnterior:    $comAnterior ? ($mAntTarefa[$id]  ?? 0) : null,
                totalDemandasAnterior: $comAnterior ? ($mAntPasta[$id]   ?? 0) : null,
                pastasCriadasAnterior: $comAnterior ? ($mAntCriadas[$id] ?? 0) : null,
                metasVencidasAnterior:  $estoque['metas_vencidas']  ?? null,
                prazosProximosAnterior: $estoque['prazos_proximos'] ?? null,
                extras:                $this->valoresExtras(
                    $colunasExtras,
                    $mExtras,
                    $id,
                    $mTotalTarefa[$id] ?? 0,
                    $mAtivasTarefa[$id] ?? 0,
                ),
                // Só extras com consulta própria (Eventos) têm tendência: total/ativas não entram.
                extrasAnteriores:      $this->valoresExtras($extrasComTendencia, $mExtrasAnt, $id, 0, 0),
                estoque:               $this->estoqueDaLinha($estoqueHoje, $id, $estoque),
                colunasZeradas:        $cortes->colunasDe($id),
            );
        }

        // O card "Pastas criadas" é a soma das linhas (e não um count sem agrupar): assim ele
        // respeita responsável e cargo do mesmo jeito que a tabela, e bate com a coluna.
        // Calculado ANTES da busca: buscar um nome não muda card nenhum (desenho).
        $totalPastasCriadas         = $this->somar($linhas, 'pastasCriadas');
        $totalPastasCriadasAnterior = $comAnterior ? $this->somar($linhas, 'pastasCriadasAnterior') : null;

        // Busca por nome: só esconde linhas. A linha de Total (e a tendência dela) soma o que
        // ficou visível, como no desenho.
        $linhas = $this->aplicarBusca($linhas, (string) ($filtros['busca'] ?? ''));

        $totaisAnteriores = $comAnterior
            ? [
                'metas'          => $this->somar($linhas, 'totalMetasAnterior'),
                'demandas'       => $this->somar($linhas, 'totalDemandasAnterior'),
                'pastas_criadas' => $this->somar($linhas, 'pastasCriadasAnterior'),
                // Estoque: só com foto de TODAS as linhas visíveis (ver DashboardOutput).
                'metas_vencidas'  => $this->somarSeTodos($linhas, 'metasVencidasAnterior'),
                'prazos'          => $this->somarSeTodos($linhas, 'prazosProximosAnterior'),
            ]
            : null;

        return new DashboardOutput(
            $totalMetasAtivas,
            $demandasUrgentes,
            $metaGlobalPercent,
            $this->ordenar($linhas, $filtros, $colunasExtras),
            totalPastasCriadas:         $totalPastasCriadas,
            metasConcluidas:            $global['concluidas'],
            metasTotal:                 $global['total'],
            totaisAnteriores:           $totaisAnteriores,
            totalPastasCriadasAnterior: $totalPastasCriadasAnterior,
            periodoAnterior:            $periodoAnterior,
            colunasExtras:              $colunasExtras,
            totaisExtras:               $this->totaisExtras($colunasExtras, $linhas, false, $mExtras[Extras::TAXA_CONCLUSAO] ?? []),
            totaisExtrasAnteriores:     $comAnterior
                ? $this->totaisExtras($extrasComTendencia, $linhas, true)
                : null,
            campeaoId:                  RankingDeMetasConcluidas::campeao($todosIds, self::concluidasDaBase($conclusao[Extras::METAS_CONCLUIDAS]), $mVencidas),
            zeramentos:                 $cortes->paraTela(),
            // O realizado da meta é o número do card (antes da busca), sem regra de contagem própria.
            totaisEstoque:              $estoqueHoje === [] ? null : $this->totaisDoEstoque($linhas, array_keys($estoqueHoje)),
            metaDePastas:               $this->metaDoMes($tenant, $referencia, $filtros, $totalPastasCriadas, $pastasOcultas),
        );
    }

    /**
     * Cadeado: a meta é do escritório inteiro e o realizado sairia sem as pastas ocultas; qualquer número derivado
     * (faltam, %, ritmo, "de N") revelaria o total. Com pasta oculta, a meta nem é montada.
     *
     * @param array<string, mixed> $filtros
     * @param list<int>            $pastasOcultas
     */
    private function metaDoMes(Tenant $tenant, \DateTimeImmutable $referencia, array $filtros, int $realizado, array $pastasOcultas): ?MetaDePastasDoMesOutput
    {
        return $pastasOcultas !== [] ? null : $this->metaDePastas->montar($tenant, $referencia, $filtros, $realizado);
    }

    /** Colunas do estoque com seta pela foto => coluna da `dashboard_foto` (as duas fixas mais ESTOQUE_COM_FOTO). */
    private const ESTOQUE_NA_FOTO = ['metas_ativas' => 'metas_ativas', 'demandas_ativas' => 'demandas_ativas'];

    /**
     * O estoque de HOJE por coluna, sem período e com a mesma consulta da foto: coluna => userId => n.
     * Metas ativas e Demandas ativas sempre; Em revisão e Pastas urgentes só se estiverem ligadas.
     *
     * @param list<string> $colunasExtras
     * @param list<int>    $pastasOcultas
     *
     * @return array<string, array<int, int>>
     */
    private function estoqueDeHoje(Tenant $tenant, array $colunasExtras, array $pastasOcultas): array
    {
        $hoje = [
            'metas_ativas'    => $this->tarefaRepository->countAtivasPorResponsavel($tenant, [], $pastasOcultas),
            'demandas_ativas' => $this->pastaRepository->countAtivasPorResponsavel($tenant, [], $pastasOcultas),
        ];
        foreach (array_keys(Extras::ESTOQUE_COM_FOTO) as $coluna) {
            if (in_array($coluna, $colunasExtras, true)) {
                $hoje[$coluna] = $this->consultarExtra($coluna, $tenant, [], [], $pastasOcultas);
            }
        }

        return $hoje;
    }

    /**
     * O estoque da linha: o de hoje por coluna e a foto de `data_de − 1` desta pessoa; null onde não há
     * lastro (sem foto dela, ou foto de antes do PR-F com a coluna NULL).
     *
     * @param array<string, array<int, int>> $estoqueHoje
     * @param array<string, int|null>|null   $foto
     *
     * @return array{atual: array<string, int>, anterior: array<string, int|null>}
     */
    private function estoqueDaLinha(array $estoqueHoje, int $id, ?array $foto): array
    {
        $linha = ['atual' => [], 'anterior' => []];
        foreach ($estoqueHoje as $coluna => $mapa) {
            $campo                     = self::ESTOQUE_NA_FOTO[$coluna] ?? Extras::ESTOQUE_COM_FOTO[$coluna]['foto'];
            $linha['atual'][$coluna]    = (int) ($mapa[$id] ?? 0);
            $linha['anterior'][$coluna] = $foto[$campo] ?? null;
        }

        return $linha;
    }

    /**
     * Total do estoque sobre as linhas VISÍVEIS: o de hoje soma tudo; a foto, só com lastro em toda linha.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     * @param list<string>                   $colunas
     *
     * @return array{atual: array<string, int>, anterior: array<string, int|null>}
     */
    private function totaisDoEstoque(array $linhas, array $colunas): array
    {
        $totais = ['atual' => [], 'anterior' => []];
        foreach ($colunas as $coluna) {
            $totais['atual'][$coluna] = array_sum(array_map(static fn (LinhaAdvogadoDashboardOutput $l): int => $l->estoque['atual'][$coluna] ?? 0, $linhas));
            $anteriores = array_map(static fn (LinhaAdvogadoDashboardOutput $l): ?int => $l->estoque['anterior'][$coluna] ?? null, $linhas);
            $totais['anterior'][$coluna] = $linhas === [] || in_array(null, $anteriores, true) ? null : array_sum($anteriores);
        }

        return $totais;
    }

    /**
     * As 7 colunas fixas, userId => count: período aplicado (vencidas e prazos são relativos à
     * referência) e, para quem tem "Zerar" ativo na coluna, recontadas a partir do corte. O corte
     * vale para todo o universo do troféu, então o troféu também respeita o zeramento (desenho).
     * Em `bruto`, os mesmos 7 mapas sem corte nenhum (a base de Concluídas e Taxa).
     *
     * @param array<string, mixed> $filtros
     * @param list<int>            $pastasOcultas
     * @param list<int>            $pessoas
     *
     * @return array<string, array<array-key, mixed>>
     */
    private function mapasDaTabela(Tenant $tenant, \DateTimeImmutable $referencia, array $filtros, array $pastasOcultas, CortesDoDashboard $cortes, array $pessoas): array
    {
        $mapas = ['bruto' => []];
        foreach (array_keys(ColunasZeraveis::FIXAS) as $coluna) {
            $mapas['bruto'][$coluna] = $this->contarColunaFixa($coluna, $tenant, $referencia, $filtros, $pastasOcultas);
            $mapas[$coluna] = RecontagemComCorte::aplicar(
                $mapas['bruto'][$coluna],
                $cortes,
                $coluna,
                $pessoas,
                fn (\DateTimeImmutable $desde): array => $this->contarColunaFixa($coluna, $tenant, $referencia, ['contar_desde' => $desde] + $filtros, $pastasOcultas),
            );
        }

        return $mapas;
    }

    /**
     * Uma coluna fixa. "Pastas criadas" é por CRIADOR, não por responsável: mede quem abriu a pasta
     * (uso do sistema e produtividade), enquanto Total e Demandas ativas medem quem responde por ela.
     *
     * @param array<string, mixed> $filtros `contar_desde` (DateTimeImmutable) = o corte do "Zerar"
     * @param list<int>            $pastasOcultas
     *
     * @return array<int, int>
     */
    private function contarColunaFixa(string $coluna, Tenant $tenant, \DateTimeImmutable $referencia, array $filtros, array $pastasOcultas): array
    {
        $desde = $filtros['contar_desde'] ?? null;

        return match ($coluna) {
            ColunasZeraveis::METAS           => $this->tarefaRepository->countPorResponsavel($tenant, $filtros, $pastasOcultas),
            ColunasZeraveis::METAS_ATIVAS    => $this->tarefaRepository->countAtivasPorResponsavel($tenant, $filtros, $pastasOcultas),
            ColunasZeraveis::METAS_VENCIDAS  => $this->tarefaRepository->countVencidasPorResponsavel($tenant, $referencia, $pastasOcultas, $desde),
            ColunasZeraveis::PRAZOS          => $this->tarefaRepository->countPrazosProximosPorResponsavel($tenant, $referencia, $pastasOcultas, $desde),
            ColunasZeraveis::DEMANDAS        => $this->pastaRepository->countPorResponsavel($tenant, $filtros, $pastasOcultas),
            ColunasZeraveis::DEMANDAS_ATIVAS => $this->pastaRepository->countAtivasPorResponsavel($tenant, $filtros, $pastasOcultas),
            default                          => $this->pastaRepository->countCriadasPorCriador($tenant, $filtros, $pastasOcultas),
        };
    }

    /**
     * Uma consulta agregada por métrica ligada. "Metas concluídas" e "Taxa de conclusão" não
     * consultam nada: saem de Total metas − Metas ativas, que já estão calculadas.
     *
     * @param array<string, mixed> $filtros
     * @param list<string>         $colunasExtras
     * @param list<int>            $pessoas
     * @param list<int>            $pastasOcultas metas destas pastas não contam (cadeado, D1 e D3)
     *
     * @return array<string, array<int, mixed>>
     */
    private function mapasExtras(Tenant $tenant, array $filtros, array $colunasExtras, array $pessoas, array $pastasOcultas = [], ?CortesDoDashboard $cortes = null): array
    {
        $cortes ??= CortesDoDashboard::nenhum();
        $mapas  = [];
        foreach ($colunasExtras as $coluna) {
            $mapas[$coluna] = RecontagemComCorte::aplicar(
                $this->consultarExtra($coluna, $tenant, $filtros, $pessoas, $pastasOcultas),
                $cortes,
                $coluna,
                $pessoas,
                fn (\DateTimeImmutable $desde): array => $this->consultarExtra($coluna, $tenant, ['contar_desde' => $desde] + $filtros, $pessoas, $pastasOcultas),
            );
        }

        return $mapas;
    }

    /**
     * @param array<string, mixed> $filtros
     * @param list<int>            $pessoas
     * @param list<int>            $pastasOcultas
     *
     * @return array<int, mixed>
     */
    private function consultarExtra(string $coluna, Tenant $tenant, array $filtros, array $pessoas, array $pastasOcultas): array
    {
        return match ($coluna) {
            Extras::METAS_REVISAO     => $this->metricasExtras->contarEmRevisaoPorResponsavel($tenant, $filtros, $pastasOcultas),
            Extras::TEMPO_MEDIO       => $this->metricasExtras->tempoDeConclusaoPorResponsavel($tenant, $filtros, $pastasOcultas),
            Extras::PASTAS_URGENTES   => $this->metricasExtras->contarUrgentesPorResponsavel($tenant, $filtros, $pastasOcultas),
            Extras::PASTAS_CONCLUIDAS => $this->metricasExtras->contarFinalizadasPorResponsavel($tenant, $filtros, $pastasOcultas),
            Extras::EVENTOS_AGENDA    => $this->metricasExtras->contarEventosPorPessoa($tenant, $filtros, $pessoas),
            Extras::RELATORIO         => $this->metricasExtras->contarRegistrosPorAutor($tenant, $filtros, $pastasOcultas),
            default                   => [],
        };
    }

    /**
     * Bases de "Metas concluídas" e da "Taxa de conclusão" (Total metas e Metas ativas), por pessoa
     * do universo, as duas com o MESMO corte: o da própria coluna (o da pessoa ou o da equipe, vale o
     * mais recente). Zerar só Total metas ou só Metas ativas não mexe aqui (nem no troféu): a base sem
     * corte vem do `bruto` da tabela, e só quem tem corte na coluna é recontado.
     *
     * @param array<string, mixed>              $filtros
     * @param list<int>                         $pastasOcultas
     * @param list<int>                         $pessoas
     * @param array<array-key, mixed>          $bruto os 7 mapas da tabela sem corte (coluna => userId => n)
     *
     * @return array<string, array<int, array{total: int, ativas: int}>>
     */
    private function basesDaConclusao(Tenant $tenant, array $filtros, array $pastasOcultas, CortesDoDashboard $cortes, array $pessoas, array $bruto): array
    {
        $bases = [];
        foreach ([Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO] as $coluna) {
            $base = [];
            foreach ($pessoas as $id) {
                $base[$id] = ['total' => (int) ($bruto[ColunasZeraveis::METAS][$id] ?? 0), 'ativas' => (int) ($bruto[ColunasZeraveis::METAS_ATIVAS][$id] ?? 0)];
            }
            foreach ($cortes->grupos($coluna, $pessoas) as $grupo) {
                $comCorte = ['contar_desde' => $grupo['desde']] + $filtros;
                $total    = $this->tarefaRepository->countPorResponsavel($tenant, $comCorte, $pastasOcultas);
                $ativas   = $this->tarefaRepository->countAtivasPorResponsavel($tenant, $comCorte, $pastasOcultas);
                foreach ($grupo['pessoas'] as $id) {
                    $base[$id] = ['total' => $total[$id] ?? 0, 'ativas' => $ativas[$id] ?? 0];
                }
            }
            $bases[$coluna] = $base;
        }

        return $bases;
    }

    /**
     * @param array<int, array{total: int, ativas: int}> $base
     *
     * @return array<int, int>
     */
    private static function concluidasDaBase(array $base): array
    {
        return array_map(static fn (array $b): int => max(0, $b['total'] - $b['ativas']), $base);
    }

    /**
     * Valor de cada coluna extra ligada para UMA pessoa. Contagem sem registro é 0 (zero é dado);
     * taxa sem meta no período e tempo médio sem meta concluída com data são null ("—"): não há
     * o que medir, e o painel não inventa número.
     *
     * @param list<string>                     $colunasExtras
     * @param array<string, array<int, mixed>> $mExtras
     *
     * @return array<string, int|null>
     */
    private function valoresExtras(array $colunasExtras, array $mExtras, int $id, int $totalMetas, int $metasAtivas): array
    {
        $valores = [];
        foreach ($colunasExtras as $coluna) {
            $base = $mExtras[$coluna][$id] ?? ['total' => $totalMetas, 'ativas' => $metasAtivas];
            $valores[$coluna] = match ($coluna) {
                Extras::METAS_CONCLUIDAS => max(0, $base['total'] - $base['ativas']),
                Extras::TAXA_CONCLUSAO   => $base['total'] > 0
                    ? (int) round(max(0, $base['total'] - $base['ativas']) / $base['total'] * 100)
                    : null,
                Extras::TEMPO_MEDIO      => isset($mExtras[$coluna][$id]) && $mExtras[$coluna][$id]['metas'] > 0
                    ? (int) round($mExtras[$coluna][$id]['dias'] / $mExtras[$coluna][$id]['metas'])
                    : null,
                default                  => (int) ($mExtras[$coluna][$id] ?? 0),
            };
        }

        return $valores;
    }

    /**
     * Total de cada coluna extra sobre as linhas VISÍVEIS (depois da busca), como a linha de Total:
     * contagem = soma; taxa = Σ concluídas ÷ Σ metas; tempo médio = média das linhas com tempo > 0
     * (a regra do desenho, dc 1.2.2 L.1980). Sem base, null ("—").
     *
     * Com $anterior, a MESMA regra sobre o período anterior, com os valores de
     * `extrasAnteriores` de cada linha — só para as extras de ColunasExtrasDoDashboard::TENDENCIA
     * (hoje, Eventos na agenda: uma soma).
     *
     * $baseDaTaxa: as bases da Taxa de conclusão com o corte da própria coluna (basesDaConclusao).
     *
     * @param list<string>                                 $colunasExtras
     * @param LinhaAdvogadoDashboardOutput[]               $linhas
     * @param array<int, array{total: int, ativas: int}>   $baseDaTaxa
     *
     * @return array<string, int|null>
     */
    private function totaisExtras(array $colunasExtras, array $linhas, bool $anterior = false, array $baseDaTaxa = []): array
    {
        $campo  = $anterior ? 'extrasAnteriores' : 'extras';
        $totais = [];
        foreach ($colunasExtras as $coluna) {
            if ($coluna === Extras::TAXA_CONCLUSAO) {
                $totais[$coluna] = $this->taxaDoTotal($linhas, $baseDaTaxa);

                continue;
            }

            if ($coluna === Extras::TEMPO_MEDIO) {
                // Como o desenho (dc 1.2.2 L.1980): média das LINHAS com tempo > 0, sobre o valor
                // que cada linha mostra — não a média ponderada pelas metas.
                $tempos = array_values(array_filter(
                    array_map(static fn (LinhaAdvogadoDashboardOutput $l): int => (int) (($l->{$campo})[$coluna] ?? 0), $linhas),
                    static fn (int $dias): bool => $dias > 0,
                ));
                $totais[$coluna] = $tempos !== [] ? (int) round(array_sum($tempos) / count($tempos)) : null;

                continue;
            }

            $totais[$coluna] = array_sum(array_map(
                static fn (LinhaAdvogadoDashboardOutput $l): int => (int) (($l->{$campo})[$coluna] ?? 0),
                $linhas,
            ));
        }

        return $totais;
    }

    /**
     * Taxa do Total: Σ concluídas ÷ Σ metas das linhas visíveis; sem meta, null ("—").
     *
     * @param LinhaAdvogadoDashboardOutput[]             $linhas
     * @param array<int, array{total: int, ativas: int}> $baseDaTaxa
     */
    private function taxaDoTotal(array $linhas, array $baseDaTaxa): ?int
    {
        $metas = 0;
        $concluidas = 0;
        foreach ($linhas as $l) {
            $base = $baseDaTaxa[$l->userId] ?? ['total' => $l->totalMetas, 'ativas' => $l->metasAtivas];
            $metas += $base['total'];
            $concluidas += max(0, $base['total'] - $base['ativas']);
        }

        return $metas > 0 ? (int) round($concluidas / $metas * 100) : null;
    }

    /**
     * Cargo vazio não filtra; CARGO_SEM fica com quem não tem cargo no escritório (nulo ou
     * em branco); qualquer outro valor compara pelo nome exato, como sempre foi.
     *
     * @param array<int, mixed>         $colaboradores
     * @param array<int, string|null>   $mCargo
     *
     * @return array<int, mixed>
     */
    private function filtrarPorCargo(array $colaboradores, array $mCargo, string $cargo): array
    {
        if ($cargo === '') {
            return $colaboradores;
        }

        if ($cargo === self::CARGO_SEM) {
            return array_values(array_filter(
                $colaboradores,
                static fn ($u) => trim((string) ($mCargo[$u->getId()] ?? '')) === '',
            ));
        }

        return array_values(array_filter(
            $colaboradores,
            static fn ($u) => ($mCargo[$u->getId()] ?? null) === $cargo,
        ));
    }

    /**
     * Período anterior de MESMA DURAÇÃO, imediatamente antes de `data_de`: N dias corridos
     * (inclusivos) terminando na véspera de `data_de`. Ex.: 01/03/2024 a 31/03/2024 (31 dias)
     * vira 30/01/2024 a 29/02/2024. Exige as duas datas válidas e em ordem; qualquer outra
     * coisa devolve null — sem período não há "anterior", e o painel não inventa um.
     *
     * Período que termina depois de hoje (o "Este mês" padrão vai até o último dia do mês) conta só
     * os dias já corridos: 01/10 a 31/10 visto em 26/10 compara com os 26 dias antes de 01/10.
     * Sem isso, o mês em curso, que ainda não tem os dias futuros, perderia sempre para um mês cheio.
     *
     * Mesmo formato estrito ('!Y-m-d') que os repositórios usam para ler as datas, para que
     * só se compare quando o período atual de fato foi aplicado.
     *
     * @param array<string, mixed> $filtros
     *
     * @return array{data_de: string, data_ate: string}|null
     */
    private function periodoAnterior(array $filtros, \DateTimeImmutable $referencia): ?array
    {
        $de  = $this->lerData((string) ($filtros['data_de'] ?? ''));
        $ate = $this->lerData((string) ($filtros['data_ate'] ?? ''));
        if ($de === null || $ate === null || $ate < $de) {
            return null;
        }

        $dias = (int) $de->diff($this->fimJaCorrido($de, $ate, $referencia))->days + 1;

        return [
            'data_de'  => $de->modify(sprintf('-%d days', $dias))->format('Y-m-d'),
            'data_ate' => $de->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /** O fim do período limitado a hoje, quando o período já começou e termina no futuro. */
    private function fimJaCorrido(\DateTimeImmutable $de, \DateTimeImmutable $ate, \DateTimeImmutable $referencia): \DateTimeImmutable
    {
        // Mesma leitura (UTC, meia-noite) das datas do filtro, para a comparação ser de dia com dia.
        $hoje = $this->lerData($referencia->format('Y-m-d')) ?? $ate;

        return $de <= $hoje && $hoje < $ate ? $hoje : $ate;
    }

    private function lerData(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        // UTC: conta de dias corridos, sem horário de verão no meio.
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new \DateTimeZone('UTC'));
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $data;
    }

    /**
     * Mantém só as linhas cujo nome contém o termo, sem diferenciar maiúscula nem acento
     * ("elida" acha "Élida"). Termo vazio não filtra.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     *
     * @return LinhaAdvogadoDashboardOutput[]
     */
    private function aplicarBusca(array $linhas, string $busca): array
    {
        $termo = $this->normalizar(mb_substr(trim($busca), 0, self::BUSCA_MAX));
        if ($termo === '') {
            return $linhas;
        }

        return array_values(array_filter(
            $linhas,
            fn (LinhaAdvogadoDashboardOutput $l): bool => str_contains($this->normalizar($l->nomeAdvogado), $termo),
        ));
    }

    /** Minúsculas sem acento e com espaços colapsados, para comparar nomes. */
    private function normalizar(string $valor): string
    {
        $valor = strtr(mb_strtolower(trim($valor)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        return (string) preg_replace('/\s+/u', ' ', $valor);
    }

    /** @param LinhaAdvogadoDashboardOutput[] $linhas */
    private function somar(array $linhas, string $campo): int
    {
        return array_sum(array_map(
            static fn (LinhaAdvogadoDashboardOutput $l): int => (int) $l->$campo,
            $linhas,
        ));
    }

    /**
     * Soma do campo quando TODAS as linhas o têm; null se alguma não tem (ou se não há linha).
     * É a regra do estoque anterior: o Total só compara quando a foto cobre o grupo inteiro
     * que está na tela — somar parcial colocaria grupos diferentes lado a lado.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     */
    private function somarSeTodos(array $linhas, string $campo): ?int
    {
        if ($linhas === []) {
            return null;
        }

        $total = 0;
        foreach ($linhas as $linha) {
            if ($linha->$campo === null) {
                return null;
            }
            $total += (int) $linha->$campo;
        }

        return $total;
    }

    /**
     * Ordena a tabela pela coluna clicada no cabeçalho. Sem coluna escolhida — ou com uma que não
     * existe, já que a chave chega pela URL — mantém o padrão histórico do painel: mais metas
     * primeiro. Qualquer direção diferente de `asc` é decrescente.
     *
     * Coluna extra só ordena quando está ligada; o "—" (sem dado) vai sempre para o fim, nas
     * duas direções — sem dado não é "menor que zero".
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     * @param array<string, mixed>           $filtros
     * @param list<string>                   $colunasExtras
     *
     * @return LinhaAdvogadoDashboardOutput[]
     */
    private function ordenar(array $linhas, array $filtros, array $colunasExtras = []): array
    {
        $coluna = (string) ($filtros['ordenar'] ?? '');

        if (in_array($coluna, $colunasExtras, true)) {
            $asc = ($filtros['direcao'] ?? '') === 'asc';
            usort($linhas, static function (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b) use ($coluna, $asc): int {
                $va = $a->extras[$coluna] ?? null;
                $vb = $b->extras[$coluna] ?? null;
                if ($va === null || $vb === null) {
                    return ($va === null) <=> ($vb === null);
                }

                return $asc ? $va <=> $vb : $vb <=> $va;
            });

            return $linhas;
        }

        if (!isset(self::ORDENAVEIS[$coluna])) {
            usort($linhas, static fn (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b): int => $b->totalMetas <=> $a->totalMetas);

            return $linhas;
        }

        $campo = self::ORDENAVEIS[$coluna];
        $asc   = ($filtros['direcao'] ?? '') === 'asc';

        // Nome e cargo ordenam como gente lê, não como bytes: o container roda com LC_COLLATE=C,
        // onde "Élida" cairia depois de "Zulmira". Mesmo caminho do MontarAtividadeEquipeUseCase.
        $collator = in_array($campo, ['nomeAdvogado', 'cargoNome'], true) ? new \Collator('pt_BR') : null;

        usort($linhas, static function (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b) use ($campo, $asc, $collator): int {
            $cmp = $collator !== null
                ? (int) $collator->compare((string) $a->$campo, (string) $b->$campo)
                : $a->$campo <=> $b->$campo;

            return $asc ? $cmp : -$cmp;
        });

        return $linhas;
    }
}
