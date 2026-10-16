<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\MapaDeAtuacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MapaDeAtuacao::class)]
final class MapaDeAtuacaoTest extends TestCase
{
    #[TestDox('Pessoas contam uma vez; "Só primários" filtra; tendência compara entradas de 30 e 60 dias; escritórios sem estado vão à parte')]
    public function testAgrega(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-16 12:00:00');
        $pessoa = static fn (string $criado, bool $prim, ?string $oabUf, array $vinculos): array => [
            'criadoEm' => $criado, 'primario' => $prim, 'oabUf' => $oabUf, 'vinculos' => $vinculos,
        ];
        $v = static fn (int $t, string $lot, bool $prim): array => ['tenantId' => $t, 'lotacao' => $lot, 'primario' => $prim, 'ultimoAcesso' => ''];

        $mapa = MapaDeAtuacao::montar([
            // dois vínculos, conta uma vez só, na lotação
            $pessoa('2026-10-10 10:00:00', true, 'DF', [$v(1, 'Maceió/AL', true), $v(2, '', true)]),
            $pessoa('2026-09-01 10:00:00', false, 'AL', [$v(1, '', false)]),
            $pessoa('2025-01-01 10:00:00', false, null, [$v(2, '', false)]),
        ], [
            ['id' => 1, 'cidade' => 'Brasília', 'estado' => 'DF'],
            ['id' => 2, 'cidade' => null, 'estado' => null],
        ], $hoje);

        $geral = $mapa['pessoas']['geral'];
        self::assertSame(['AL' => 2], $geral['A']);
        self::assertSame(['AL' => ['Maceió' => 1]], $geral['C']);
        self::assertSame(['AL' => ['ag' => 1, 'an' => 1]], $geral['tend']);
        self::assertSame(1, $geral['semLoc']);
        self::assertSame(3, $geral['total']);

        self::assertSame(['AL' => 1], $mapa['pessoas']['prim']['A']);
        self::assertSame(1, $mapa['pessoas']['prim']['total']);

        self::assertSame(['DF' => 1], $mapa['escritorios']['A']);
        self::assertSame(['DF' => ['Brasília' => 1]], $mapa['escritorios']['C']);
        self::assertSame(1, $mapa['escritorios']['semEnd']);
    }
}
