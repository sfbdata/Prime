<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObterOuCriarPerfilUseCase::class)]
final class ObterOuCriarPerfilUseCaseTest extends TestCase
{
    private UserProfileRepository&MockObject $repository;
    private ObterOuCriarPerfilUseCase $sut;
    private User $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(UserProfileRepository::class);
        $this->sut = new ObterOuCriarPerfilUseCase($this->repository);
        $this->user = $this->createStub(User::class);
    }

    #[TestDox('Retorna perfil existente sem criar novo')]
    public function testRetornaPerfilExistente(): void
    {
        $perfilExistente = new UserProfile($this->user);

        $this->repository
            ->expects($this->once())
            ->method('buscarPorUsuario')
            ->with($this->user)
            ->willReturn($perfilExistente);

        $this->repository->expects($this->never())->method('salvar');

        $resultado = $this->sut->executar($this->user);

        self::assertSame($perfilExistente, $resultado);
    }

    #[TestDox('Cria novo perfil quando não existe e persiste via salvar')]
    public function testCriaNovoPerfilQuandoNaoExiste(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('buscarPorUsuario')
            ->with($this->user)
            ->willReturn(null);

        $this->repository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(UserProfile::class), true);

        $resultado = $this->sut->executar($this->user);

        self::assertInstanceOf(UserProfile::class, $resultado);
        self::assertSame($this->user, $resultado->getUser());
    }

    #[TestDox('Novo perfil criado tem estado inicial correto')]
    public function testNovoPerfilTemEstadoInicialCorreto(): void
    {
        $this->repository->method('buscarPorUsuario')->willReturn(null);
        $this->repository->method('salvar');

        $resultado = $this->sut->executar($this->user);

        self::assertNull($resultado->getFotoUrl());
        self::assertNull($resultado->getStatus());
        self::assertNull($resultado->getNomeCompleto());
        self::assertInstanceOf(\DateTimeImmutable::class, $resultado->getCriadoEm());
    }

    #[TestDox('Não cria duplicado se perfil já existe')]
    public function testNaoCriaDuplicado(): void
    {
        $perfilExistente = new UserProfile($this->user);
        $this->repository->method('buscarPorUsuario')->willReturn($perfilExistente);

        $this->repository->expects($this->never())->method('salvar');

        $this->sut->executar($this->user);
        $this->sut->executar($this->user);
    }
}
