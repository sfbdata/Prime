<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Google\IdTokenDoGoogle;
use App\Shared\Google\IdTokenDoGoogleInvalidoException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdTokenDoGoogle::class)]
final class IdTokenDoGoogleTest extends TestCase
{
    private const BOA = ['sub' => 's1', 'email' => ' pessoa@gmail.com ', 'email_verified' => true, 'aud' => 'cli', 'iss' => 'https://accounts.google.com', 'nonce' => 'n1'];

    #[TestDox('Para o nosso cliente (texto ou lista), do Google (as duas formas do iss), válido e com o nonce do pedido: devolve a carga com o e-mail aparado')]
    public function testValida(): void
    {
        $sut = new IdTokenDoGoogle();

        self::assertSame('pessoa@gmail.com', $sut->conferir($this->token(self::BOA), 'cli', 'n1')['email']);
        self::assertSame('s1', $sut->conferir($this->token(['aud' => ['outro', 'cli'], 'iss' => 'accounts.google.com'] + self::BOA), 'cli', 'n1')['sub']);
        self::assertSame('s1', $sut->conferir($this->token(self::BOA), 'cli')['sub'], 'sem nonce pedido (a Agenda), não confere nonce');
    }

    #[TestDox('Recusa: $_dataName')]
    #[DataProvider('invalidos')]
    public function testRecusa(string $token, string $cliente, ?string $nonce): void
    {
        $this->expectException(IdTokenDoGoogleInvalidoException::class);

        (new IdTokenDoGoogle())->conferir($token, $cliente, $nonce);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidos(): iterable
    {
        $t = static fn (array $c): string => (new self('x'))->token($c);
        yield 'outro cliente' => [$t(self::BOA), 'outro', 'n1'];
        yield 'cliente vazio no servidor' => [$t(['aud' => ''] + self::BOA), '', 'n1'];
        yield 'outro emissor' => [$t(['iss' => 'https://mal.example'] + self::BOA), 'cli', 'n1'];
        yield 'vencido' => [$t(['exp' => time() - 1] + self::BOA), 'cli', 'n1'];
        yield 'validade em texto' => [$t(['exp' => (string) (time() + 60)] + self::BOA), 'cli', 'n1'];
        yield 'nonce de outro pedido' => [$t(self::BOA), 'cli', 'n2'];
        yield 'sem nonce quando foi pedido' => [$t(['nonce' => null] + self::BOA), 'cli', 'n1'];
        yield 'e-mail não confirmado' => [$t(['email_verified' => false] + self::BOA), 'cli', 'n1'];
        yield 'confirmação em texto' => [$t(['email_verified' => 'true'] + self::BOA), 'cli', 'n1'];
        yield 'sem sub' => [$t(['sub' => ''] + self::BOA), 'cli', 'n1'];
        yield 'token que não é JWT' => ['qualquer-coisa', 'cli', 'n1'];
    }

    /** @param array<string, mixed> $carga */
    private function token(array $carga): string
    {
        $carga += ['exp' => time() + 3600];

        return 'cab.' . rtrim(strtr(base64_encode((string) json_encode($carga)), '+/', '-_'), '=') . '.ass';
    }
}
