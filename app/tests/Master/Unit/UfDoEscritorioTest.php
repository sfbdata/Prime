<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\UfDoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A UF do escritório para os convênios: o estado e, sem ele, a UF da OAB do criador (spec docs/specs/convenios.md, §5.1).
 */
#[CoversClass(UfDoEscritorio::class)]
final class UfDoEscritorioTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function casos(): iterable
    {
        yield 'estado válido vence a OAB' => ['rj', 'SP', 'RJ'];
        yield 'sem estado, a OAB' => [null, 'SP', 'SP'];
        yield 'estado vazio, a OAB' => ['  ', 'sp', 'SP'];
        yield 'estado que não é UF, a OAB' => ['Goiânia', 'GO', 'GO'];
        yield 'nenhum dos dois' => [null, null, null];
        yield 'OAB que não é UF' => [null, 'XX', null];
    }

    #[DataProvider('casos')]
    #[TestDox('estado $estado e OAB $ufDaOab: $esperada')]
    public function testDe(?string $estado, ?string $ufDaOab, ?string $esperada): void
    {
        self::assertSame($esperada, UfDoEscritorio::de($estado, $ufDaOab));
    }

    #[TestDox('linha do painel sem a coluna da OAB: só o estado')]
    public function testDaLinhaSemAColuna(): void
    {
        self::assertSame('GO', UfDoEscritorio::daLinha(['estado' => 'GO']));
        self::assertSame('SP', UfDoEscritorio::daLinha(['estado' => null, 'uf_oab' => 'SP']));
        self::assertNull(UfDoEscritorio::daLinha(['estado' => null]));
    }
}
