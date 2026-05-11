<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\EditarMarcadorUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarMarcadorUseCase::class)]
final class EditarMarcadorUseCaseTest extends TestCase
{
    private MarcadorRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $em;
    private EditarMarcadorUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(MarcadorRepository::class);
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->useCase    = new EditarMarcadorUseCase($this->repository, $this->em);

        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('user@test.com');
    }

    public function testEditarNomeDoMarcador(): void
    {
        $marcador = new Marcador('Antigo', $this->tenant, $this->usuario);

        $this->repository
            ->expects($this->once())
            ->method('findPorTenant')
            ->with(1, $this->tenant)
            ->willReturn($marcador);

        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, 'Novo Nome', null, $this->tenant);

        $this->assertSame('Novo Nome', $resultado->getNome());
        $this->assertNull($resultado->getCor());
    }

    public function testEditarCorDoMarcador(): void
    {
        $marcador = new Marcador('Marcador', $this->tenant, $this->usuario);

        $this->repository
            ->method('findPorTenant')
            ->willReturn($marcador);

        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, 'Marcador', '#e8fdf0', $this->tenant);

        $this->assertSame('#e8fdf0', $resultado->getCor());
    }

    public function testEditarComCorInvalidaLancaExcecao(): void
    {
        $marcador = new Marcador('Marcador', $this->tenant, $this->usuario);

        $this->repository
            ->method('findPorTenant')
            ->willReturn($marcador);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cor inválida.');

        $this->useCase->executar(1, 'Marcador', '#000000', $this->tenant);
    }

    public function testEditarMarcadorInexistenteLancaExcecao(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findPorTenant')
            ->with(999, $this->tenant)
            ->willReturn(null);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Marcador não encontrado.');

        $this->useCase->executar(999, 'Qualquer', null, $this->tenant);
    }
}
