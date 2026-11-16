<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\PoliticaDeCacheCimd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Validade do documento CIMD pelos cabeçalhos HTTP, com piso de 5 minutos e teto de 24 horas (spec 3.4). */
#[CoversClass(PoliticaDeCacheCimd::class)]
final class PoliticaDeCacheCimdTest extends TestCase
{
    private const AGORA = '2026-11-16 12:00:00';

    /** @return iterable<string, array{array<string, list<string>>, int}> */
    public static function casos(): iterable
    {
        yield 'max-age dentro da faixa' => [['cache-control' => ['public, max-age=3600']], 3600];
        yield 'max-age abaixo do piso' => [['cache-control' => ['max-age=10']], 300];
        yield 'max-age zero' => [['cache-control' => ['max-age=0']], 300];
        yield 'max-age acima do teto' => [['cache-control' => ['max-age=604800']], 86400];
        yield 'max-age entre aspas e em maiúsculas' => [['cache-control' => ['Max-Age="1200"']], 1200];
        yield 'no-store' => [['cache-control' => ['no-store, max-age=3600']], 300];
        yield 'no-cache' => [['cache-control' => ['max-age=3600', 'no-cache']], 300];
        yield 'sem cabeçalho' => [[], 300];
        yield 'Expires em 2 horas' => [['expires' => ['Mon, 16 Nov 2026 14:00:00 GMT']], 7200];
        yield 'Expires no passado' => [['expires' => ['Mon, 16 Nov 2026 11:00:00 GMT']], 300];
        yield 'Expires inválido' => [['expires' => ['0']], 300];
        yield 'Expires em uma semana' => [['expires' => ['Mon, 23 Nov 2026 12:00:00 GMT']], 86400];
        yield 'max-age manda sobre Expires' => [['cache-control' => ['max-age=600'], 'expires' => ['Mon, 16 Nov 2026 14:00:00 GMT']], 600];
        yield 'Cache-Control sem max-age cai no Expires' => [['cache-control' => ['public'], 'expires' => ['Mon, 16 Nov 2026 13:00:00 GMT']], 3600];
    }

    /** @param array<string, list<string>> $cabecalhos */
    #[DataProvider('casos')]
    #[TestDox('$_dataName')]
    public function testValidade(array $cabecalhos, int $segundos): void
    {
        $agora = new \DateTimeImmutable(self::AGORA, new \DateTimeZone('UTC'));

        $validoAte = (new PoliticaDeCacheCimd())->validoAte($cabecalhos, $agora);

        self::assertSame($segundos, $validoAte->getTimestamp() - $agora->getTimestamp());
    }
}
