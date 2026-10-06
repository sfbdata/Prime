<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\PastaDocumento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `sha256` só aceita o formato de `hash('sha256', …)`: um hash "quase certo" nunca casaria com
 * outro e viraria "sem duplicado" em silêncio — por isso é recusado, não corrigido.
 */
#[CoversClass(PastaDocumento::class)]
final class PastaDocumentoSha256Test extends TestCase
{
    #[TestDox('nasce NULL (ainda não calculado) e aceita um hash hex minúsculo de 64 caracteres')]
    public function testAceitaHashValidoENull(): void
    {
        $doc = new PastaDocumento();
        self::assertNull($doc->getSha256());

        $hash = hash('sha256', 'conteúdo');
        $doc->setSha256($hash);
        self::assertSame($hash, $doc->getSha256());

        $doc->setSha256(null);
        self::assertNull($doc->getSha256());
    }

    /** @return iterable<string, array{string}> */
    public static function hashesInvalidos(): iterable
    {
        yield 'maiúsculo'      => [strtoupper(hash('sha256', 'x'))];
        yield 'truncado'       => [substr(hash('sha256', 'x'), 0, 63)];
        yield 'com espaço'     => [' ' . substr(hash('sha256', 'x'), 1)];
        yield 'md5'            => [md5('x')];
        yield 'vazio'          => [''];
    }

    #[TestDox('recusa hash fora do formato: $_dataName')]
    #[DataProvider('hashesInvalidos')]
    public function testRecusaHashInvalido(string $invalido): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PastaDocumento())->setSha256($invalido);
    }
}
