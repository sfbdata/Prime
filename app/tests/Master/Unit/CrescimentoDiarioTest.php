<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\CrescimentoDiario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Séries por dia do gráfico Crescimento (desenho 05 - Perfis 1.2, VS 03.11.26): visão "Diário" e "Acessos ao sistema".
 */
#[CoversClass(CrescimentoDiario::class)]
final class CrescimentoDiarioTest extends TestCase
{
    private const GB = 1073741824;

    #[TestDox('sem foto, o acumulado do dia é reconstruído pela data de criação; o futuro fica nulo')]
    public function testReconstroiPelaDataDeCriacao(): void
    {
        $series = CrescimentoDiario::metricas(
            ['contas' => ['2026-01-01' => 2.0, '2026-01-03' => 1.0], 'usuarios' => [], 'pastas' => [], 'bytes' => ['2026-01-02' => (float) self::GB]],
            [],
            new \DateTimeImmutable('2026-01-03 10:00:00'),
        );

        self::assertSame([2026], array_keys($series));
        self::assertCount(365, $series[2026]['contas']);
        self::assertSame([2.0, 2.0, 3.0, null], array_slice($series[2026]['contas'], 0, 4));
        self::assertSame([0.0, 1.0, 1.0, null], array_slice($series[2026]['gb'], 0, 4));
        self::assertSame([0.0, 0.0, 0.0, null], array_slice($series[2026]['usuarios'], 0, 4));
    }

    #[TestDox('o dia com foto usa a foto; o acumulado segue da reconstrução nos dias sem foto')]
    public function testFotoTemPrecedencia(): void
    {
        $series = CrescimentoDiario::metricas(
            ['contas' => ['2026-01-01' => 5.0], 'usuarios' => [], 'pastas' => [], 'bytes' => []],
            ['contas' => ['2026-01-02' => 4.0], 'bytes' => ['2026-01-02' => 2.0 * self::GB]],
            new \DateTimeImmutable('2026-01-03'),
        );

        self::assertSame([5.0, 4.0, 5.0], array_slice($series[2026]['contas'], 0, 3));
        self::assertSame([0.0, 2.0, 0.0], array_slice($series[2026]['gb'], 0, 3));
    }

    #[TestDox('o acumulado atravessa a virada do ano; ano bissexto tem 366 dias')]
    public function testAtravessaOAno(): void
    {
        $series = CrescimentoDiario::metricas(
            ['contas' => ['2024-12-31' => 3.0], 'usuarios' => [], 'pastas' => [], 'bytes' => []],
            [],
            new \DateTimeImmutable('2025-01-01'),
        );

        self::assertSame([2024, 2025], array_keys($series));
        self::assertCount(366, $series[2024]['contas']);
        self::assertSame(3.0, $series[2024]['contas'][365]);
        self::assertSame([3.0, null], array_slice($series[2025]['contas'], 0, 2));
    }

    #[TestDox('acessos: nulo antes do primeiro registro e no futuro, zero no dia sem acesso')]
    public function testAcessos(): void
    {
        $acessos = CrescimentoDiario::acessos(['2026-11-02' => 3, '2026-11-04' => 1], new \DateTimeImmutable('2026-11-05'));

        self::assertSame([2026], array_keys($acessos));
        self::assertCount(365, $acessos[2026]);
        // 1º de novembro é o dia 305 do ano (índice 304).
        self::assertSame([null, 3, 0, 1, 0, null], array_slice($acessos[2026], 304, 6));
    }

    #[TestDox('sem nenhum acesso gravado, não há linha de acessos')]
    public function testSemAcessos(): void
    {
        self::assertSame([], CrescimentoDiario::acessos([], new \DateTimeImmutable('2026-11-04')));
    }
}
