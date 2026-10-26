<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\Repository\HistoricoDoDashboardRepository;
use App\Dashboard\UseCase\ObterHistoricoDoCardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A conta da janela "Histórico" (DASH-017) com a referência fixa em 15/08/2026: série por mês,
 * meses que ainda não chegaram, melhor mês, mês atual, Média, Melhor, comparação com o mesmo
 * intervalo do ano anterior, "Todos" — e o universo de colaboradores igual ao do card.
 */
#[CoversClass(ObterHistoricoDoCardUseCase::class)]
final class ObterHistoricoDoCardUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private \DateTimeImmutable $referencia;

    protected function setUp(): void
    {
        $this->tenant     = $this->createStub(Tenant::class);
        $this->referencia = new \DateTimeImmutable('2026-08-15 12:00:00');
    }

    private function usuario(int $id, string $nome): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);
        $u->method('getFullName')->willReturn($nome);

        return $u;
    }

    /**
     * @param list<User>              $ativos
     * @param array<int, string|null> $cargos
     */
    private function userRepo(array $ativos, array $cargos = []): UserRepository
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findColaboradoresAtivosPorTenant')->willReturn($ativos);
        $repo->method('findCargoPorColaboradores')->willReturn($cargos);

        return $repo;
    }

    /**
     * @param array<string, int>   $porMes
     * @param array<string, mixed> $capturado
     */
    private function repo(array $porMes, array &$capturado = []): HistoricoDoDashboardRepository
    {
        $repo = $this->createStub(HistoricoDoDashboardRepository::class);
        $repo->method('contarPastasCriadasPorMes')->willReturnCallback(
            static function (Tenant $t, array $ids, \DateTimeImmutable $de, \DateTimeImmutable $ate) use ($porMes, &$capturado): array {
                $capturado = ['ids' => $ids, 'de' => $de->format('Y-m-d H:i:s'), 'ate' => $ate->format('Y-m-d H:i:s')];

                return $porMes;
            },
        );

        return $repo;
    }

    /**
     * @param list<array<string, mixed>> $opcoes
     *
     * @return array<string, mixed>
     */
    private function opcao(array $opcoes, string $chave): array
    {
        foreach ($opcoes as $o) {
            if ($o['chave'] === $chave) {
                return $o;
            }
        }
        self::fail('sem a opção ' . $chave);
    }

    #[TestDox('ano corrente: 12 barras, meses depois do atual vazios (null), mês atual e melhor mês marcados; seletor 2026 · 2025 · 2024 · Todos')]
    public function testAnoCorrente(): void
    {
        $capturado = [];
        $useCase = new ObterHistoricoDoCardUseCase(
            $this->repo(['2026-01' => 3, '2026-03' => 6, '2026-08' => 2, '2025-01' => 1, '2025-02' => 1, '2025-03' => 2, '2025-08' => 4, '2025-11' => 9], $capturado),
            $this->userRepo([$this->usuario(1, 'Ana'), $this->usuario(2, 'Bruno')]),
            $this->createStub(DashboardFotoRepository::class),
        );

        $out = $useCase->executar($this->tenant, 'pastas', $this->referencia);

        self::assertTrue($out->disponivel);
        self::assertSame('2026', $out->inicial);
        self::assertSame(['2026', '2025', '2024', 'todos'], array_column($out->opcoes, 'chave'));
        self::assertSame([1, 2], $capturado['ids'], 'universo = colaboradores ativos');
        self::assertSame('2023-01-01 00:00:00', $capturado['de'], 'um ano antes do seletor, para a comparação do mais antigo');
        self::assertSame('2026-12-31 23:59:59', $capturado['ate']);

        $o = $this->opcao($out->opcoes, '2026');
        self::assertSame([3, 0, 6, 0, 0, 0, 0, 2, null, null, null, null], array_column($o['barras'], 'valor'));
        self::assertSame(80, $o['barras'][2]['altura'], 'o maior chega ao topo');
        self::assertSame(3, $o['barras'][8]['altura'], 'mês que não chegou: barra vazia');
        self::assertSame(4, $o['barras'][1]['altura'], 'zero com o mínimo de 4px');
        self::assertTrue($o['barras'][2]['melhor']);
        self::assertTrue($o['barras'][7]['atual']);
        self::assertSame([false, false, true, false, false, false, false, true, false, false, false, false], array_column($o['barras'], 'mostrarValor'));
        self::assertSame('set/2026: ainda não fechou', $o['barras'][8]['titulo']);

        // Média/mês = 11 / 8 = 1,375 → "1,4"; Melhor = 6 em mar; vs 2025 nos mesmos 8 meses: 8 → 11 = +38%.
        self::assertSame(
            [
                ['rotulo' => 'Média/mês', 'valor' => '1,4', 'sub' => '8 meses', 'tom' => 'neutro'],
                ['rotulo' => 'Melhor', 'valor' => '6', 'sub' => 'mar/2026', 'tom' => 'ouro'],
                ['rotulo' => 'vs 2025', 'valor' => '+38%', 'sub' => '8 → 11', 'tom' => 'bom'],
            ],
            $o['stats'],
        );
    }

    #[TestDox('ano fechado: compara os 12 meses com o ano anterior; queda é vermelha com o menos tipográfico')]
    public function testAnoFechadoEQueda(): void
    {
        $useCase = new ObterHistoricoDoCardUseCase(
            $this->repo(['2025-05' => 2, '2024-05' => 4]),
            $this->userRepo([$this->usuario(1, 'Ana')]),
            $this->createStub(DashboardFotoRepository::class),
        );

        $o = $this->opcao($useCase->executar($this->tenant, 'pastas', $this->referencia)->opcoes, '2025');

        self::assertNotContains(null, array_column($o['barras'], 'valor'), 'ano fechado não tem mês vazio');
        self::assertTrue($o['barras'][11]['atual'], 'dezembro é o último');
        self::assertSame(['rotulo' => 'vs 2024', 'valor' => '−50%', 'sub' => '4 → 2', 'tom' => 'ruim'], $o['stats'][2]);
        self::assertSame('12 meses', $o['stats'][0]['sub']);
    }

    #[TestDox('ano sem nenhuma pasta: Melhor sem destaque e comparação N/D — nada inventado')]
    public function testAnoVazio(): void
    {
        $useCase = new ObterHistoricoDoCardUseCase($this->repo([]), $this->userRepo([$this->usuario(1, 'Ana')]), $this->createStub(DashboardFotoRepository::class));

        $o = $this->opcao($useCase->executar($this->tenant, 'pastas', $this->referencia)->opcoes, '2024');

        self::assertNotContains(true, array_column($o['barras'], 'melhor'));
        self::assertSame(['rotulo' => 'Melhor', 'valor' => '0', 'sub' => 'Sem dado', 'tom' => 'neutro'], $o['stats'][1]);
        self::assertSame('N/D', $o['stats'][2]['valor']);
        self::assertSame('0', $o['stats'][0]['valor']);
    }

    #[TestDox('"Todos": uma barra por ano do seletor, do mais antigo ao atual, com a soma; Média/ano, Melhor e Variação')]
    public function testTodos(): void
    {
        $useCase = new ObterHistoricoDoCardUseCase(
            $this->repo(['2023-06' => 50, '2024-02' => 4, '2024-09' => 6, '2025-01' => 15, '2026-03' => 5]),
            $this->userRepo([$this->usuario(1, 'Ana')]),
            $this->createStub(DashboardFotoRepository::class),
        );

        $o = $this->opcao($useCase->executar($this->tenant, 'pastas', $this->referencia)->opcoes, 'todos');

        self::assertSame(['2024', '2025', '2026'], array_column($o['barras'], 'rotulo'), '2023 só serve de comparação');
        self::assertSame([10, 15, 5], array_column($o['barras'], 'valor'));
        self::assertTrue($o['barras'][1]['melhor']);
        self::assertTrue($o['barras'][2]['atual']);
        self::assertSame(
            [
                ['rotulo' => 'Média/ano', 'valor' => '10', 'sub' => '3 anos', 'tom' => 'neutro'],
                ['rotulo' => 'Melhor', 'valor' => '15', 'sub' => '2025', 'tom' => 'ouro'],
                ['rotulo' => 'Variação', 'valor' => '−50%', 'sub' => '2024 → 2026', 'tom' => 'igual'],
            ],
            $o['stats'],
        );
    }

    #[TestDox('universo do card: responsável e cargo do painel estreitam; "Sem cargo" pega nulo e em branco; recorte no subtítulo')]
    public function testUniversoComoNoCard(): void
    {
        $ativos = [$this->usuario(1, 'Ana'), $this->usuario(2, 'Bruno'), $this->usuario(3, 'Carla')];
        $cargos = [1 => 'Advogado', 2 => null, 3 => '  '];

        $casos = [
            [['cargo' => 'Advogado'], [1], 'Histórico mensal · Advogado'],
            [['cargo' => '__sem__'], [2, 3], 'Histórico mensal · Sem cargo'],
            [['responsavel' => '2'], [2], 'Histórico mensal · Bruno'],
            [['responsavel' => '2', 'cargo' => 'Advogado'], [], 'Histórico mensal · Bruno'],
            [['responsavel' => '99'], [], 'Histórico mensal · colaborador não encontrado'],
            [[], [1, 2, 3], 'Histórico mensal'],
        ];
        foreach ($casos as [$filtros, $ids, $subtitulo]) {
            $capturado = ['ids' => 'não chamado'];
            $useCase = new ObterHistoricoDoCardUseCase($this->repo([], $capturado), $this->userRepo($ativos, $cargos), $this->createStub(DashboardFotoRepository::class));
            $out = $useCase->executar($this->tenant, 'pastas', $this->referencia, $filtros);

            self::assertSame($ids, $capturado['ids'], json_encode($filtros));
            self::assertSame($subtitulo, $out->subtitulo);
        }
    }

    /** @param array<string, array{valor: int, ativas: int}> $retratos */
    private function fotoRepo(array $retratos, array &$chamadas = []): DashboardFotoRepository
    {
        $repo = $this->createStub(DashboardFotoRepository::class);
        $repo->method('retratoPorMes')->willReturnCallback(
            static function (Tenant $t, array $ids, string $coluna) use ($retratos, &$chamadas): array {
                $chamadas[] = [$ids, $coluna];

                return $retratos;
            },
        );

        return $repo;
    }

    private function useCaseComFoto(array $retratos, array &$chamadas = []): ObterHistoricoDoCardUseCase
    {
        return new ObterHistoricoDoCardUseCase(
            $this->createStub(HistoricoDoDashboardRepository::class),
            $this->userRepo([$this->usuario(1, 'Ana'), $this->usuario(2, 'Bruno')]),
            $this->fotoRepo($retratos, $chamadas),
        );
    }

    #[TestDox('Metas ativas: série só dos meses com foto; mês sem foto é null ("sem histórico"); sem melhor, o stat do meio é "Pico" neutro')]
    public function testMetasAtivasSoDosMesesComFoto(): void
    {
        $chamadas = [];
        $out = $this->useCaseComFoto(['2026-07' => ['valor' => 12, 'ativas' => 12], '2026-08' => ['valor' => 20, 'ativas' => 20]], $chamadas)
            ->executar($this->tenant, 'metas', $this->referencia);

        self::assertTrue($out->disponivel);
        self::assertSame([[[1, 2], 'metas_ativas']], $chamadas, 'universo do card, coluna metas_ativas');
        $o = $this->opcao($out->opcoes, '2026');
        self::assertSame([null, null, null, null, null, null, 12, 20, null, null, null, null], array_column($o['barras'], 'valor'));
        self::assertSame('jan/2026: sem histórico', $o['barras'][0]['titulo'], 'passado sem foto');
        self::assertSame('set/2026: ainda não fechou', $o['barras'][8]['titulo']);
        self::assertSame(['Média', '16', '2 meses', 'neutro'], array_values($o['stats'][0]));
        self::assertSame(['Pico', '20', 'ago/2026', 'neutro'], array_values($o['stats'][1]));
        self::assertFalse(in_array(true, array_column($o['barras'], 'melhor'), true), 'fila: nenhum destaque dourado');
        self::assertSame('N/D', $o['stats'][2]['valor'], 'sem foto no ano anterior não há comparação');
        self::assertSame(array_fill(0, 12, null), array_column($this->opcao($out->opcoes, '2025')['barras'], 'valor'), '2025 sem foto: tudo vazio');
        self::assertSame([null, null, 16], array_column($this->opcao($out->opcoes, 'todos')['barras'], 'valor'), 'Todos: média do ano; ano sem foto vazio');
    }

    #[TestDox('Demandas urgentes: menos é melhor, o menor mês é o dourado e o stat é "Melhor (menor)"')]
    public function testUrgentesMenorEhMelhor(): void
    {
        $out = $this->useCaseComFoto(['2026-06' => ['valor' => 9, 'ativas' => 0], '2026-07' => ['valor' => 4, 'ativas' => 0], '2026-08' => ['valor' => 6, 'ativas' => 0]])
            ->executar($this->tenant, 'urgentes', $this->referencia);

        $o = $this->opcao($out->opcoes, '2026');
        self::assertTrue($o['barras'][6]['melhor']);
        self::assertSame(['Melhor (menor)', '4', 'jul/2026', 'ouro'], array_values($o['stats'][1]));
    }

    #[TestDox('Meta global: % de concluídas (total − ativas) sobre o total; "p.p." na comparação; mês sem meta no total não tem percentual')]
    public function testMetaGlobalPercentual(): void
    {
        $out = $this->useCaseComFoto([
            '2025-08' => ['valor' => 10, 'ativas' => 5],   // 50%
            '2026-07' => ['valor' => 0, 'ativas' => 0],    // divisão por zero: sem percentual
            '2026-08' => ['valor' => 10, 'ativas' => 4],   // 60%
        ])->executar($this->tenant, 'global', $this->referencia);

        $o = $this->opcao($out->opcoes, '2026');
        self::assertSame([null, null, null, null, null, null, null, 60, null, null, null, null], array_column($o['barras'], 'valor'));
        self::assertSame('60%', $o['barras'][7]['texto']);
        self::assertSame('ago/2026: 60%', $o['barras'][7]['titulo']);
        self::assertSame(['rotulo' => 'vs 2025', 'valor' => '+10 p.p.', 'sub' => '50% → 60%', 'tom' => 'bom'], $o['stats'][2]);
    }

    #[TestDox('Sem nenhuma foto no período: janela indisponível, nota "sem histórico", nenhum número')]
    public function testSemFotoNenhumaIndisponivel(): void
    {
        foreach (['metas', 'urgentes', 'global'] as $card) {
            $out = $this->useCaseComFoto([])->executar($this->tenant, $card, $this->referencia);

            self::assertFalse($out->disponivel, $card);
            self::assertSame([], $out->opcoes);
            self::assertNull($out->inicial);
            self::assertStringContainsString('sem histórico', $out->nota);
            self::assertStringContainsString('nenhum número é mostrado', $out->nota);
        }
    }

    #[TestDox('Com pasta oculta pelo cadeado a foto (do escritório inteiro) não serve: indisponível, sem consultar a foto')]
    public function testCadeadoNaoUsaAFoto(): void
    {
        $foto = $this->createMock(DashboardFotoRepository::class);
        $foto->expects(self::never())->method('retratoPorMes');
        $useCase = new ObterHistoricoDoCardUseCase($this->createStub(HistoricoDoDashboardRepository::class), $this->userRepo([$this->usuario(1, 'Ana')]), $foto);

        $out = $useCase->executar($this->tenant, 'metas', $this->referencia, [], [77]);

        self::assertFalse($out->disponivel);
        self::assertStringContainsString('pastas que o seu acesso não mostra', $out->nota);
    }
}
