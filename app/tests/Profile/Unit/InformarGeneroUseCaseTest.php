<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Genero;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\InformarGeneroUseCase;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** A janela "Como devemos tratar você?" grava o gênero no próprio perfil (spec genero-no-cadastro, regra 3). */
#[CoversClass(InformarGeneroUseCase::class)]
final class InformarGeneroUseCaseTest extends TestCase
{
    private UserProfileRepository&MockObject $repositorio;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(UserProfileRepository::class);
    }

    #[TestDox('Com perfil: grava o gênero e salva com flush')]
    public function testGravaNoPerfilExistente(): void
    {
        $user   = new User();
        $perfil = new UserProfile($user);
        $this->repositorio->method('buscarPorUsuario')->with($user)->willReturn($perfil);
        $this->repositorio->expects(self::once())->method('salvar')->with($perfil, true);

        $genero = $this->useCase()->executar($user, 'feminino');

        self::assertSame(Genero::Feminino, $genero);
        self::assertSame(Genero::Feminino, $perfil->getGenero());
    }

    #[TestDox('Sem perfil: cria o perfil, como no Meu Perfil, e grava o gênero nele')]
    public function testCriaOPerfil(): void
    {
        $user = new User();
        $this->repositorio->method('buscarPorUsuario')->willReturn(null);
        $salvos = [];
        $this->repositorio->method('salvar')->willReturnCallback(static function (UserProfile $p) use (&$salvos): void {
            $salvos[] = $p;
        });

        $this->useCase()->executar($user, 'masculino');

        self::assertNotEmpty($salvos);
        self::assertSame($user, end($salvos)->getUser());
        self::assertSame(Genero::Masculino, end($salvos)->getGenero());
    }

    #[TestDox('Sem escolha ou com o legado: recusa antes de tocar no perfil')]
    public function testRecusaSemGenero(): void
    {
        $this->repositorio->expects(self::never())->method('buscarPorUsuario');
        $this->repositorio->expects(self::never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Informe o gênero.');

        $this->useCase()->executar(new User(), 'nao_informado');
    }

    private function useCase(): InformarGeneroUseCase
    {
        return new InformarGeneroUseCase(new ObterOuCriarPerfilUseCase($this->repositorio), $this->repositorio);
    }
}
