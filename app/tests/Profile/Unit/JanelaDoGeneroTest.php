<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Genero;
use App\Profile\Service\JanelaDoGenero;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Quando a janela do gênero aparece (spec genero-no-cadastro, regra 3 e D1). */
#[CoversClass(JanelaDoGenero::class)]
final class JanelaDoGeneroTest extends TestCase
{
    #[TestDox('Aparece sem perfil, com gênero nulo e com o legado; não aparece com Feminino ou Masculino')]
    public function testQuemVe(): void
    {
        $sessao = new Session(new MockArraySessionStorage());

        self::assertTrue(JanelaDoGenero::deveAparecer(self::usuario(1), $sessao));
        self::assertTrue(JanelaDoGenero::deveAparecer(self::usuario(2, null, true), $sessao));
        self::assertTrue(JanelaDoGenero::deveAparecer(self::usuario(3, Genero::NaoInformado), $sessao));
        self::assertFalse(JanelaDoGenero::deveAparecer(self::usuario(4, Genero::Feminino), $sessao));
        self::assertFalse(JanelaDoGenero::deveAparecer(self::usuario(5, Genero::Masculino), $sessao));
    }

    #[TestDox('Fechar some só para quem fechou, na sessão em que fechou')]
    public function testFecharValeParaASessao(): void
    {
        $sessao = new Session(new MockArraySessionStorage());
        $ana    = self::usuario(1);

        JanelaDoGenero::fechar($ana, $sessao);

        self::assertFalse(JanelaDoGenero::deveAparecer($ana, $sessao));
        self::assertTrue(JanelaDoGenero::deveAparecer(self::usuario(2), $sessao), 'a marca é de quem fechou');
        self::assertTrue(JanelaDoGenero::deveAparecer($ana, new Session(new MockArraySessionStorage())), 'sessão nova (próximo login): volta');
    }

    private static function usuario(int $id, ?Genero $genero = null, bool $comPerfil = false): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        if ($genero !== null || $comPerfil) {
            $perfil = new UserProfile($user);
            $perfil->setGenero($genero);
            $user->setProfile($perfil);
        }

        return $user;
    }
}
