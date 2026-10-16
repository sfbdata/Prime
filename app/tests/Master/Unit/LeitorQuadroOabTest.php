<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Fontes\FonteIndisponivel;
use App\Master\Fontes\LeitorQuadroOab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorQuadroOab::class)]
final class LeitorQuadroOabTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/oab-quadro-advogados-2026-10-16.html';

    #[TestDox('Lê as 3 tabelas da página real da OAB (retrato de 16/10/2026)')]
    public function testLePaginaReal(): void
    {
        $lido = (new LeitorQuadroOab())->ler((string) file_get_contents(self::FIXTURE));

        self::assertCount(27, $lido['uf']);
        self::assertSame(1512836, $lido['totalAdvogados']);
        self::assertSame(396761, $lido['uf']['SP'][0]);
        self::assertSame(4250, $lido['uf']['AC'][0]);
        self::assertSame(1512836, array_sum(array_map(static fn (array $v): int => (int) $v[0], $lido['uf'])));

        // AC: estagiários, suplementares, consultores (quantitativo)
        self::assertSame([4250, 41, 597, 0], array_slice($lido['uf']['AC'], 0, 4));
        // AC: advogadas e advogados (gênero)
        self::assertSame([2046, 2204], array_slice($lido['uf']['AC'], 4, 2));
        // AC: até 25 anos F/M e 26 a 40 F (faixa etária)
        self::assertSame([118, 72, 1025], array_slice($lido['uf']['AC'], 6, 3));

        foreach ($lido['uf'] as $uf => $valores) {
            self::assertNotContains(null, $valores, 'todas as posições de ' . $uf . ' foram lidas');
        }
    }

    #[TestDox('Página sem o quadro (layout mudou, página de erro) falha em vez de gravar dado parcial')]
    public function testPaginaSemQuadroFalha(): void
    {
        $this->expectException(FonteIndisponivel::class);

        (new LeitorQuadroOab())->ler('<html><body><p>Manutenção</p></body></html>');
    }
}
