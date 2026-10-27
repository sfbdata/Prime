<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\Inteligencia\SimulacaoDeDia;
use App\Dashboard\Inteligencia\TempoDoPeriodo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** "Simular dia": só vale dentro do período, até o fim do mês corrente, e nunca no dia real. */
#[CoversClass(SimulacaoDeDia::class)]
#[Group('dashboard')]
final class SimulacaoDeDiaTest extends TestCase
{
    /** Hoje = 15/10/2026, período outubro inteiro (31 dias): dia real 15. */
    private function tempo(string $de = '2026-10-01', string $ate = '2026-10-31'): ?TempoDoPeriodo
    {
        return TempoDoPeriodo::de($de, $ate, new \DateTimeImmutable('2026-10-15 14:00'));
    }

    #[TestDox('Sem período completo não há controle')]
    public function testSemPeriodoNaoHaControle(): void
    {
        self::assertNull(SimulacaoDeDia::de('2026-10-10', null));
    }

    #[TestDox('Sem parâmetro: não simula, controle no dia real e vai até o último dia do mês')]
    public function testSemParametro(): void
    {
        $s = SimulacaoDeDia::de(null, $this->tempo());

        self::assertNotNull($s);
        self::assertFalse($s->ativa());
        self::assertSame(15, $s->numero);
        self::assertSame(15, $s->diaReal);
        self::assertSame(31, $s->maximo);
        self::assertSame('2026-10-01', $s->inicio);
    }

    #[TestDox('Dia válido do período simula: a posição vira o valor do controle e o hoje da leitura')]
    public function testDiaValido(): void
    {
        $s = SimulacaoDeDia::de('2026-10-03', $this->tempo());

        self::assertNotNull($s);
        self::assertTrue($s->ativa());
        self::assertSame(3, $s->numero);
        self::assertSame('2026-10-03', $s->hoje(new \DateTimeImmutable('2026-10-15'))->format('Y-m-d'));
        self::assertSame(31, SimulacaoDeDia::de('2026-10-31', $this->tempo())?->numero);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidos(): iterable
    {
        yield 'lixo'                  => ['abc'];
        yield 'data impossível'       => ['2026-02-31'];
        yield 'antes do período'      => ['2026-09-30'];
        yield 'depois do período'     => ['2026-11-01'];
        yield 'igual ao dia real'     => ['2026-10-15'];
        yield 'formato com hora'      => ['2026-10-03 10:00'];
    }

    #[DataProvider('invalidos')]
    #[TestDox('Valor inválido ou fora da faixa não simula: "$valor"')]
    public function testInvalidosNaoSimulam(string $valor): void
    {
        $s = SimulacaoDeDia::de($valor, $this->tempo());

        self::assertNotNull($s);
        self::assertFalse($s->ativa());
        self::assertSame($s->diaReal, $s->numero);
    }

    #[TestDox('Período que passa do mês corrente: nunca simula além do fim do mês')]
    public function testNuncaAlemDoFimDoMes(): void
    {
        $tempo = $this->tempo('2026-10-01', '2026-12-31');

        self::assertSame(31, SimulacaoDeDia::de(null, $tempo)?->maximo);
        self::assertFalse(SimulacaoDeDia::de('2026-11-02', $tempo)?->ativa());
        self::assertTrue(SimulacaoDeDia::de('2026-10-31', $tempo)?->ativa());
    }
}
