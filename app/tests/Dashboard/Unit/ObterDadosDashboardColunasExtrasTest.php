<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\DTO\MetaDePastasDoMesOutput;
use App\Dashboard\Meta\MontarMetaDePastasDoMes;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Colunas extras ("Adicionar coluna") no UseCase do Dashboard: só as ligadas são calculadas (uma
 * consulta por métrica, no máximo), as fórmulas do desenho, o "—" quando não há o que medir, o
 * Total de cada uma e a ordenação pela coluna extra.
 */
#[CoversClass(ObterDadosDashboardUseCase::class)]
#[Group('dashboard')]
final class ObterDadosDashboardColunasExtrasTest extends TestCase
{
    private PastaRepository&MockObject $pastaRepo;
    private TarefaRepository&MockObject $tarefaRepo;
    private UserRepository&MockObject $userRepo;
    private MetricasExtrasDoDashboardRepository&MockObject $metricas;
    private Tenant $tenant;
    private ObterDadosDashboardUseCase $sut;

    protected function setUp(): void
    {
        $this->pastaRepo  = $this->createMock(PastaRepository::class);
        $this->tarefaRepo = $this->createMock(TarefaRepository::class);
        $this->userRepo   = $this->createMock(UserRepository::class);
        $this->metricas   = $this->createMock(MetricasExtrasDoDashboardRepository::class);
        $this->tenant     = $this->createStub(Tenant::class);

        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 0, 'total' => 0]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countPrazosProximosPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countAtivasPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countCriadasPorCriador')->willReturn([]);
        $this->userRepo->method('findCargoPorColaboradores')->willReturn([]);
        $this->userRepo->method('findFotoPorColaboradores')->willReturn([]);

