<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Prazo;

use App\Shared\Prazo\RegraDoPrazo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegraDoPrazo::class)]
final class RegraDoPrazoTest extends TestCase
{
    /** @return iterable<string, array{int, string, string}> */
    public static function faixas(): iterable
    {
        yield 'vencido há vários dias' => [-3, 'Vencido há 3 dias', RegraDoPrazo::TOM_URGENTE];
        yield 'vencido há 1 dia' => [-1, 'Vencido há 1 dia', RegraDoPrazo::TOM_URGENTE];
        yield 'hoje' => [0, 'Hoje', RegraDoPrazo::TOM_URGENTE];
        yield 'falta 1 dia' => [1, 'Faltam 1 dia', RegraDoPrazo::TOM_URGENTE];
        yield 'até 3 dias é urgente' => [3, 'Faltam 3 dias', RegraDoPrazo::TOM_URGENTE];
        yield '4 dias já é próximo' => [4, 'Faltam 4 dias', RegraDoPrazo::TOM_PROXIMO];
        yield 'até 7 dias é próximo' => [7, 'Faltam 7 dias', RegraDoPrazo::TOM_PROXIMO];
        yield '8 dias é tranquilo' => [8, 'Faltam 8 dias', RegraDoPrazo::TOM_TRANQUILO];
    }

    #[DataProvider('faixas')]
    #[TestDox('$dias dia(s): "$selo", tom $tom')]
    public function testSeloETom(int $dias, string $selo, string $tom): void
    {
        self::assertSame($selo, RegraDoPrazo::selo($dias));
        self::assertSame($tom, RegraDoPrazo::tom($dias));
    }

    #[TestDox('A conta é por dia de calendário: a hora do prazo e a de agora não mudam o resultado')]
    public function testDiasAteIgnoraAHora(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-20 23:59:00');

        self::assertSame(0, RegraDoPrazo::diasAte(new \DateTimeImmutable('2026-10-20 00:00:00'), $hoje));
        self::assertSame(-1, RegraDoPrazo::diasAte(new \DateTimeImmutable('2026-10-19 23:59:00'), $hoje));
        self::assertSame(7, RegraDoPrazo::diasAte(new \DateTimeImmutable('2026-10-27 08:00:00'), $hoje));
    }

    #[TestDox('Janela "prazo próximo": do começo do dia até o começo do 8º dia (exclusivo)')]
    public function testJanelaDoPrazoProximo(): void
    {
        $agora = new \DateTimeImmutable('2026-10-20 15:30:00');

        self::assertSame('2026-10-20 00:00:00', RegraDoPrazo::inicioDoDia($agora)->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-28 00:00:00', RegraDoPrazo::fimDoPrazoProximo($agora)->format('Y-m-d H:i:s'));
    }
}
