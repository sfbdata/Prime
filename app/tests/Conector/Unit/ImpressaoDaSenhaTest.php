<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\ImpressaoDaSenha;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImpressaoDaSenha::class)]
final class ImpressaoDaSenhaTest extends TestCase
{
    private static function pessoa(?string $hashDaSenha): User
    {
        $user = new User();
        if ($hashDaSenha !== null) {
            $user->setPassword($hashDaSenha);
        }

        return $user;
    }

    #[TestDox('A impressão tem o formato da coluna (64 hexadecimais) e não é o próprio hash da senha')]
    public function testFormato(): void
    {
        $impressao = (new ImpressaoDaSenha('segredo-da-aplicacao'))->de(self::pessoa('$2y$04$hashDaSenha'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $impressao);
        self::assertNotSame(hash('sha256', '$2y$04$hashDaSenha'), $impressao);
    }

    #[TestDox('Mesmo hash, mesma impressão; senha trocada (hash novo) muda a impressão (seção 4, regra 3)')]
    public function testMudaComOHash(): void
    {
        $sut = new ImpressaoDaSenha('segredo-da-aplicacao');

        self::assertSame($sut->de(self::pessoa('$2y$04$antigo')), $sut->de(self::pessoa('$2y$04$antigo')));
        self::assertNotSame($sut->de(self::pessoa('$2y$04$antigo')), $sut->de(self::pessoa('$2y$04$novo')));
    }

    #[TestDox('Outro segredo da aplicação dá outra impressão: sem o segredo, não se calcula a impressão de um hash')]
    public function testDependeDoSegredo(): void
    {
        $user = self::pessoa('$2y$04$hash');

        self::assertNotSame((new ImpressaoDaSenha('um'))->de($user), (new ImpressaoDaSenha('outro'))->de($user));
    }

    #[TestDox('Conta sem senha (só Google ou Facebook) tem impressão fixa; criar a senha muda a impressão')]
    public function testContaSemSenha(): void
    {
        $sut = new ImpressaoDaSenha('segredo-da-aplicacao');

        self::assertSame($sut->de(self::pessoa(null)), $sut->de(self::pessoa(null)));
        self::assertNotSame($sut->de(self::pessoa(null)), $sut->de(self::pessoa('$2y$04$criada')));
    }
}
