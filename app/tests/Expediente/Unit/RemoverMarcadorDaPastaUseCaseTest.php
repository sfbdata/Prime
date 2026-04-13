<?php

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\RemoverMarcadorDaPastaUseCase;
use App\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RemoverMarcadorDaPastaUseCaseTest extends TestCase
{
    private PastaRepository&MockObject $pastaRepository;
    private MarcadorRepository&MockObject $marcadorRepository;
    private EntityManagerInterface&MockObject $em;
    private RemoverMarcadorDaPastaUseCase $useCase;

    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->pastaRepository    = $this->createMock(PastaRepository::class);
        $this->marcadorRepository = $this->createMock(MarcadorRepository::class);
        $this->em                 = $this->createMock(EntityManagerInterface::class);

        $this->useCase = new RemoverMarcadorDaPastaUseCase(
            $this->pastaRepository,
            $this->marcadorRepository,
            $this->em,
        );

        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('user@test.com')->setTenant($this->tenant);
    }

    private function criarPasta(): Pasta
    {
        return new Pasta();
    }

    private function criarMarcador(string $nome): Marcador
    {
        return new Marcador($nome, $this->tenant, $this->usuario);
    }

    public function testDesmarcarUmMarcador(): void
    {
        $pasta    = $this->criarPasta();
        $marcador = $this->criarMarcador('Urgente');
        $pasta->addMarcador($marcador);

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);
        $this->marcadorRepository
            ->method('findPorTenant')
            ->with(10, $this->tenant)
            ->willReturn($marcador);

        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, [10], $this->usuario);

        $this->assertCount(0, $resultado->getMarcadores());
        $this->assertFalse($resultado->hasMarcador($marcador));
    }

    public function testDesmarcarVariosMarcadores(): void
    {
        $pasta     = $this->criarPasta();
        $marcador1 = $this->criarMarcador('Urgente');
        $marcador2 = $this->criarMarcador('Aguardando');
        $marcador3 = $this->criarMarcador('Concluído');

        $pasta->addMarcador($marcador1);
        $pasta->addMarcador($marcador2);
        $pasta->addMarcador($marcador3);

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);
        $this->marcadorRepository
            ->method('findPorTenant')
            ->willReturnMap([
                [10, $this->tenant, $marcador1],
                [20, $this->tenant, $marcador2],
            ]);

        $this->em->expects($this->once())->method('flush');

        // Remove apenas marcador1 e marcador2 — marcador3 deve permanecer
        $resultado = $this->useCase->executar(1, [10, 20], $this->usuario);

        $this->assertCount(1, $resultado->getMarcadores());
        $this->assertFalse($resultado->hasMarcador($marcador1));
        $this->assertFalse($resultado->hasMarcador($marcador2));
        $this->assertTrue($resultado->hasMarcador($marcador3));
    }

    public function testDesmarcarMarcadorInexistenteLancaExcecao(): void
    {
        $pasta = $this->criarPasta();

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);
        $this->marcadorRepository
            ->method('findPorTenant')
            ->with(99, $this->tenant)
            ->willReturn(null);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Marcador não encontrado.');

        $this->useCase->executar(1, [99], $this->usuario);
    }

    public function testDesmarcarMarcadorDePastaInexistenteLancaExcecao(): void
    {
        $this->pastaRepository->method('find')->with(999)->willReturn(null);
        $this->marcadorRepository->expects($this->never())->method('findPorTenant');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Pasta não encontrada.');

        $this->useCase->executar(999, [10], $this->usuario);
    }

    public function testDesmarcarMarcadorDeOutroTenantLancaExcecao(): void
    {
        $pasta        = $this->criarPasta();
        $outraTenant  = new Tenant();
        $outroUsuario = (new User())->setEmail('outro@test.com')->setTenant($outraTenant);

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);
        $this->marcadorRepository
            ->method('findPorTenant')
            ->with(10, $outraTenant)
            ->willReturn(null);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);

        $this->useCase->executar(1, [10], $outroUsuario);
    }
}