        $this->sut = new ObterDadosDashboardUseCase(
            $this->pastaRepo,
            $this->tarefaRepo,
            $this->userRepo,
            $this->createMock(DashboardFotoRepository::class),
            $this->metricas,
            $this->createStub(DashboardZeramentoRepository::class),
            $this->createConfiguredStub(MontarMetaDePastasDoMes::class, ['montar' => new MetaDePastasDoMesOutput(null, null, '2026-10', 0, 22, 1, false)]),
        );
    }

    private function usuario(int $id, string $nome): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);
        $u->method('getFullName')->willReturn($nome);

        return $u;
    }

    /**
     * @param array<int, int> $total
     * @param array<int, int> $ativas
     */
    private function metas(array $total, array $ativas): void
    {
        $this->tarefaRepo->method('countPorResponsavel')->willReturn($total);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn($ativas);
    }

    /** @param list<User> $pessoas */
    private function equipe(array $pessoas): void
    {
        $this->userRepo->method('findColaboradoresAtivosPorTenant')->willReturn($pessoas);
    }

    /** @return array<int, array<string, int|null>> userId => extras */
    private function extrasPorPessoa(DashboardOutput $out): array
    {
        $mapa = [];
        foreach ($out->porAdvogado as $l) {
            $mapa[$l->userId] = $l->extras;
        }

        return $mapa;
    }

    #[TestDox('Sem extra ligada: nenhuma consulta de métrica extra e a linha sem extras (a tabela de sempre)')]
    public function testSemExtrasNaoConsulta(): void
    {
        $this->metas([7 => 3], [7 => 1]);
        $this->equipe([$this->usuario(7, 'Ana')]);
        $this->metricas->expects(self::never())->method(self::anything());

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'));

        self::assertSame([], $out->colunasExtras);
        self::assertSame([], $out->totaisExtras);
        self::assertSame([], $out->porAdvogado[0]->extras);
    }

    #[TestDox('Concluídas = Total − Ativas e Taxa = concluídas ÷ total, sem nenhuma consulta nova; sem meta, taxa "—" (null)')]
    public function testConcluidasETaxaDerivadas(): void
    {
        $this->metas([7 => 4, 8 => 3], [7 => 1, 8 => 3]);
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno'), $this->usuario(9, 'Carla')]);
        $this->metricas->expects(self::never())->method(self::anything());

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), [], [Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO]);
        $x   = $this->extrasPorPessoa($out);

        self::assertSame([Extras::METAS_CONCLUIDAS => 3, Extras::TAXA_CONCLUSAO => 75], $x[7]);
        self::assertSame([Extras::METAS_CONCLUIDAS => 0, Extras::TAXA_CONCLUSAO => 0], $x[8], 'tem meta e nenhuma concluída: 0%, não "—"');
        self::assertSame([Extras::METAS_CONCLUIDAS => 0, Extras::TAXA_CONCLUSAO => null], $x[9], 'sem meta não há taxa');
        // Total: soma das concluídas; taxa pela base inteira (3 de 7 = 43%), não média das taxas.
        self::assertSame([Extras::METAS_CONCLUIDAS => 3, Extras::TAXA_CONCLUSAO => 43], $out->totaisExtras);
    }

    #[TestDox('Cada métrica com consulta é chamada UMA vez por período, com o tenant e o filtro da tabela; eventos recebem o universo da tabela')]
    public function testUmaConsultaPorMetrica(): void
    {
        $filtros    = ['data_de' => '2024-01-01', 'data_ate' => '2024-01-31', 'cargo' => '', 'responsavel' => ''];
        $anteriores = ['data_de' => '2023-12-01', 'data_ate' => '2023-12-31', 'cargo' => '', 'responsavel' => ''];
        $this->metas([], []);
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno')]);
        // Estoque (sem tendência): uma consulta só, no período atual.
        $this->metricas->expects(self::once())->method('contarEmRevisaoPorResponsavel')->with($this->tenant, $filtros)->willReturn([7 => 2]);
        $this->metricas->expects(self::once())->method('contarUrgentesPorResponsavel')->with($this->tenant, $filtros)->willReturn([8 => 5]);
        // Tempo médio tem tendência (PR-E): atual e anterior, a mesma consulta.
        $tempos = [];
        $this->metricas->expects(self::exactly(2))->method('tempoDeConclusaoPorResponsavel')
            ->willReturnCallback(function (Tenant $t, array $f) use (&$tempos): array {
                $tempos[] = $f;

                return [];
            });
        // Com tendência (DASH-010): atual e período anterior de mesma duração — uma de cada.
        $eventos = [];
        $this->metricas->expects(self::exactly(2))->method('contarEventosPorPessoa')
            ->willReturnCallback(function (Tenant $t, array $f, array $pessoas) use (&$eventos): array {
                self::assertSame([7, 8], $pessoas);
                $eventos[] = $f;

                return $f['data_de'] === '2024-01-01' ? [7 => 1] : [];
            });

        // Pastas concluídas tem tendência: atual e anterior, com o cadeado repassado.
        $finalizadas = [];
        $this->metricas->expects(self::exactly(2))->method('contarFinalizadasPorResponsavel')
            ->willReturnCallback(function (Tenant $t, array $f) use (&$finalizadas): array {
                $finalizadas[] = $f;

                return $f['data_de'] === '2024-01-01' ? [8 => 4] : [8 => 2];
            });

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-02-10'), $filtros, Extras::chaves());
        $x   = $this->extrasPorPessoa($out);

        self::assertSame(Extras::chaves(), $out->colunasExtras);
        self::assertSame(2, $x[7][Extras::METAS_REVISAO]);
        self::assertSame(0, $x[8][Extras::METAS_REVISAO], 'contagem sem registro é zero (é dado), não "—"');
        self::assertSame(5, $x[8][Extras::PASTAS_URGENTES]);
        self::assertSame(1, $x[7][Extras::EVENTOS_AGENDA]);
        self::assertSame(0, $x[8][Extras::EVENTOS_AGENDA]);
        self::assertNull($x[7][Extras::TEMPO_MEDIO], 'sem meta concluída com data: "—"');
        self::assertSame([$filtros, $anteriores], $eventos);
        self::assertSame([$filtros, $anteriores], $tempos);
        self::assertSame(4, $x[8][Extras::PASTAS_CONCLUIDAS]);
        self::assertSame(0, $x[7][Extras::PASTAS_CONCLUIDAS]);
        self::assertSame([$filtros, $anteriores], $finalizadas);
        self::assertSame(2, $out->porAdvogado[array_search(8, array_map(static fn ($l): int => $l->userId, $out->porAdvogado), true)]->extrasAnteriores[Extras::PASTAS_CONCLUIDAS]);
        self::assertSame(4, $out->totaisExtras[Extras::PASTAS_CONCLUIDAS]);
    }

    #[TestDox('Só as extras ligadas consultam: ligar só "Em revisão" não chama tempo, urgentes nem eventos')]
    public function testSoAsLigadasConsultam(): void
    {
        $this->metas([], []);
        $this->equipe([$this->usuario(7, 'Ana')]);
        $this->metricas->expects(self::once())->method('contarEmRevisaoPorResponsavel')->willReturn([]);
        $this->metricas->expects(self::never())->method('tempoDeConclusaoPorResponsavel');
        $this->metricas->expects(self::never())->method('contarUrgentesPorResponsavel');
        $this->metricas->expects(self::never())->method('contarEventosPorPessoa');

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), [], [Extras::METAS_REVISAO]);

        self::assertSame([Extras::METAS_REVISAO => 0], $out->porAdvogado[0]->extras);
    }

    #[TestDox('Sem colaborador na tabela, nenhuma métrica extra é consultada')]
    public function testSemEquipeNaoConsulta(): void
    {
        $this->metas([], []);
        $this->equipe([]);
        $this->metricas->expects(self::never())->method(self::anything());

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), [], Extras::chaves());

        self::assertSame([], $out->porAdvogado);
    }

    #[TestDox('Tempo médio: média da pessoa arredondada; Total é a média das linhas com tempo > 0, como o desenho')]
    public function testTempoMedioTotalPelasLinhas(): void
    {
        $this->metas([], []);
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno'), $this->usuario(9, 'Carla'), $this->usuario(10, 'Davi')]);
        $this->metricas->method('tempoDeConclusaoPorResponsavel')->willReturn([
            7  => ['dias' => 30, 'metas' => 10], // 3d
            8  => ['dias' => 1, 'metas' => 1],   // 1d
            10 => ['dias' => 0, 'metas' => 2],   // 0d: concluiu no mesmo dia
        ]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), [], [Extras::TEMPO_MEDIO]);
        $x   = $this->extrasPorPessoa($out);

        self::assertSame(3, $x[7][Extras::TEMPO_MEDIO]);
        self::assertSame(1, $x[8][Extras::TEMPO_MEDIO]);
        self::assertNull($x[9][Extras::TEMPO_MEDIO]);
        self::assertSame(0, $x[10][Extras::TEMPO_MEDIO]);
        // dc 1.2.2 L.1980: média das linhas com tempo > 0 → (3 + 1) ÷ 2 = 2. A linha "—" e a de
        // 0d ficam fora (contando a de 0d, daria 4 ÷ 3 = 1).
        self::assertSame([Extras::TEMPO_MEDIO => 2], $out->totaisExtras);
    }

    #[TestDox('Tempo médio: o Total NÃO é ponderado pelas metas (3d em 10 metas e 1d em 1 meta → 2d, não 3d)')]
    public function testTempoMedioTotalNaoEPonderado(): void
    {
        $this->metas([], []);
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno')]);
        $this->metricas->method('tempoDeConclusaoPorResponsavel')->willReturn([
            7 => ['dias' => 30, 'metas' => 10], // 3d
            8 => ['dias' => 1, 'metas' => 1],   // 1d
        ]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), [], [Extras::TEMPO_MEDIO]);

        // Ponderada daria 31 ÷ 11 = 2,8 → 3; a do desenho é (3 + 1) ÷ 2 = 2.
        self::assertSame([Extras::TEMPO_MEDIO => 2], $out->totaisExtras);
    }

    #[TestDox('Sem base nenhuma, o Total de taxa e de tempo médio é "—" (null); o de contagem é 0')]
    public function testTotaisSemBase(): void
    {
        $this->metas([], []);
        $this->equipe([$this->usuario(7, 'Ana')]);
        $this->metricas->method('tempoDeConclusaoPorResponsavel')->willReturn([]);
        $this->metricas->method('contarUrgentesPorResponsavel')->willReturn([]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), [], [Extras::TAXA_CONCLUSAO, Extras::TEMPO_MEDIO, Extras::PASTAS_URGENTES]);

        self::assertSame([Extras::TAXA_CONCLUSAO => null, Extras::TEMPO_MEDIO => null, Extras::PASTAS_URGENTES => 0], $out->totaisExtras);
    }

    #[TestDox('Chave fora do catálogo é ignorada e repetida sai uma vez; a ordem é a do usuário')]
    public function testChavesInvalidasIgnoradas(): void
    {
        $this->metas([], []);
        $this->equipe([$this->usuario(7, 'Ana')]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), [], ['demandas_encerradas', Extras::TAXA_CONCLUSAO, 'salario', Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO]);

        self::assertSame([Extras::TAXA_CONCLUSAO, Extras::METAS_CONCLUIDAS], $out->colunasExtras);
        self::assertSame([Extras::TAXA_CONCLUSAO, Extras::METAS_CONCLUIDAS], array_keys($out->porAdvogado[0]->extras));
    }

    #[TestDox('Ordenar pela extra: decrescente e crescente pelo valor, com "—" sempre no fim')]
    public function testOrdenarPelaExtraComVazioNoFim(): void
    {
        $this->metas([7 => 4, 8 => 2], [7 => 3, 8 => 0]); // Ana 25%, Bruno 100%, Carla sem meta
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno'), $this->usuario(9, 'Carla')]);
        $ref = new \DateTimeImmutable('2024-01-10');

        $desc = $this->sut->executar($this->tenant, $ref, ['ordenar' => Extras::TAXA_CONCLUSAO, 'direcao' => 'desc'], [Extras::TAXA_CONCLUSAO]);
        $asc  = $this->sut->executar($this->tenant, $ref, ['ordenar' => Extras::TAXA_CONCLUSAO, 'direcao' => 'asc'], [Extras::TAXA_CONCLUSAO]);

        self::assertSame([8, 7, 9], array_map(static fn ($l): int => $l->userId, $desc->porAdvogado));
        self::assertSame([7, 8, 9], array_map(static fn ($l): int => $l->userId, $asc->porAdvogado));
    }

    #[TestDox('Ordenar por extra que NÃO está ligada cai no padrão do painel (mais metas primeiro)')]
    public function testOrdenarPorExtraDesligadaCaiNoPadrao(): void
    {
        $this->metas([7 => 1, 8 => 5], [7 => 0, 8 => 0]);
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno')]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), ['ordenar' => Extras::TAXA_CONCLUSAO, 'direcao' => 'asc'], []);

        self::assertSame([8, 7], array_map(static fn ($l): int => $l->userId, $out->porAdvogado));
    }

    #[TestDox('O Total das extras soma só as linhas VISÍVEIS: a busca por nome tira a pessoa do Total')]
    public function testTotalRespeitaBusca(): void
    {
        $this->metas([7 => 4, 8 => 2], [7 => 1, 8 => 2]);
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno')]);
        $this->metricas->method('contarUrgentesPorResponsavel')->willReturn([7 => 2, 8 => 9]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), ['busca' => 'ana'], [Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO, Extras::PASTAS_URGENTES]);

        self::assertCount(1, $out->porAdvogado);
        self::assertSame([Extras::METAS_CONCLUIDAS => 3, Extras::TAXA_CONCLUSAO => 75, Extras::PASTAS_URGENTES => 2], $out->totaisExtras);
    }

    // ─── tendência das extras (DASH-010) ─────────────────────────────

    #[TestDox('Sem período completo, nenhuma extra tem anterior (sem seta) e nenhuma consulta extra é repetida')]
    public function testSemPeriodoSemAnterior(): void
    {
        $this->metas([7 => 4], [7 => 1]);
        $this->equipe([$this->usuario(7, 'Ana')]);
        $this->metricas->expects(self::once())->method('contarEventosPorPessoa')->willReturn([7 => 2]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-01-10'), ['data_de' => '2024-01-01'], [Extras::METAS_CONCLUIDAS, Extras::EVENTOS_AGENDA]);

        self::assertSame([], $out->porAdvogado[0]->extrasAnteriores);
        self::assertNull($out->totaisExtrasAnteriores);
    }

    #[TestDox('Com período: toda extra de TENDENCIA tem anterior (mesma consulta, datas trocadas); Em revisão e Pastas urgentes ficam sem, consultadas só no atual')]
    public function testAnteriorDasExtrasComTendencia(): void
    {
        $filtros = ['data_de' => '2024-02-01', 'data_ate' => '2024-02-29'];
        $antes   = '2024-01-22'; // visto em 10/02, só os 10 dias já corridos: 22/01 a 31/01
        $this->metas([7 => 4, 8 => 3], [7 => 1, 8 => 3]);
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno')]);
        $this->metricas->expects(self::exactly(2))->method('tempoDeConclusaoPorResponsavel')->willReturnCallback(
            static fn (Tenant $t, array $f): array => $f['data_de'] === $antes
                ? [7 => ['dias' => 9, 'metas' => 3]]
                : [7 => ['dias' => 6, 'metas' => 3], 8 => ['dias' => 4, 'metas' => 1]],
        );
        $this->metricas->expects(self::exactly(2))->method('contarEventosPorPessoa')->willReturnCallback(
            static fn (Tenant $t, array $f, array $p): array => $f['data_de'] === $antes ? [8 => 1] : [7 => 2, 8 => 1],
        );
        $this->metricas->expects(self::once())->method('contarEmRevisaoPorResponsavel')->willReturn([]);
        $this->metricas->expects(self::once())->method('contarUrgentesPorResponsavel')->willReturn([]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-02-10'), $filtros, Extras::chaves());
        $ant = [];
        foreach ($out->porAdvogado as $l) {
            $ant[$l->userId] = $l->extrasAnteriores;
        }

        // Os mocks de metas devolvem o mesmo para os dois períodos: anterior = 4 metas/1 ativa (Ana) e 3/3 (Bruno).
        self::assertSame(
            [Extras::METAS_CONCLUIDAS => 3, Extras::TAXA_CONCLUSAO => 75, Extras::TEMPO_MEDIO => 3, Extras::PASTAS_CONCLUIDAS => 0, Extras::EVENTOS_AGENDA => 0, Extras::RELATORIO => 0],
            $ant[7],
        );
        self::assertSame(
            [Extras::METAS_CONCLUIDAS => 0, Extras::TAXA_CONCLUSAO => 0, Extras::TEMPO_MEDIO => null, Extras::PASTAS_CONCLUIDAS => 0, Extras::EVENTOS_AGENDA => 1, Extras::RELATORIO => 0],
            $ant[8],
            'Bruno: sem meta concluída com data no anterior, tempo null (sem seta)',
        );
        // Total anterior: concluídas somadas, taxa pela base inteira (3 de 7 = 43), tempo = média das linhas com tempo > 0.
        self::assertSame(
            [Extras::METAS_CONCLUIDAS => 3, Extras::TAXA_CONCLUSAO => 43, Extras::TEMPO_MEDIO => 3, Extras::PASTAS_CONCLUIDAS => 0, Extras::EVENTOS_AGENDA => 1, Extras::RELATORIO => 0],
            $out->totaisExtrasAnteriores,
        );
        self::assertArrayNotHasKey(Extras::METAS_REVISAO, $ant[7]);
        self::assertArrayNotHasKey(Extras::PASTAS_URGENTES, $ant[7]);
        // o atual segue calculado
        self::assertSame(
            [Extras::METAS_CONCLUIDAS => 3, Extras::TAXA_CONCLUSAO => 43, Extras::TEMPO_MEDIO => 3, Extras::EVENTOS_AGENDA => 3],
            array_intersect_key($out->totaisExtras, array_flip([Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO, Extras::TEMPO_MEDIO, Extras::EVENTOS_AGENDA])),
        );
    }

    #[TestDox('Concluídas e taxa pedem Total e Ativas do período ANTERIOR (mesma consulta, datas trocadas), sem consulta de métrica extra')]
    public function testConcluidasETaxaConsultamOAnterior(): void
    {
        $filtros    = ['data_de' => '2024-02-01', 'data_ate' => '2024-02-29'];
        $anteriores = ['data_de' => '2024-01-22', 'data_ate' => '2024-01-31'];
        $ativas     = [];
        $this->tarefaRepo->expects(self::exactly(2))->method('countAtivasPorResponsavel')
            ->willReturnCallback(function (Tenant $t, array $f) use (&$ativas): array {
                $ativas[] = [$f['data_de'], $f['data_ate']];

                return $f['data_de'] === '2024-01-22' ? [7 => 2] : [7 => 1];
            });
        $this->tarefaRepo->method('countPorResponsavel')->willReturn([7 => 4]);
        $this->equipe([$this->usuario(7, 'Ana')]);
        $this->metricas->expects(self::never())->method(self::anything());

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-02-10'), $filtros, [Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO]);

        self::assertSame([['2024-02-01', '2024-02-29'], [$anteriores['data_de'], $anteriores['data_ate']]], $ativas);
        self::assertSame([Extras::METAS_CONCLUIDAS => 2, Extras::TAXA_CONCLUSAO => 50], $out->porAdvogado[0]->extrasAnteriores);
        self::assertSame([Extras::METAS_CONCLUIDAS => 2, Extras::TAXA_CONCLUSAO => 50], $out->totaisExtrasAnteriores);
        self::assertSame([Extras::METAS_CONCLUIDAS => 3, Extras::TAXA_CONCLUSAO => 75], $out->totaisExtras);
    }

    #[TestDox('Divisão por zero: sem meta no período anterior, concluídas anterior é 0 (a tela mostra "novo") e a taxa anterior é null (sem seta)')]
    public function testSemMetaNoAnteriorTaxaNula(): void
    {
        $this->tarefaRepo->method('countPorResponsavel')->willReturnCallback(
            static fn (Tenant $t, array $f): array => $f['data_de'] === '2024-02-01' ? [7 => 4] : [],
        );
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturnCallback(
            static fn (Tenant $t, array $f): array => $f['data_de'] === '2024-02-01' ? [7 => 1] : [],
        );
        $this->equipe([$this->usuario(7, 'Ana')]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2024-02-10'), ['data_de' => '2024-02-01', 'data_ate' => '2024-02-29'], [Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO]);

        self::assertSame([Extras::METAS_CONCLUIDAS => 0, Extras::TAXA_CONCLUSAO => null], $out->porAdvogado[0]->extrasAnteriores);
        self::assertSame([Extras::METAS_CONCLUIDAS => 0, Extras::TAXA_CONCLUSAO => null], $out->totaisExtrasAnteriores);
    }

    #[TestDox('O Total anterior de eventos soma só as linhas VISÍVEIS (a busca tira a Ana dos dois lados)')]
    public function testTotalAnteriorRespeitaBusca(): void
    {
        $this->metas([], []);
        $this->equipe([$this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno')]);
        $this->metricas->method('contarEventosPorPessoa')->willReturnCallback(
            static fn (Tenant $t, array $f, array $p): array => $f['data_de'] === '2024-02-01' ? [7 => 5, 8 => 2] : [7 => 9, 8 => 1],
        );

        $out = $this->sut->executar(
            $this->tenant,
            new \DateTimeImmutable('2024-02-10'),
            ['data_de' => '2024-02-01', 'data_ate' => '2024-02-29', 'busca' => 'bru'],
            [Extras::EVENTOS_AGENDA],
        );

        self::assertSame([Extras::EVENTOS_AGENDA => 2], $out->totaisExtras);
        self::assertSame([Extras::EVENTOS_AGENDA => 1], $out->totaisExtrasAnteriores);
    }
}
