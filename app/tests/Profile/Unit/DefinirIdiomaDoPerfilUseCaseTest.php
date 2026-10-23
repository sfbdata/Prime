<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Idioma;
use App\Profile\Exception\IdiomaIndisponivelException;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\DefinirIdiomaDoPerfilUseCase;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinirIdiomaDoPerfilUseCase::class)]
final class DefinirIdiomaDoPerfilUseCaseTest extends TestCase
{
    #[TestDox('idioma liberado: grava no perfil (que nasce se não existir) e devolve o idioma')]
    public function testGravaIdiomaLiberado(): void
    {
        $usuario = new User();
        $perfil = new UserProfile($usuario);
        $repositorio = $this->createMock(UserProfileRepository::class);
        $repositorio->method('buscarPorUsuario')->willReturn($perfil);
        $repositorio->expects(self::once())->method('salvar')->with($perfil, true);

        $idioma = (new DefinirIdiomaDoPerfilUseCase(new ObterOuCriarPerfilUseCase($repositorio), $repositorio))->executar($usuario, 'pt-BR');

        self::assertSame(Idioma::PortuguesBrasil, $idioma);
        self::assertSame(Idioma::PortuguesBrasil, $perfil->getIdioma());
    }

    #[TestDox('idioma não liberado (inglês e espanhol "em breve", os que saíram do seletor) ou desconhecido: recusa sem gravar')]
    public function testRecusaNaoLiberado(): void
    {
        $repositorio = $this->createMock(UserProfileRepository::class);
        $repositorio->expects(self::never())->method('salvar');
        $useCase = new DefinirIdiomaDoPerfilUseCase(new ObterOuCriarPerfilUseCase($repositorio), $repositorio);

        foreach (['en', 'es', 'fr', 'ar', 'xx', ''] as $valor) {
            try {
                $useCase->executar(new User(), $valor);
                self::fail('deveria recusar ' . $valor);
            } catch (IdiomaIndisponivelException $e) {
                self::assertSame('Este idioma ainda não está disponível.', $e->getMessage());
            }
        }
    }
}
