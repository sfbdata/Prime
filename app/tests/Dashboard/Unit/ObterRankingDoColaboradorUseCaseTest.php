<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Repository\HistoricoDeMetasDoDashboardRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\UseCase\ObterRankingDoColaboradorUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Cartão do colaborador: período do painel ou janela, posição, Rendimento, 1º do mês e a guarda de posse (IDOR). */
#[CoversClass(ObterRankingDoColaboradorUseCase::class)]
#[Group('dashboard')]
final class ObterRankingDoColaboradorUseCaseTest extends TestCase
{
    private UserRepository&MockObject $userRepo;
    private TarefaRepository&MockObject $tarefaRepo;
    private MetricasExtrasDoDashboardRepository&MockObject $metricas;
    private HistoricoDeMetasDoDashboardRepository&MockObject $historico;
    private Tenant $tenant;
    private ObterRankingDoColaboradorUseCase $sut;

    protected function setUp(): void
    {
        $this->userRepo   = $this->createMock(UserRepository::class);
        $this->tarefaRepo = $this->createMock(TarefaRepository::class);
        $this->metricas   = $this->createMock(MetricasExtrasDoDashboardRepository::class);
        $this->historico  = $this->createMock(HistoricoDeMetasDoDashboardRepository::class);
        $this->tenant     = $this->createStub(Tenant::class);

        $this->userRepo->method('findColaboradoresAtivosPorTenant')->willReturn([
            $this->usuario(7, 'Ana Lima'), $this->usuario(8, 'Bruno Reis'), $this->usuario(9, 'Carla Dias'),
        ]);
        $this->userRepo->method('findCargoPorColaboradores')->willReturn([7 => 'Advogada', 8 => null, 9 => 'Estagiária']);

        $this->sut = new ObterRankingDoColaboradorUseCase($this->userRepo, $this->tarefaRepo, $this->metricas, $this->historico);
    }

    private function usuario(int $id, string $nome): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);
        $u->method('getFullName')->willReturn($nome);

        return $u;
    }

    #[TestDox('Sem janela: o período do painel; posição, registros e Rendimento contra a média dos outros')]
    public function testPeriodoDoPainel(): void
    {
        $periodo = ['data_de' => '2026-08-01', 'data_ate' => '2026-08-14'];
        $this->tarefaRepo->expects(self::once())->method('countPorResponsavel')
            ->with($this->tenant, $periodo, [4])->willReturn([7 => 6, 8 => 4, 9 => 1]);
        $this->tarefaRepo->expects(self::once())->method('countAtivasPorResponsavel')
            ->with($this->tenant, $periodo, [4])->willReturn([7 => 1, 8 => 2, 9 => 1]);
        $this->metricas->expects(self::once())->method('contarRegistrosPorAutor')
            ->with($this->tenant, $periodo, [4])->willReturn([8 => 11]);
        $this->historico->expects(self::once())->method('contarPorMesEResponsavel')->with($this->tenant, [4])->willReturn([]);

        $out = $this->sut->executar($this->tenant, 8, '', $periodo + ['busca' => 'x'], new \DateTimeImmutable('2026-08-14 10:00'), [4]);

        self::assertSame(['Bruno Reis', null, 'periodo', '2026-08-01', 2, 3, 11, false], [
            $out->nome, $out->cargo, $out->janela, $out->dataDe, $out->posicao, $out->pessoas, $out->registros, $out->campeao,
        ]);
        self::assertSame([2, 2.5, 2], [$out->concluidas, $out->mediaOutros, $out->outros], 'Ana 5 e Carla 0: média 2,5');
    }

    #[TestDox('Janela do seletor vence o período do painel: Trimestre do 1º dia do trimestre até hoje')]
    public function testJanelaTrimestre(): void
    {
        $this->tarefaRepo->expects(self::once())->method('countPorResponsavel')
            ->with($this->tenant, ['data_de' => '2026-07-01', 'data_ate' => '2026-08-14'], [])->willReturn([7 => 5, 8 => 2]);

        $out = $this->sut->executar($this->tenant, 7, 'trimestre', ['data_de' => '2020-01-01'], new \DateTimeImmutable('2026-08-14'));

        self::assertSame([1, true, 'trimestre', '2026-07-01'], [$out->posicao, $out->campeao, $out->janela, $out->dataDe]);
    }

    #[TestDox('1º do mês: os meses fechados que a pessoa ganhou, na equipe inteira')]
    public function testPrimeirosDoMes(): void
    {
        $this->historico->method('contarPorMesEResponsavel')->willReturn([
            ['ano' => 2026, 'mes' => 2, 'userId' => 9, 'total' => 3, 'ativas' => 0],
            ['ano' => 2026, 'mes' => 2, 'userId' => 7, 'total' => 1, 'ativas' => 0],
        ]);

        $out = $this->sut->executar($this->tenant, 9, '', [], new \DateTimeImmutable('2026-08-14'));

        self::assertSame([2026 => [2]], $out->primeiros['ganhos']);
        self::assertSame(7, $out->primeiros['ultimoMesFechado']);
    }

    #[TestDox('Id que não é colaborador ativo do escritório: exceção, sem consultar nada (IDOR)')]
    public function testForaDoEscritorio(): void
    {
        $this->tarefaRepo->expects(self::never())->method(self::anything());
        $this->metricas->expects(self::never())->method(self::anything());
        $this->historico->expects(self::never())->method(self::anything());

        $this->expectException(ColaboradorForaDoEscritorioException::class);
        $this->sut->executar($this->tenant, 999, 'mes', [], new \DateTimeImmutable('2026-08-14'));
    }
}
