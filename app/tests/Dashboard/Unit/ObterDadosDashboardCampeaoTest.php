<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
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
 * Troféu e coluna "Relatório" no UseCase do Dashboard (spec dashboard-parte-1, itens 2 e 3).
 */
#[CoversClass(ObterDadosDashboardUseCase::class)]
#[Group('dashboard')]
final class ObterDadosDashboardCampeaoTest extends TestCase
{
    private TarefaRepository&MockObject $tarefaRepo;
    private UserRepository&MockObject $userRepo;
    private MetricasExtrasDoDashboardRepository&MockObject $metricas;
    private Tenant $tenant;
    private ObterDadosDashboardUseCase $sut;

    protected function setUp(): void
    {
        $pastaRepo        = $this->createMock(PastaRepository::class);
        $this->tarefaRepo = $this->createMock(TarefaRepository::class);
        $this->userRepo   = $this->createMock(UserRepository::class);
        $this->metricas   = $this->createMock(MetricasExtrasDoDashboardRepository::class);
        $this->tenant     = $this->createStub(Tenant::class);

        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 0, 'total' => 0]);
        $this->userRepo->method('findColaboradoresAtivosPorTenant')->willReturn([
            $this->usuario(7, 'Ana'), $this->usuario(8, 'Bruno'), $this->usuario(9, 'Carla'),
        ]);
        $this->userRepo->method('findCargoPorColaboradores')->willReturn([7 => 'Advogado', 8 => 'Estagiário', 9 => 'Advogado']);

        $this->sut = new ObterDadosDashboardUseCase(
            $pastaRepo,
            $this->tarefaRepo,
            $this->userRepo,
            $this->createMock(DashboardFotoRepository::class),
            $this->metricas,
        );
    }

    private function usuario(int $id, string $nome): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);
        $u->method('getFullName')->willReturn($nome);

        return $u;
    }

    #[TestDox('Troféu: mais concluídas (total − ativas) no período do painel, com as pastas ocultas')]
    public function testCampeaoNoPeriodo(): void
    {
        $filtros = ['data_de' => '2026-10-01', 'data_ate' => '2026-10-23'];
        // Do período do painel; o anterior (tendência) também passa por countPorResponsavel e fica zerado.
        $this->tarefaRepo->method('countPorResponsavel')->willReturnCallback(
            static fn (Tenant $t, array $f, array $o): array => $f === $filtros && $o === [55] ? [7 => 6, 8 => 4, 9 => 1] : [],
        );
        $this->tarefaRepo->expects(self::once())->method('countAtivasPorResponsavel')
            ->with($this->tenant, $filtros, [55])->willReturn([7 => 5, 8 => 1]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-23'), $filtros, [], [55]);

        self::assertSame(8, $out->campeaoId, 'Bruno: 3 concluídas; Ana tem mais metas, mas só 1 concluída');
    }

    #[TestDox('Troféu não muda de dono com cargo, responsável ou busca: o universo é a equipe inteira')]
    public function testCampeaoAntesDosFiltrosDaTabela(): void
    {
        $this->tarefaRepo->method('countPorResponsavel')->willReturn([7 => 1, 8 => 3]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-23'), ['cargo' => 'Advogado', 'busca' => 'Ana']);

        self::assertSame(8, $out->campeaoId, 'Bruno segue campeão, ainda que a linha dele não esteja na tela');
        self::assertSame([7], array_map(static fn ($l): int => $l->userId, $out->porAdvogado));
    }

    #[TestDox('Empate em concluídas: vence quem tem menos vencidas; empate total, ninguém')]
    public function testDesempatePorVencidas(): void
    {
        $this->tarefaRepo->method('countPorResponsavel')->willReturn([7 => 2, 8 => 2]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([7 => 1]);

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-23'));

        self::assertSame(8, $out->campeaoId);
    }

    #[TestDox('Ninguém concluiu: sem troféu')]
    public function testSemCampeao(): void
    {
        $this->tarefaRepo->method('countPorResponsavel')->willReturn([7 => 2]);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn([7 => 2]);

        self::assertNull($this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-23'))->campeaoId);
    }

    #[TestDox('Relatório ligado: registros por autor com o filtro e as pastas ocultas, e o anterior para a tendência; Total = soma das linhas')]
    public function testColunaRelatorio(): void
    {
        $filtros = ['data_de' => '2026-10-01', 'data_ate' => '2026-10-23'];
        // Duas consultas: o período e o anterior equivalente (a tendência do Relatório).
        $this->metricas->expects(self::exactly(2))->method('contarRegistrosPorAutor')->willReturnCallback(
            static fn (Tenant $t, array $f, array $o): array => $f === $filtros && $o === [55] ? [7 => 4, 9 => 2, 99 => 50] : [7 => 2],
        );

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-23'), $filtros, [Extras::RELATORIO], [55]);

        $porPessoa = [];
        foreach ($out->porAdvogado as $l) {
            $porPessoa[$l->userId] = $l->extras[Extras::RELATORIO];
        }
        self::assertSame([7 => 4, 8 => 0, 9 => 2], $porPessoa);
        self::assertSame([Extras::RELATORIO => 6], $out->totaisExtras, 'quem não está na tabela (99) não entra no Total');
        self::assertSame([Extras::RELATORIO => 2], $out->totaisExtrasAnteriores, 'tendência: o anterior pela mesma consulta');
    }
}
