<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\NomeDoArquivoDaFolha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O nome da pessoa no arquivo da folha (P87; desenho 16, L726: `normalize('NFD').replace(/[^A-Za-z]/g, '').toUpperCase()`). */
#[CoversClass(NomeDoArquivoDaFolha::class)]
#[Group('ponto')]
final class NomeDoArquivoDaFolhaTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> nome, no PDF, no XLSX */
    public static function nomes(): iterable
    {
        yield 'acento vira a letra sem acento' => ['JOÃO DA SILVA', 'JOAODASILVA', 'JOAODASILVA'];
        yield 'cedilha e caixa baixa' => ['Conceição Araújo', 'CONCEICAOARAUJO', 'ConceicaoAraujo'];
        yield 'dígitos só no XLSX' => ['Ana 2', 'ANA', 'Ana2'];
        yield 'sem letra nenhuma: USUARIO no PDF (decisão 26)' => ['123', 'USUARIO', '123'];
        yield 'vazio' => ['', 'USUARIO', 'Usuario'];
    }

    #[DataProvider('nomes')]
    #[TestDox('$_dataName')]
    public function testNome(string $nome, string $doPdf, string $doXlsx): void
    {
        self::assertSame($doPdf, NomeDoArquivoDaFolha::doPdf($nome));
        self::assertSame($doXlsx, NomeDoArquivoDaFolha::doXlsx($nome));
    }
}
