<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Entity\SaldoDoEscritorio;
use App\ServiceDesk\Exception\SaldoInsuficiente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaldoDoEscritorio::class)]
final class SaldoDoEscritorioTest extends TestCase
{
    public function testNasceZeradoESemPermitirAbrirChamado(): void
    {
        $saldo = new SaldoDoEscritorio(new Tenant());

        self::assertSame(0, $saldo->getSaldoCentavos());
        self::assertFalse($saldo->permiteAbrirChamado());
    }

    #[DataProvider('limitesDoMinimo')]
    public function testMinimoDeVinteReaisParaAbrirChamado(int $centavos, bool $permite): void
    {
        $saldo = new SaldoDoEscritorio(new Tenant());
        $saldo->creditar($centavos);

        self::assertSame($permite, $saldo->permiteAbrirChamado());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function limitesDoMinimo(): iterable
    {
        yield 'R$ 19,99' => [1_999, false];
        yield 'R$ 20,00' => [2_000, true];
        yield 'R$ 20,01' => [2_001, true];
    }

    public function testDebitaEDevolve(): void
    {
        $saldo = new SaldoDoEscritorio(new Tenant());
        $saldo->creditar(10_000);
        $saldo->debitar(2_500);

        self::assertSame(7_500, $saldo->getSaldoCentavos());
    }

    public function testDebitoMaiorQueOSaldoNaoDeixaNegativo(): void
    {
        $saldo = new SaldoDoEscritorio(new Tenant());
        $saldo->creditar(1_000);

        $this->expectException(SaldoInsuficiente::class);
        try {
            $saldo->debitar(1_001);
        } finally {
            self::assertSame(1_000, $saldo->getSaldoCentavos());
        }
    }

    #[DataProvider('valoresInvalidos')]
    public function testRecusaValorZeroOuNegativo(int $valor): void
    {
        $saldo = new SaldoDoEscritorio(new Tenant());

        $this->expectException(\DomainException::class);
        $saldo->creditar($valor);
    }

    /** @return iterable<string, array{int}> */
    public static function valoresInvalidos(): iterable
    {
        yield 'zero' => [0];
        yield 'negativo' => [-1];
    }
}
