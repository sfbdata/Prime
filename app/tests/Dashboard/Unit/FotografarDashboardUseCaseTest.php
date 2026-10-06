<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\UseCase\FotografarDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(FotografarDashboardUseCase::class)]
#[Group('dashboard')]
final class FotografarDashboardUseCaseTest extends TestCase
{
    private TarefaRepository $tarefaRepo;
    private PastaRepository $pastaRepo;
    private UserRepository $userRepo;
    private DashboardFotoRepository $fotoRepo;
    private Tenant $tenant;
    private FotografarDashboardUseCase $sut;

    protected function setUp(): void
    {
        $this->tarefaRepo = $this->createMock(TarefaRepository::class);
        $this->pastaRepo  = $this->createMock(PastaRepository::class);
        $this->userRepo   = $this->createMock(UserRepository::class);
        $this->fotoRepo   = $this->createMock(DashboardFotoRepository::class);
        $this->tenant     = $this->createMock(Tenant::class);

        $this->sut = new FotografarDashboardUseCase($this->tarefaRepo, $this->pastaRepo, $this->userRepo, $this->fotoRepo);
    }

    private function mockUser(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function cenario(): void
    {
        $this->userRepo->method('findColaboradoresAtivosPorTenant')->willReturn([$this->mockUser(1), $this->mockUser(2)]);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn([1 => 5, 99 => 8]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([1 => 2]);
        $this->tarefaRepo->method('countPrazosProximosPorResponsavel')->willReturn([2 => 4]);
        $this->pastaRepo->method('countAtivasPorResponsavel')->willReturn([1 => 7, 2 => 1]);
    }

    #[TestDox('usa as mesmas consultas do painel SEM período e grava uma linha por colaborador ativo (zeros incluídos)')]
    public function testGravaUmaLinhaPorColaboradorComAsMesmasConsultas(): void
    {
        $momento = new \DateTimeImmutable('2026-10-06 23:55:00');
        $dia     = new \DateTimeImmutable('2026-10-06');

        $this->userRepo->method('findColaboradoresAtivosPorTenant')->willReturn([$this->mockUser(1), $this->mockUser(2)]);
        $this->tarefaRepo->expects(self::once())->method('countAtivasPorResponsavel')->with($this->tenant, [])->willReturn([1 => 5, 99 => 8]);
        $this->pastaRepo->expects(self::once())->method('countAtivasPorResponsavel')->with($this->tenant, [])->willReturn([1 => 7, 2 => 1]);
        $this->tarefaRepo->expects(self::once())->method('countVencidasPorResponsavel')->with($this->tenant, $momento)->willReturn([1 => 2]);
        $this->tarefaRepo->expects(self::once())->method('countPrazosProximosPorResponsavel')->with($this->tenant, $momento)->willReturn([2 => 4]);

        $esperado = [
            1 => ['metas_ativas' => 5, 'demandas_ativas' => 7, 'metas_vencidas' => 2, 'prazos_proximos' => 0],
            2 => ['metas_ativas' => 0, 'demandas_ativas' => 1, 'metas_vencidas' => 0, 'prazos_proximos' => 4],
        ];
        $this->fotoRepo->expects(self::once())->method('gravar')->with($this->tenant, $dia, $esperado)->willReturn(2);

        $resultado = $this->sut->executar($this->tenant, $dia, $momento);

        self::assertSame($esperado, $resultado->contagens, 'o 99 não é colaborador ativo: fica de fora');
        self::assertSame(2, $resultado->gravadas);
        self::assertSame(2, $resultado->colaboradores());
    }

    #[TestDox('dry-run calcula, mas não grava')]
    public function testDryRunNaoGrava(): void
    {
        $this->cenario();
        $this->fotoRepo->expects(self::never())->method('gravar');

        $resultado = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-06'), new \DateTimeImmutable(), true);

        self::assertSame(0, $resultado->gravadas);
        self::assertSame(2, $resultado->colaboradores());
    }

    #[TestDox('sem colaborador ativo não consulta nada nem grava')]
    public function testSemColaboradorNaoFazNada(): void
    {
        $this->userRepo->method('findColaboradoresAtivosPorTenant')->willReturn([]);
        $this->tarefaRepo->expects(self::never())->method('countAtivasPorResponsavel');
        $this->fotoRepo->expects(self::never())->method('gravar');

        $resultado = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-06'), new \DateTimeImmutable());

        self::assertSame([], $resultado->contagens);
    }
}
