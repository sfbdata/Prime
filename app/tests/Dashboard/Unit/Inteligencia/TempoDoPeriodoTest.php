<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\Inteligencia\TempoDoPeriodo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Porte de `tempo()`/`fase()` de bluejus-intelligence.js (L17-22, L58-63): dias do período,
 * posição de hoje e a fase, com os limiares do desenho nos casos de borda.
 */
#[CoversClass(TempoDoPeriodo::class)]
#[Group('dashboard')]
final class TempoDoPeriodoTest extends TestCase
{
    private function hoje(string $dia): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dia . ' 15:30:00', new \DateTimeZone('America/Sao_Paulo'));
    }

    #[TestDox('Março (31 dias) no dia 10: total 31, passados 10, restantes 21, fração 10/31')]
    public function testContaDiasDoPeriodo(): void
    {
        $t = TempoDoPeriodo::de('2024-03-01', '2024-03-31', $this->hoje('2024-03-10'));

        self::assertNotNull($t);
        self::assertSame(31, $t->total);
        self::assertSame(10, $t->passados);
        self::assertSame(21, $t->restantes);
        self::assertEqualsWithDelta(10 / 31, $t->fracao, 1e-9);
        self::assertFalse($t->encerrado);
        self::assertSame(TempoDoPeriodo::FASE_MEIO, $t->fase());
    }

    #[TestDox('A hora e o fuso de "hoje" não mudam a contagem: só o dia conta')]
    public function testSoODiaDeHojeConta(): void
    {
        $madrugada = new \DateTimeImmutable('2024-03-10 00:00:01', new \DateTimeZone('America/Sao_Paulo'));
        $noite     = new \DateTimeImmutable('2024-03-10 23:59:59', new \DateTimeZone('America/Sao_Paulo'));

        self::assertSame(10, TempoDoPeriodo::de('2024-03-01', '2024-03-31', $madrugada)?->passados);
        self::assertSame(10, TempoDoPeriodo::de('2024-03-01', '2024-03-31', $noite)?->passados);
    }

    /** @return iterable<string, array{string, string}> */
    public static function fases(): iterable
    {
        // fração < 0,2 é início: 6/31 = 0,194 ainda é; 7/31 = 0,226 já é meio (L52, L60)
        yield 'dia 6 de 31 → início'   => ['2024-03-06', TempoDoPeriodo::FASE_INICIO];
        yield 'dia 7 de 31 → meio'     => ['2024-03-07', TempoDoPeriodo::FASE_MEIO];
        // restantes ≤ 5 é reta final: dia 26 (restam 5) é; dia 25 (restam 6) não (L54, L61)
        yield 'dia 25 de 31 → meio'    => ['2024-03-25', TempoDoPeriodo::FASE_MEIO];
        yield 'dia 26 de 31 → final'   => ['2024-03-26', TempoDoPeriodo::FASE_FINAL];
        // último dia e depois dele: encerrado (passados ≥ total)
        yield 'dia 31 de 31 → encerrado' => ['2024-03-31', TempoDoPeriodo::FASE_ENCERRADO];
        yield 'depois do fim → encerrado' => ['2024-06-15', TempoDoPeriodo::FASE_ENCERRADO];
        // antes de começar: 0 dias passados, fração 0 → início
        yield 'antes do início → início' => ['2024-02-20', TempoDoPeriodo::FASE_INICIO];
    }

    #[DataProvider('fases')]
    #[TestDox('Fase do período em março/2024 quando hoje é $hoje: $esperada')]
    public function testFase(string $hoje, string $esperada): void
    {
        $t = TempoDoPeriodo::de('2024-03-01', '2024-03-31', $this->hoje($hoje));

        self::assertNotNull($t);
        self::assertSame($esperada, $t->fase());
    }

    #[TestDox('Antes do início: 0 passados, 31 restantes; depois do fim: 31 passados, 0 restantes')]
    public function testForaDoPeriodo(): void
    {
        $antes = TempoDoPeriodo::de('2024-03-01', '2024-03-31', $this->hoje('2024-02-20'));
        self::assertSame(0, $antes?->passados);
        self::assertSame(31, $antes?->restantes);
        self::assertFalse($antes?->encerrado);

        $depois = TempoDoPeriodo::de('2024-03-01', '2024-03-31', $this->hoje('2024-06-15'));
        self::assertSame(31, $depois?->passados);
        self::assertSame(0, $depois?->restantes);
        self::assertTrue($depois?->encerrado);
    }

    #[TestDox('Período de um único dia: total 1, e no próprio dia já está encerrado')]
    public function testPeriodoDeUmDia(): void
    {
        $t = TempoDoPeriodo::de('2024-03-10', '2024-03-10', $this->hoje('2024-03-10'));

        self::assertSame(1, $t?->total);
        self::assertSame(1, $t?->passados);
        self::assertTrue($t?->encerrado);
    }

    /** @return iterable<string, array{string, string}> */
    public static function periodosInvalidos(): iterable
    {
        yield 'sem data_de'       => ['', '2024-03-31'];
        yield 'sem data_ate'      => ['2024-03-01', ''];
        yield 'data inválida'     => ['2024-13-01', '2024-03-31'];
        yield 'formato errado'    => ['01/03/2024', '31/03/2024'];
        yield 'fim antes do início' => ['2024-03-31', '2024-03-01'];
    }

    #[DataProvider('periodosInvalidos')]
    #[TestDox('Sem período completo e válido não há tempo a contar (null)')]
    public function testSemPeriodoCompletoDevolveNull(string $de, string $ate): void
    {
        self::assertNull(TempoDoPeriodo::de($de, $ate, $this->hoje('2024-03-10')));
    }
}
