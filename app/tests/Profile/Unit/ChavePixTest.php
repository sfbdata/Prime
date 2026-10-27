<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Profile\Service\ChavePix;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChavePix::class)]
final class ChavePixTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validas(): iterable
    {
        yield 'CPF só com números' => ['52998224725'];
        yield 'CPF com pontuação' => ['529.982.247-25'];
        yield 'CNPJ com pontuação' => ['11.222.333/0001-81'];
        yield 'e-mail' => ['ana@pix.com.br'];
        yield 'celular só com números' => ['11987654321'];
        yield 'celular com +55' => ['+5511987654321'];
        yield 'celular com 55 e pontuação' => ['+55 (11) 98765-4321'];
        yield 'chave aleatória' => ['123e4567-e89b-42d3-a456-426614174000'];
        yield 'aparada' => ['  ana@pix.com.br  '];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidas(): iterable
    {
        yield 'CPF com dígito errado' => ['12345678900'];
        yield 'CPF repetido' => ['11111111111'];
        yield 'CNPJ com dígito errado' => ['11222333000182'];
        yield 'e-mail sem domínio' => ['ana@'];
        yield 'telefone fixo' => ['1132654321'];
        yield 'celular sem o nove' => ['11887654321'];
        yield 'DDD inexistente' => ['02987654321'];
        yield 'UUID quebrado' => ['123e4567-e89b-42d3-a456-42661417400'];
        yield 'texto solto' => ['minha chave'];
        yield 'mais de 140 caracteres' => [str_repeat('a', 130) . '@pix.com'];
    }

    #[DataProvider('validas')]
    #[TestDox('aceita a chave: $chave')]
    public function testValida(string $chave): void
    {
        self::assertTrue(ChavePix::valida($chave));
    }

    #[DataProvider('invalidas')]
    #[TestDox('recusa a chave: $chave')]
    public function testInvalida(string $chave): void
    {
        self::assertFalse(ChavePix::valida($chave));
    }

    #[TestDox('vazio ou só espaços não é chave (o campo é opcional, quem decide é o DTO)')]
    public function testVazio(): void
    {
        self::assertFalse(ChavePix::valida(''));
        self::assertFalse(ChavePix::valida('   '));
    }
}
