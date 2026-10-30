<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\ListarMinhasDemandasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarMinhasDemandasUseCase::class)]
final class ListarMinhasDemandasUseCaseTest extends TestCase
{
    private PastaRepository&MockObject $repository;
    private ListarMinhasDemandasUseCase $useCase;
    private User&MockObject $usuario;
    private Tenant&MockObject $tenant;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PastaRepository::class);
        $this->useCase    = new ListarMinhasDemandasUseCase($this->repository);
        $this->usuario    = $this->createMock(User::class);
        $this->usuario->method('getId')->willReturn(42);
        $this->tenant = $this->createMock(Tenant::class);
    }

    #[TestDox('Fixa o responsável como o próprio usuário e repassa filtros, página e ordem ao repositório')]
    public function testFixaResponsavelERepassaFiltros(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-001');
        $comResponsavel = static fn (array $f): bool => ($f['responsavel'] ?? null) === '42' && ($f['status'] ?? null) === 'suspenso';

        $this->repository->expects($this->once())->method('countByFilters')
            ->with(self::callback($comResponsavel), $this->tenant, $this->usuario)->willReturn(60);
        $this->repository->expects($this->once())->method('findByFilters')
            ->with(self::callback($comResponsavel), $this->tenant, 2, 50, 'nup', 'asc', $this->usuario, $this->usuario)
            ->willReturn([$pasta]);

        $resultado = $this->useCase->executar($this->usuario, $this->tenant, ['status' => 'suspenso'], 2, 50, 'nup', 'asc');

        self::assertSame([$pasta], $resultado['pastas']);
        self::assertSame(60, $resultado['total']);
        self::assertSame(2, $resultado['pagina']);
    }

    #[TestDox('Cadeado: quem busca é o próprio usuário (a pasta oculta para ele não volta)')]
    public function testPassaOUsuarioComoQuemBusca(): void
    {
        $this->repository->expects($this->once())->method('countByFilters')
            ->with(self::anything(), $this->tenant, self::identicalTo($this->usuario))->willReturn(0);
        $this->repository->expects($this->once())->method('findByFilters')
            ->with(self::anything(), $this->tenant, 1, 1, '', 'desc', self::identicalTo($this->usuario), self::identicalTo($this->usuario))
            ->willReturn([]);

        $this->useCase->executar($this->usuario, $this->tenant, [], 1, null, '', 'desc');
    }

    #[TestDox('Filtro Responsável com o próprio usuário continua listando as minhas')]
    public function testResponsavelEuMesmo(): void
    {
        $this->repository->expects($this->once())->method('countByFilters')
            ->with(self::callback(static fn (array $f): bool => $f['responsavel'] === '42'))->willReturn(1);
        $this->repository->expects($this->once())->method('findByFilters')->willReturn([]);

        $resultado = $this->useCase->executar($this->usuario, $this->tenant, ['responsavel' => '42'], 1, 25, '', 'desc');

        self::assertSame(1, $resultado['total']);
    }

    #[TestDox('Filtro Responsável com outra pessoa (ou "sem") só estreita: nenhuma pasta, e o repositório nem é consultado')]
    public function testResponsavelDeOutraPessoaNaoAmplia(): void
    {
        $this->repository->expects($this->never())->method('countByFilters');
        $this->repository->expects($this->never())->method('findByFilters');

        foreach (['999', 'sem', 'equipe'] as $outro) {
            $resultado = $this->useCase->executar($this->usuario, $this->tenant, ['responsavel' => $outro], 3, 25, '', 'desc');
            self::assertSame(['pastas' => [], 'total' => 0, 'pagina' => 1], $resultado, $outro);
        }
    }

    #[TestDox('Página além da última volta para a última; sem tamanho de página traz todas (CSV)')]
    public function testPaginaLimitadaETodasDeUmaVez(): void
    {
        $this->repository->method('countByFilters')->willReturn(30);
        $this->repository->expects($this->exactly(2))->method('findByFilters')
            ->willReturnCallback(function (array $f, Tenant $t, int $pagina, int $tamanho): array {
                self::assertContains([$pagina, $tamanho], [[2, 25], [1, 30]]);

                return [];
            });

        self::assertSame(2, $this->useCase->executar($this->usuario, $this->tenant, [], 9, 25, '', 'desc')['pagina']);
        self::assertSame(1, $this->useCase->executar($this->usuario, $this->tenant, [], 1, null, '', 'desc')['pagina']);
    }
}
