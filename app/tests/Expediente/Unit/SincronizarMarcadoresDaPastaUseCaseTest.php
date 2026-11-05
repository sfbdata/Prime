<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\SincronizarMarcadoresDaPastaUseCase;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(SincronizarMarcadoresDaPastaUseCase::class)]
final class SincronizarMarcadoresDaPastaUseCaseTest extends TestCase
{
    private PastaRepository&MockObject $pastaRepository;
    private MarcadorRepository&MockObject $marcadorRepository;
    private EntityManagerInterface&MockObject $em;
    private PermissionChecker&MockObject $permissoes;
    private SincronizarMarcadoresDaPastaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->pastaRepository    = $this->createMock(PastaRepository::class);
        $this->marcadorRepository = $this->createMock(MarcadorRepository::class);
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->permissoes         = $this->createMock(PermissionChecker::class);
        $this->permissoes->method('canAccessResource')->willReturn(true);
        $this->useCase = new SincronizarMarcadoresDaPastaUseCase(
            $this->pastaRepository,
            $this->marcadorRepository,
            $this->em,
            $this->permissoes,
        );

        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('user@test.com');
    }

    public function testPastaInexistenteLancaExcecao(): void
    {
        // A busca é por id E escritório: pasta de outro escritório também cai aqui (404).
        $this->pastaRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 99, 'tenant' => $this->tenant])
            ->willReturn(null);

        $this->marcadorRepository->expects($this->never())->method('findPorTenant');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Pasta não encontrada.');

        $this->useCase->executar(99, [1], $this->usuario, $this->tenant);
    }

    public function testMarcadorDeOutroTenantLancaExcecao(): void
    {
        $this->pastaRepository->method('findOneBy')->willReturn(new Pasta());

        $this->marcadorRepository->expects($this->once())
            ->method('findPorTenant')
            ->with(7, $this->tenant)
            ->willReturn(null);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Marcador não encontrado.');

        $this->useCase->executar(1, [7], $this->usuario, $this->tenant);
    }

    public function testSincronizarComListaVaziaRetornaPastaEChamaFlush(): void
    {
        $pasta = new Pasta();

        $this->pastaRepository->method('findOneBy')->willReturn($pasta);
        $this->marcadorRepository->expects($this->never())->method('findPorTenant');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, [], $this->usuario, $this->tenant);

        $this->assertSame($pasta, $resultado);
    }

    public function testSincronizarComUmMarcadorChamaFindPorTenantEFlush(): void
    {
        $pasta    = new Pasta();
        $marcador = new Marcador('Urgente', $this->tenant, $this->usuario);

        $this->pastaRepository->method('findOneBy')->willReturn($pasta);
        $this->marcadorRepository->expects($this->once())
            ->method('findPorTenant')
            ->with(10, $this->tenant)
            ->willReturn($marcador);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, [10], $this->usuario, $this->tenant);

        $this->assertSame($pasta, $resultado);
    }

    public function testSincronizarComDoisMarcadoresChamaFindPorTenantDuasVezes(): void
    {
        $pasta = new Pasta();
        $m1    = new Marcador('Urgente', $this->tenant, $this->usuario);
        $m2    = new Marcador('Aguardando', $this->tenant, $this->usuario);

        $this->pastaRepository->method('findOneBy')->willReturn($pasta);
        $this->marcadorRepository->expects($this->exactly(2))
            ->method('findPorTenant')
            ->willReturnMap([
                [10, $this->tenant, $m1],
                [20, $this->tenant, $m2],
            ]);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(1, [10, 20], $this->usuario, $this->tenant);

        $this->assertSame($pasta, $resultado);
    }

    public function testSemPermissaoDeEditarAPastaLancaSemGravar(): void
    {
        $permissoes = $this->createMock(PermissionChecker::class);
        $permissoes->expects($this->once())
            ->method('canAccessResource')
            ->with($this->usuario, $this->tenant, 'pasta', 5, 'edit')
            ->willReturn(false);
        $useCase = new SincronizarMarcadoresDaPastaUseCase($this->pastaRepository, $this->marcadorRepository, $this->em, $permissoes);
        $pasta = new Pasta();
        $pasta->addMarcador(new Marcador('Antigo', $this->tenant, $this->usuario));
        $this->pastaRepository->method('findOneBy')->willReturn($pasta);
        $this->marcadorRepository->expects($this->never())->method('findPorTenant');
        $this->em->expects($this->never())->method('flush');

        try {
            $useCase->executar(5, [], $this->usuario, $this->tenant);
            self::fail('devia recusar quem não edita a pasta');
        } catch (SemPermissaoParaEditarPastaException) {
            self::assertCount(1, $pasta->getMarcadores(), 'nada muda na pasta');
        }
    }
}
