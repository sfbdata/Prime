<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Ranking\HistoricoDeCampeoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** "1º do mês": o campeão de cada mês fechado, pela regra do troféu. */
#[CoversClass(HistoricoDeCampeoes::class)]
#[Group('dashboard')]
final class HistoricoDeCampeoesTest extends TestCase
{
    /** @return array{ano: int, mes: int, userId: int, total: int, ativas: int} */
    private static function linha(int $ano, int $mes, int $userId, int $total, int $ativas = 0): array
    {
        return ['ano' => $ano, 'mes' => $mes, 'userId' => $userId, 'total' => $total, 'ativas' => $ativas];
    }

    #[TestDox('Ganha o mês quem concluiu mais (total − ativas); o mês corrente ainda não fechou')]
    public function testMesesGanhos(): void
    {
        $linhas = [
            self::linha(2026, 3, 7, 5, 1), self::linha(2026, 3, 8, 6, 4), // Ana 4 × Bruno 2
            self::linha(2026, 5, 7, 1), self::linha(2026, 5, 8, 3),       // Bruno
            self::linha(2026, 1, 7, 2),                                   // Ana sozinha
            self::linha(2026, 8, 7, 9),                                   // agosto: mês corrente
        ];

        $h = HistoricoDeCampeoes::doColaborador(7, $linhas, [7, 8], [], new \DateTimeImmutable('2026-08-14'));

        self::assertSame([1, 3], $h['ganhos'][2026]);
        self::assertSame(7, $h['ultimoMesFechado']);
        self::assertSame([2026], $h['anos']);
    }

    #[TestDox('Desempate por menos vencidas; empate total, ninguém; quem não está na equipe não disputa')]
    public function testDesempateEUniverso(): void
    {
        $linhas = [
            self::linha(2025, 2, 7, 3), self::linha(2025, 2, 8, 3),
            self::linha(2025, 4, 7, 2), self::linha(2025, 4, 99, 9),
        ];

        $comVencida = HistoricoDeCampeoes::doColaborador(7, $linhas, [7, 8], [8 => 1], new \DateTimeImmutable('2026-01-10'));
        self::assertSame([2, 4], $comVencida['ganhos'][2025]);

        $empate = HistoricoDeCampeoes::doColaborador(7, $linhas, [7, 8], [], new \DateTimeImmutable('2026-01-10'));
        self::assertSame([4], $empate['ganhos'][2025]);
    }

    #[TestDox('Anos: do corrente até o primeiro com meta, do mais novo para o mais antigo; sem meta, só o corrente')]
    public function testAnos(): void
    {
        $h = HistoricoDeCampeoes::doColaborador(7, [self::linha(2024, 6, 8, 1)], [7, 8], [], new \DateTimeImmutable('2026-01-10'));
        self::assertSame([2026, 2025, 2024], $h['anos']);
        self::assertSame([], $h['ganhos']);
        self::assertSame(0, $h['ultimoMesFechado'], 'em janeiro nenhum mês do ano fechou');

        self::assertSame([2026], HistoricoDeCampeoes::doColaborador(7, [], [7], [], new \DateTimeImmutable('2026-01-10'))['anos']);
    }
}
