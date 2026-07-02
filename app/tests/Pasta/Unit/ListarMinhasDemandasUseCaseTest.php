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

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PastaRepository::class);
        $this->useCase    = new ListarMinhasDemandasUseCase($this->repository);
    }

    #[TestDox('Fixa o responsável como o próprio usuário e repassa filtros/paginação ao repositório')]
    public function testFixaResponsavelERepassaFiltros(): void
    {
        $usuario = $this->createMock(User::class);
        $usuario->method('getId')->willReturn(42);
        $tenant = $this->createMock(Tenant::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-001');

        $comResponsavel = static fn (array $f): bool =>
            ($f['responsavel'] ?? null) === '42' && ($f['status'] ?? null) === 'ativo';

        $this->repository
            ->expects($this->once())
            ->method('findByFilters')
            ->with(self::callback($comResponsavel), $tenant, 2, 25, 'prioridade', 'desc')
            ->willReturn([$pasta]);

        $this->repository
            ->expects($this->once())
            ->method('countByFilters')
            ->with(self::callback($comResponsavel), $tenant)
            ->willReturn(1);

        $resultado = $this->useCase->executar($usuario, $tenant, ['status' => 'ativo'], 2, 25, 'prioridade', 'desc');

        self::assertSame([$pasta], $resultado['pastas']);
        self::assertSame(1, $resultado['total']);
    }

    #[TestDox('Nunca deixa o request sobrescrever o responsável (segurança entre colegas)')]
    public function testResponsavelNaoVemDoRequest(): void
    {
        $usuario = $this->createMock(User::class);
        $usuario->method('getId')->willReturn(7);
        $tenant = $this->createMock(Tenant::class);

        $this->repository
            ->expects($this->once())
            ->method('findByFilters')
            ->with(self::callback(static fn (array $f): bool => ($f['responsavel'] ?? null) === '7'), $tenant, 1, 25, '', 'desc')
            ->willReturn([]);

        $this->repository->method('countByFilters')->willReturn(0);

        // Tenta injetar o responsável de outro usuário via filtros — deve ser ignorado.
        $resultado = $this->useCase->executar($usuario, $tenant, ['responsavel' => '999'], 1, 25, '', 'desc');

        self::assertSame([], $resultado['pastas']);
        self::assertSame(0, $resultado['total']);
    }
}
