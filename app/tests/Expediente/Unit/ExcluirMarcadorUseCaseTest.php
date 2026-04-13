<?php

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\ExcluirMarcadorUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExcluirMarcadorUseCaseTest extends TestCase
{
    private MarcadorRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $em;
    private ExcluirMarcadorUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(MarcadorRepository::class);
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->useCase    = new ExcluirMarcadorUseCase($this->repository, $this->em);

        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('user@test.com')->setTenant($this->tenant);
    }

    public function testExcluirMarcadorExistente(): void
    {
        $marcador = new Marcador('Para excluir', $this->tenant, $this->usuario);

        $this->repository
            ->expects($this->once())
            ->method('findPorTenant')
            ->with(5, $this->tenant)
            ->willReturn($marcador);

        $this->em->expects($this->once())->method('remove')->with($marcador);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(5, $this->usuario);

        $this->assertSame($marcador, $resultado);
    }

    public function testExcluirMarcadorInexistenteLancaExcecao(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findPorTenant')
            ->with(999, $this->tenant)
            ->willReturn(null);

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Marcador não encontrado.');

        $this->useCase->executar(999, $this->usuario);
    }

    public function testExcluirNaoAfetaMarcadorDeOutroTenant(): void
    {
        $outraTenant = new Tenant();
        $outroUsuario = (new User())->setEmail('outro@test.com')->setTenant($outraTenant);

        // repository filtra por tenant — retorna null para outro tenant
        $this->repository
            ->expects($this->once())
            ->method('findPorTenant')
            ->with(1, $outraTenant)
            ->willReturn(null);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(1, $outroUsuario);
    }
}
