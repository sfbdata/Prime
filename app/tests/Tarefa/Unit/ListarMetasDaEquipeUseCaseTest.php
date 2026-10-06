<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\UseCase\ListarMetasDaEquipeUseCase;
use Doctrine\ORM\Tools\Pagination\Paginator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarMetasDaEquipeUseCase::class)]
final class ListarMetasDaEquipeUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private \DateTimeImmutable $referencia;

    protected function setUp(): void
    {
        $this->tenant     = $this->createStub(Tenant::class);
        $this->referencia = new \DateTimeImmutable('2026-10-05 12:00:00');
    }

    private function usuario(int $id, string $nome): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);
        $u->method('getFullName')->willReturn($nome);

        return $u;
    }

    private function paginador(int $total): Paginator
    {
        $p = $this->createStub(Paginator::class);
        $p->method('count')->willReturn($total);
        $p->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $p;
    }

    /**
     * @param list<User>                $colaboradores
     * @param array<int, string|null>   $cargos
     */
    private function userRepo(array $colaboradores, array $cargos = []): UserRepository
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findColaboradoresAtivosPorTenant')->willReturn($colaboradores);
        $repo->method('findCargoPorColaboradores')->willReturn($cargos);

        return $repo;
    }

    /**
     * Repositório que registra o que recebeu.
     *
     * @param array<string, mixed> $capturado
     */
    private function tarefaRepoCapturando(array &$capturado, int $total = 0): TarefaRepository
    {
        $repo = $this->createMock(TarefaRepository::class);
        $repo->expects(self::atLeastOnce())
            ->method('findMetasDaEquipePaginado')
            ->willReturnCallback(function (Tenant $t, array $ids, string $status, array $filtros, \DateTimeImmutable $ref, int $pagina, int $porPagina) use (&$capturado, $total): Paginator {
                $capturado = compact('ids', 'status', 'filtros', 'ref', 'pagina', 'porPagina');

                return $this->paginador($total);
            });

        return $repo;
    }

    #[TestDox('Responsável do escritório: ids = [ele] e o nome entra no título com o período')]
    public function testResponsavelDoEscritorio(): void
    {
        $capturado = [];
        $useCase = new ListarMetasDaEquipeUseCase(
            $this->tarefaRepoCapturando($capturado, 3),
            $this->userRepo([$this->usuario(7, 'Fulano de Tal'), $this->usuario(8, 'Beltrana')]),
        );

        $out = $useCase->executar($this->tenant, $this->referencia, [
            'status' => 'vencidas', 'responsavel' => '7', 'data_de' => '2026-09-01', 'data_ate' => '2026-09-30',
        ]);

        self::assertSame([7], $capturado['ids']);
        self::assertSame('vencidas', $capturado['status']);
        self::assertSame($this->referencia, $capturado['ref']);
        self::assertSame(3, $out->total);
        // Vencidas não usam período: nem no título, nem nos links.
        self::assertSame('Metas vencidas — Fulano de Tal', $out->titulo);
        self::assertSame(['status' => 'vencidas', 'responsavel' => '7'], $out->filtros);
    }

    #[TestDox('Título com período no ano corrente usa dd/mm (ex.: "Metas ativas — Fulano · 01/09–30/09")')]
    public function testTituloComPeriodo(): void
    {
        $capturado = [];
        $useCase = new ListarMetasDaEquipeUseCase(
            $this->tarefaRepoCapturando($capturado),
            $this->userRepo([$this->usuario(7, 'Fulano')]),
        );

        $out = $useCase->executar($this->tenant, $this->referencia, [
            'status' => 'ativas', 'responsavel' => '7', 'data_de' => '2026-09-01', 'data_ate' => '2026-09-30',
        ]);

        self::assertSame('Metas ativas — Fulano · 01/09–30/09', $out->titulo);
        self::assertSame(['data_de' => '2026-09-01', 'data_ate' => '2026-09-30'], $capturado['filtros']);
    }

    #[TestDox('Responsável fora do escritório: universo vazio e nenhum nome revelado')]
    public function testResponsavelForaDoEscritorio(): void
    {
        $capturado = [];
        $useCase = new ListarMetasDaEquipeUseCase(
            $this->tarefaRepoCapturando($capturado),
            $this->userRepo([$this->usuario(7, 'Fulano')]),
        );

        $out = $useCase->executar($this->tenant, $this->referencia, ['status' => 'todas', 'responsavel' => '999']);

        self::assertSame([], $capturado['ids']);
        self::assertTrue($out->estaVazia());
        self::assertSame('Metas — colaborador não encontrado', $out->titulo);
    }

    #[TestDox('Cargo estreita o universo aos colaboradores daquele cargo, como no Dashboard')]
    public function testCargoEstreita(): void
    {
        $capturado = [];
        $useCase = new ListarMetasDaEquipeUseCase(
            $this->tarefaRepoCapturando($capturado),
            $this->userRepo(
                [$this->usuario(1, 'A'), $this->usuario(2, 'B'), $this->usuario(3, 'C')],
                [1 => 'Advogado', 2 => 'Estagiário', 3 => 'Advogado'],
            ),
        );

        $out = $useCase->executar($this->tenant, $this->referencia, ['status' => 'todas', 'cargo' => 'Advogado']);

        self::assertSame([1, 3], $capturado['ids']);
        self::assertSame('Metas — Advogado', $out->titulo);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function statusProvider(): iterable
    {
        yield 'todas'         => ['todas', 'todas'];
        yield 'ativas'        => ['ativas', 'ativas'];
        yield 'vencidas'      => ['vencidas', 'vencidas'];
        yield 'prazo_proximo' => ['prazo_proximo', 'prazo_proximo'];
        yield 'vazio'         => ['', 'todas'];
        yield 'desconhecido'  => ['concluidas', 'todas'];
    }

    #[DataProvider('statusProvider')]
    #[TestDox('Status "$recebido" chega ao repositório como "$esperado"')]
    public function testNormalizaStatus(string $recebido, string $esperado): void
    {
        $capturado = [];
        $useCase = new ListarMetasDaEquipeUseCase(
            $this->tarefaRepoCapturando($capturado),
            $this->userRepo([$this->usuario(1, 'A')]),
        );

        $out = $useCase->executar($this->tenant, $this->referencia, ['status' => $recebido]);

        self::assertSame($esperado, $capturado['status']);
        self::assertSame($esperado, $out->status);
    }

    #[TestDox('Data inválida é ignorada (mesma régua do Dashboard)')]
    public function testDataInvalidaIgnorada(): void
    {
        $capturado = [];
        $useCase = new ListarMetasDaEquipeUseCase(
            $this->tarefaRepoCapturando($capturado),
            $this->userRepo([$this->usuario(1, 'A')]),
        );

        $out = $useCase->executar($this->tenant, $this->referencia, ['data_de' => '2026-02-31', 'data_ate' => 'ontem']);

        self::assertSame(['data_de' => '', 'data_ate' => ''], $capturado['filtros']);
        self::assertSame('Metas — Equipe', $out->titulo);
    }

    #[TestDox('Página além da última é trazida de volta para a última')]
    public function testPaginaAlemDaUltima(): void
    {
        $paginas = [];
        $repo = $this->createMock(TarefaRepository::class);
        $repo->expects(self::exactly(2))
            ->method('findMetasDaEquipePaginado')
            ->willReturnCallback(function (Tenant $t, array $ids, string $s, array $f, \DateTimeImmutable $r, int $pagina) use (&$paginas): Paginator {
                $paginas[] = $pagina;

                return $this->paginador(ListarMetasDaEquipeUseCase::POR_PAGINA + 1);
            });

        $useCase = new ListarMetasDaEquipeUseCase($repo, $this->userRepo([$this->usuario(1, 'A')]));
        $out     = $useCase->executar($this->tenant, $this->referencia, [], 99);

        self::assertSame([99, 2], $paginas);
        self::assertSame(2, $out->pagina);
        self::assertSame(2, $out->totalPaginas);
    }
}
