<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Service\ForcaDaSenha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A régua tem de contar igual à da tela (app/public/js/auth/entrada.js): "símbolo" é qualquer caractere fora de
 * [A-Za-z0-9_], inclusive letra acentuada. Se o servidor contar diferente, a tela deixa enviar e o servidor recusa.
 */
#[CoversClass(ForcaDaSenha::class)]
final class ForcaDaSenhaTest extends TestCase
{
    /** @return iterable<string, array{string, int, bool}> */
    public static function senhas(): iterable
    {
        yield 'vazia' => ['', 0, false];
        yield 'só minúsculas' => ['abcdefgh', 1, false];
        yield 'letra acentuada conta como símbolo' => ['abcdéfgh', 2, true];
        yield 'minúsculas e número' => ['abcdefg1', 2, true];
        yield 'maiúscula, minúscula e número' => ['Abcdefg1', 3, true];
        yield 'as quatro' => ['Abcdef1!', 4, true];
        yield 'curta mesmo forte' => ['Ab1!', 3, false];
        yield 'sublinhado não é símbolo' => ['abcdefg_', 1, false];
    }

    #[DataProvider('senhas')]
    #[TestDox('Pontos e aceite da régua do desenho')]
    public function testRegua(string $senha, int $pontos, bool $aceita): void
    {
        self::assertSame($pontos, ForcaDaSenha::pontos($senha));
        self::assertSame($aceita, ForcaDaSenha::aceita($senha));
    }
}
