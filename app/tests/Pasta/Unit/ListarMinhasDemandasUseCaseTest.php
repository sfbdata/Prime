<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\ListarMinhasDemandasUseCase;
use App\Pasta\Repository\PastaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarMinhasDemandasUseCase::class)]
final class ListarMinhasDemandasUseCaseTest extends TestCase
{
    private PastaRepository&MockObject $repository;
    private ListarMinhasDemandasUseCase $useCase;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PastaRepository::class);
        $this->useCase    = new ListarMinhasDemandasUseCase($this->repository);
    }

    #[TestDox('Sem filtros chama repository com null e retorna as pastas')]
    public function testSemFiltrosRetornaPastas(): void
    {
        $usuario = $this->createMock(User::class);
        $tenant  = $this->createMock(Tenant::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-001');

        $this->repository
            ->expects($this->once())
            ->method('findAtivasPorResponsavel')
            ->with($usuario, $tenant, null, null)
            ->willReturn([$pasta]);

        $resultado = $this->useCase->executar($usuario, $tenant, null, null);

        self::assertCount(1, $resultado);
        self::assertSame($pasta, $resultado[0]);
    }

    #[TestDox('Com filtros repassa cliente e prioridade para o repository')]
    public function testComFiltrosRepassaParaRepository(): void
    {
        $usuario = $this->createMock(User::class);
        $tenant  = $this->createMock(Tenant::class);

        $this->repository
            ->expects($this->once())
            ->method('findAtivasPorResponsavel')
            ->with($usuario, $tenant, 'Maria', 'urgente')
            ->willReturn([]);

        $resultado = $this->useCase->executar($usuario, $tenant, 'Maria', 'urgente');

        self::assertSame([], $resultado);
    }

    #[TestDox('Repository vazio retorna array vazio')]
    public function testRepositoryVazioRetornaArrayVazio(): void
    {
        $usuario = $this->createMock(User::class);
        $tenant  = $this->createMock(Tenant::class);

        $this->repository
            ->method('findAtivasPorResponsavel')
            ->willReturn([]);

        $resultado = $this->useCase->executar($usuario, $tenant, null, null);

        self::assertSame([], $resultado);
    }

    #[TestDox('Múltiplas pastas são retornadas na ordem do repository')]
    public function testMultiplasPastasRetornadas(): void
    {
        $usuario = $this->createMock(User::class);
        $tenant  = $this->createMock(Tenant::class);

        $pasta1 = new Pasta();
        $pasta1->setNup('NUP-001');

        $pasta2 = new Pasta();
        $pasta2->setNup('NUP-002');

        $this->repository
            ->method('findAtivasPorResponsavel')
            ->willReturn([$pasta1, $pasta2]);

        $resultado = $this->useCase->executar($usuario, $tenant, null, null);

        self::assertCount(2, $resultado);
        self::assertSame($pasta1, $resultado[0]);
        self::assertSame($pasta2, $resultado[1]);
    }
}
