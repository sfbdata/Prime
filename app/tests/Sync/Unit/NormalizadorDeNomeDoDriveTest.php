<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Sync\Service\NormalizadorDeNomeDoDrive;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(NormalizadorDeNomeDoDrive::class)]
final class NormalizadorDeNomeDoDriveTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function nomesIguais(): iterable
    {
        yield 'caixa' => ['10 - JOSE - TRABALHISTA', '10 - jose - trabalhista'];
        yield 'acento' => ['10 - JOSÉ CONCEIÇÃO - AÇÃO', '10 - jose conceicao - acao'];
        yield 'espacos repetidos e bordas' => ["  10  -   JOSE\t- X  ", '10 - jose - x'];
        yield 'traço longo vira hífen' => ["10 \u{2014} JOSE \u{2013} X", '10 - jose - x'];
    }

    #[DataProvider('nomesIguais')]
    #[TestDox('normaliza caixa, acento e espaços: $original')]
    public function testNormalizar(string $original, string $esperado): void
    {
        self::assertSame($esperado, NormalizadorDeNomeDoDrive::normalizar($original));
    }

    /** @return iterable<string, array{string, string}> */
    public static function clientes(): iterable
    {
        yield 'número com hífen' => ['001 - Maria Souza', 'maria souza'];
        yield 'número com ponto' => ['12A. MARIA SOUZA', 'maria souza'];
        yield 'número só com espaço' => ['7 Maria  Souza', 'maria souza'];
        yield 'sem número' => ['Márià Souza', 'maria souza'];
        yield 'só o número' => ['001', '001'];
        yield 'número no meio fica' => ['Maria 2 Souza', 'maria 2 souza'];
    }

    #[DataProvider('clientes')]
    #[TestDox('pelo cliente, ignora o número da frente: $original')]
    public function testNormalizarCliente(string $original, string $esperado): void
    {
        self::assertSame($esperado, NormalizadorDeNomeDoDrive::normalizarCliente($original));
    }
}
