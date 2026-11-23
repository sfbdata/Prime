<?php

declare(strict_types=1);

namespace App\Tests\Backup\Unit;

use App\Backup\DTO\ResultadoDoBackup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResultadoDoBackup::class)]
final class ResultadoDoBackupTest extends TestCase
{
    private const SHA = 'a3f1c2d4e5b60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[TestDox('resultado válido guarda os valores')]
    public function testValido(): void
    {
        $r = new ResultadoDoBackup('abc.zip', 1024, self::SHA, 3, 1);

        self::assertSame('abc.zip', $r->chaveArquivo);
        self::assertSame(1024, $r->tamanhoBytes);
        self::assertSame(self::SHA, $r->sha256);
        self::assertSame(3, $r->arquivosIncluidos);
        self::assertSame(1, $r->arquivosFora);
    }

    #[TestDox('zero é aceito em tamanho e contagens')]
    public function testZeroEhAceito(): void
    {
        $r = new ResultadoDoBackup('abc.zip', 0, self::SHA, 0, 0);

        self::assertSame(0, $r->tamanhoBytes);
    }

    /** @return iterable<string, array{string, int, string, int, int}> */
    public static function invalidos(): iterable
    {
        yield 'chave vazia' => ['', 10, self::SHA, 0, 0];
        yield 'sha256 curto' => ['a.zip', 10, 'abc123', 0, 0];
        yield 'sha256 longo' => ['a.zip', 10, self::SHA . '0', 0, 0];
        yield 'sha256 com maiúscula' => ['a.zip', 10, strtoupper(self::SHA), 0, 0];
        yield 'sha256 não hexadecimal' => ['a.zip', 10, str_repeat('g', 64), 0, 0];
        yield 'sha256 vazio' => ['a.zip', 10, '', 0, 0];
        yield 'tamanho negativo' => ['a.zip', -1, self::SHA, 0, 0];
        yield 'incluídos negativo' => ['a.zip', 10, self::SHA, -1, 0];
        yield 'fora negativo' => ['a.zip', 10, self::SHA, 0, -1];
    }

    #[DataProvider('invalidos')]
    #[TestDox('$_dataName é recusado')]
    public function testInvalidoLanca(string $chave, int $tamanho, string $sha, int $incluidos, int $fora): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ResultadoDoBackup($chave, $tamanho, $sha, $incluidos, $fora);
    }
}
