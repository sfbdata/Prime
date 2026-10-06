<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaFavorita;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaFavoritaRepository;
use App\Pasta\UseCase\AlternarFavoritoDaPastaUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlternarFavoritoDaPastaUseCase::class)]
final class AlternarFavoritoDaPastaUseCaseTest extends TestCase
{
    private PastaFavoritaRepository&MockObject $repository;
    private PermissionChecker&MockObject $permissionChecker;
    private AlternarFavoritoDaPastaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->repository        = $this->createMock(PastaFavoritaRepository::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->useCase           = new AlternarFavoritoDaPastaUseCase($this->repository, $this->permissionChecker);

        $this->tenant  = new Tenant();
        $this->usuario = new User();
        $this->pasta   = new Pasta();
        $this->pasta->setNup('1234');
        $this->pasta->setTenant($this->tenant);
    }

    #[TestDox('Pasta que ainda não é favorita passa a ser: grava a preferência do usuário e devolve true')]
    public function testFavoritaQuandoNaoEra(): void
    {
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository
            ->expects(self::once())
            ->method('buscarDoUsuario')
            ->with($this->pasta, $this->usuario, $this->tenant)
            ->willReturn(null);
        $this->repository->expects(self::never())->method('remover');
        $this->repository
            ->expects(self::once())
            ->method('salvar')
            ->with(
                self::callback(fn (PastaFavorita $f): bool => $f->getPasta() === $this->pasta
                    && $f->getUsuario() === $this->usuario
                    && $f->getTenant() === $this->tenant),
                true,
            );

        self::assertTrue($this->useCase->executar($this->pasta, $this->usuario, $this->tenant));
    }

    #[TestDox('Pasta que já é favorita deixa de ser: apaga a preferência e devolve false')]
    public function testDesfavoritaQuandoEra(): void
    {
        $existente = new PastaFavorita($this->tenant, $this->usuario, $this->pasta);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository->method('buscarDoUsuario')->willReturn($existente);
        $this->repository->expects(self::never())->method('salvar');
        $this->repository->expects(self::once())->method('remover')->with($existente, true);

        self::assertFalse($this->useCase->executar($this->pasta, $this->usuario, $this->tenant));
    }

    #[TestDox('Pasta de OUTRO escritório é recusada antes de qualquer consulta ou gravação')]
    public function testPastaDeOutroEscritorio(): void
    {
        $outro = new Tenant();
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->repository->expects(self::never())->method('buscarDoUsuario');
        $this->repository->expects(self::never())->method('salvar');
        $this->repository->expects(self::never())->method('remover');

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase->executar($this->pasta, $this->usuario, $outro);
    }

    #[TestDox('Quem não pode VER a pasta não a favorita — e a pergunta é a de ver a pasta')]
    public function testSemPermissaoDeVer(): void
    {
        $this->permissionChecker
            ->expects(self::once())
            ->method('canAccessResource')
            ->with($this->usuario, $this->tenant, AccessRequest::RESOURCE_PASTA, self::anything(), AccessRequest::ACTION_VIEW)
            ->willReturn(false);
        $this->repository->expects(self::never())->method('buscarDoUsuario');
        $this->repository->expects(self::never())->method('salvar');
        $this->repository->expects(self::never())->method('remover');

        $this->expectException(SemPermissaoParaVerPastaException::class);

        $this->useCase->executar($this->pasta, $this->usuario, $this->tenant);
    }
}
