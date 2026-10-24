<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Pasta\UseCase\AlternarAcompanhamentoDaPastaUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlternarAcompanhamentoDaPastaUseCase::class)]
final class AlternarAcompanhamentoDaPastaUseCaseTest extends TestCase
{
    private PastaAcompanhamentoDesligadoRepository&MockObject $repository;
    private PermissionChecker&MockObject $permissionChecker;
    private AlternarAcompanhamentoDaPastaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->repository        = $this->createMock(PastaAcompanhamentoDesligadoRepository::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->useCase           = new AlternarAcompanhamentoDaPastaUseCase($this->repository, $this->permissionChecker);

        $this->tenant  = new Tenant();
        $this->usuario = new User();
        $this->pasta   = new Pasta();
        $this->pasta->setNup('1234');
        $this->pasta->setTenant($this->tenant);
    }

    #[TestDox('Padrão ligado: o primeiro clique desliga (grava a exceção, devolve false) e o segundo religa (apaga, devolve true)')]
    public function testPadraoLigadoDesligaELiga(): void
    {
        // Dublê com estado, como a tabela: sem linha = acompanhando.
        $desligado = false;
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository->method('estaDesligado')
            ->with($this->pasta, $this->usuario, $this->tenant)
            ->willReturnCallback(function () use (&$desligado): bool {
                return $desligado;
            });
        $this->repository->expects(self::once())->method('desligar')
            ->with($this->tenant, $this->usuario, $this->pasta)
            ->willReturnCallback(function () use (&$desligado): void {
                $desligado = true;
            });
        $this->repository->expects(self::once())->method('religar')
            ->with($this->tenant, $this->usuario, $this->pasta)
            ->willReturnCallback(function () use (&$desligado): void {
                $desligado = false;
            });

        self::assertFalse($this->useCase->executar($this->pasta, $this->usuario, $this->tenant), 'ligado por padrão: o clique desliga');
        self::assertTrue($this->useCase->executar($this->pasta, $this->usuario, $this->tenant), 'desligado: o clique religa');
    }

    #[TestDox('Pasta de OUTRO escritório é recusada antes de qualquer consulta ou gravação')]
    public function testPastaDeOutroEscritorio(): void
    {
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->repository->expects(self::never())->method('estaDesligado');
        $this->repository->expects(self::never())->method('desligar');
        $this->repository->expects(self::never())->method('religar');

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase->executar($this->pasta, $this->usuario, new Tenant());
    }

    #[TestDox('Quem não pode VER a pasta não mexe no acompanhamento, e a pergunta é a de ver a pasta')]
    public function testSemPermissaoDeVer(): void
    {
        $this->permissionChecker
            ->expects(self::once())
            ->method('canAccessResource')
            ->with($this->usuario, $this->tenant, AccessRequest::RESOURCE_PASTA, self::anything(), AccessRequest::ACTION_VIEW)
            ->willReturn(false);
        $this->repository->expects(self::never())->method('estaDesligado');
        $this->repository->expects(self::never())->method('desligar');
        $this->repository->expects(self::never())->method('religar');

        $this->expectException(SemPermissaoParaVerPastaException::class);

        $this->useCase->executar($this->pasta, $this->usuario, $this->tenant);
    }
}
