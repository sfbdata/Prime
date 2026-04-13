<?php

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\MoverPastaMarcadoresUseCase;
use App\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MoverPastaMarcadoresUseCaseTest extends TestCase
{
    private PastaRepository&MockObject $pastaRepository;
    private MarcadorRepository&MockObject $marcadorRepository;
    private EntityManagerInterface&MockObject $em;
    private MoverPastaMarcadoresUseCase $useCase;

    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->pastaRepository    = $this->createMock(PastaRepository::class);
        $this->marcadorRepository = $this->createMock(MarcadorRepository::class);
        $this->em                 = $this->createMock(EntityManagerInterface::class);

        $this->useCase = new MoverPastaMarcadoresUseCase(
            $this->pastaRepository,
            $this->marcadorRepository,
            $this->em,
        );

        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('user@test.com')->setTenant($this->tenant);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function criarPasta(): Pasta
    {
        return new Pasta();
    }

    private function criarMarcador(string $nome): Marcador
    {
        return new Marcador($nome, $this->tenant, $this->usuario);
    }

    // ------------------------------------------------------------------
    // Vincular um marcador a uma pasta
    // ------------------------------------------------------------------

    public function testVincularUmMarcadorAPasta(): void
    {
        $pasta    = $this->criarPasta();
        $marcador = $this->criarMarcador('Urgente');

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);
        $this->marcadorRepository
            ->method('findPorTenant')
            ->with(10, $this->tenant)
            ->willReturn($marcador);

        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, [10], $this->usuario);

        $this->assertTrue($resultado->hasMarcador($marcador));
        $this->assertCount(1, $resultado->getMarcadores());
    }

    // ------------------------------------------------------------------
    // Lista vazia — nada é adicionado, nada é removido
    // ------------------------------------------------------------------

    public function testListaVaziaNaoAlteraMarcadoresExistentes(): void
    {
        $pasta    = $this->criarPasta();
        $marcador = $this->criarMarcador('Urgente');
        $pasta->addMarcador($marcador);

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);
        $this->marcadorRepository->expects($this->never())->method('findPorTenant');

        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, [], $this->usuario);

        $this->assertTrue($resultado->hasMarcador($marcador));
        $this->assertCount(1, $resultado->getMarcadores());
    }

    // ------------------------------------------------------------------
    // Vincular vários marcadores a uma pasta
    // ------------------------------------------------------------------

    public function testVincularVariosMarcadoresAPasta(): void
    {
        $pasta     = $this->criarPasta();
        $marcador1 = $this->criarMarcador('Urgente');
        $marcador2 = $this->criarMarcador('Aguardando');
        $marcador3 = $this->criarMarcador('Concluído');

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);

        $this->marcadorRepository
            ->method('findPorTenant')
            ->willReturnMap([
                [10, $this->tenant, $marcador1],
                [20, $this->tenant, $marcador2],
                [30, $this->tenant, $marcador3],
            ]);

        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, [10, 20, 30], $this->usuario);

        $this->assertCount(3, $resultado->getMarcadores());
        $this->assertTrue($resultado->hasMarcador($marcador1));
        $this->assertTrue($resultado->hasMarcador($marcador2));
        $this->assertTrue($resultado->hasMarcador($marcador3));
    }

    // ------------------------------------------------------------------
    // Acréscimo — marcadores anteriores são preservados
    // ------------------------------------------------------------------

    public function testAdicionarMarcadoresPreservaPreviamenteVinculados(): void
    {
        $pasta     = $this->criarPasta();
        $marcador1 = $this->criarMarcador('Urgente');
        $marcador2 = $this->criarMarcador('Aguardando');
        $marcador3 = $this->criarMarcador('Concluído');

        // Pasta já tem marcador1 e marcador2
        $pasta->addMarcador($marcador1);
        $pasta->addMarcador($marcador2);

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);

        $this->marcadorRepository
            ->method('findPorTenant')
            ->with(30, $this->tenant)
            ->willReturn($marcador3);

        $this->em->expects($this->once())->method('flush');

        // Acrescenta apenas marcador3 — os dois anteriores devem permanecer
        $resultado = $this->useCase->executar(1, [30], $this->usuario);

        $this->assertCount(3, $resultado->getMarcadores());
        $this->assertTrue($resultado->hasMarcador($marcador1));
        $this->assertTrue($resultado->hasMarcador($marcador2));
        $this->assertTrue($resultado->hasMarcador($marcador3));
    }

    // ------------------------------------------------------------------
    // Erros — pasta não encontrada
    // ------------------------------------------------------------------

    public function testPastaInexistenteLancaExcecao(): void
    {
        $this->pastaRepository->method('find')->with(999)->willReturn(null);
        $this->marcadorRepository->expects($this->never())->method('findPorTenant');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Pasta não encontrada.');

        $this->useCase->executar(999, [1], $this->usuario);
    }

    // ------------------------------------------------------------------
    // Erros — marcador de outro tenant
    // ------------------------------------------------------------------

    public function testMarcadorDeOutroTenantLancaExcecao(): void
    {
        $pasta = $this->criarPasta();

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);

        // repository retorna null (marcador não pertence ao tenant)
        $this->marcadorRepository
            ->method('findPorTenant')
            ->with(99, $this->tenant)
            ->willReturn(null);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Marcador não encontrado.');

        $this->useCase->executar(1, [99], $this->usuario);
    }

    // ------------------------------------------------------------------
    // Acúmulo — vincular 1, depois vincular mais 2 (duas chamadas)
    // ------------------------------------------------------------------

    public function testVincularUmDepoisMaisDoisMarcadores(): void
    {
        $pasta     = $this->criarPasta();
        $marcador1 = $this->criarMarcador('Urgente');
        $marcador2 = $this->criarMarcador('Aguardando');
        $marcador3 = $this->criarMarcador('Concluído');

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);

        $this->marcadorRepository
            ->method('findPorTenant')
            ->willReturnMap([
                [10, $this->tenant, $marcador1],
                [20, $this->tenant, $marcador2],
                [30, $this->tenant, $marcador3],
            ]);

        $this->em->expects($this->exactly(2))->method('flush');

        // --- Primeira chamada: vincula apenas marcador1 ---
        $this->useCase->executar(1, [10], $this->usuario);

        $this->assertCount(1, $pasta->getMarcadores());
        $this->assertTrue($pasta->hasMarcador($marcador1));

        // --- Segunda chamada: acrescenta marcador2 e marcador3 ---
        // O cliente envia apenas os novos — o anterior é mantido automaticamente
        $this->useCase->executar(1, [20, 30], $this->usuario);

        $this->assertCount(3, $pasta->getMarcadores());
        $this->assertTrue($pasta->hasMarcador($marcador1));
        $this->assertTrue($pasta->hasMarcador($marcador2));
        $this->assertTrue($pasta->hasMarcador($marcador3));
    }

    // ------------------------------------------------------------------
    // Idempotência — vincular mesmo marcador duas vezes
    // ------------------------------------------------------------------

    public function testVincularMesmoMarcadorDuasVezesNaoDuplica(): void
    {
        $pasta    = $this->criarPasta();
        $marcador = $this->criarMarcador('Urgente');

        $this->pastaRepository->method('find')->with(1)->willReturn($pasta);

        $this->marcadorRepository
            ->method('findPorTenant')
            ->willReturn($marcador);

        $this->em->expects($this->once())->method('flush');

        // Passa o mesmo id duas vezes na lista
        $resultado = $this->useCase->executar(1, [10, 10], $this->usuario);

        // addMarcador usa contains() — não deve duplicar
        $this->assertCount(1, $resultado->getMarcadores());
    }
}
