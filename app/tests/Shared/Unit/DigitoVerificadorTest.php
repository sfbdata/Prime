<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\DigitoVerificador;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DigitoVerificador::class)]
final class DigitoVerificadorTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function cpfs(): iterable
    {
        yield 'válido com máscara' => ['529.982.247-25', true];
        yield 'válido só dígitos' => ['52998224725', true];
        yield 'primeiro dígito errado' => ['529.982.247-35', false];
        yield 'segundo dígito errado' => ['529.982.247-26', false];
        yield 'sequência repetida' => ['111.111.111-11', false];
        yield 'curto' => ['5299822472', false];
        yield 'vazio' => ['', false];
    }

    #[DataProvider('cpfs')]
    #[TestDox('CPF $cpf → $valido')]
    public function testCpf(string $cpf, bool $valido): void
    {
        self::assertSame($valido, DigitoVerificador::cpfValido($cpf));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cnpjs(): iterable
    {
        yield 'válido com máscara' => ['11.222.333/0001-81', true];
        yield 'dígito errado' => ['11.222.333/0001-82', false];
        yield 'repetido' => ['00.000.000/0000-00', false];
    }

    #[DataProvider('cnpjs')]
    #[TestDox('CNPJ $cnpj → $valido')]
    public function testCnpj(string $cnpj, bool $valido): void
    {
        self::assertSame($valido, DigitoVerificador::cnpjValido($cnpj));
    }
}
