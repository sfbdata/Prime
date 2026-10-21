<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\CalendarioDaCampanha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As datas da recorrente (spec §8.4, A9): +7, +14, e o mensal ancorado no dia da primeira execução. */
#[CoversClass(CalendarioDaCampanha::class)]
final class CalendarioDaCampanhaTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function seguintes(): iterable
    {
        yield 'semanal' => ['semanal', '2027-01-31 09:00', '2027-01-31 09:00', '2027-02-07 09:00'];
        yield 'quinzenal' => ['quinzenal', '2027-01-31 09:00', '2027-01-31 09:00', '2027-02-14 09:00'];
        yield '31/01 para 28/02' => ['mensal', '2027-01-31 09:00', '2027-01-31 09:00', '2027-02-28 09:00'];
        yield '28/02 volta a 31/03' => ['mensal', '2027-01-31 09:00', '2027-02-28 09:00', '2027-03-31 09:00'];
        yield '31/03 para 30/04' => ['mensal', '2027-01-31 09:00', '2027-03-31 09:00', '2027-04-30 09:00'];
        yield 'ano bissexto: 29/02' => ['mensal', '2028-01-31 18:30', '2028-01-31 18:30', '2028-02-29 18:30'];
        yield 'dezembro para janeiro' => ['mensal', '2027-12-15 08:00', '2027-12-15 08:00', '2028-01-15 08:00'];
    }

    #[DataProvider('seguintes')]
    #[TestDox('A execução seguinte: $frequencia, âncora $ancora, atual $atual, seguinte $esperada')]
    public function testSeguinte(string $frequencia, string $ancora, string $atual, string $esperada): void
    {
        $seguinte = CalendarioDaCampanha::seguinte($frequencia, new \DateTimeImmutable($ancora), new \DateTimeImmutable($atual));

        self::assertSame($esperada, $seguinte?->format('Y-m-d H:i'));
    }

    #[TestDox('A única não tem seguinte')]
    public function testUnica(): void
    {
        self::assertNull(CalendarioDaCampanha::seguinte('unica', new \DateTimeImmutable(), new \DateTimeImmutable()));
    }

    #[TestDox('Nada é recuperado: a primeira data depois de agora pula as que passaram, sem perder a âncora')]
    public function testPrimeiraDepois(): void
    {
        $ancora = new \DateTimeImmutable('2027-01-31 09:00');

        $mensal = CalendarioDaCampanha::primeiraDepois('mensal', $ancora, $ancora, new \DateTimeImmutable('2027-05-10 12:00'));
        $semanal = CalendarioDaCampanha::primeiraDepois('semanal', $ancora, $ancora, new \DateTimeImmutable('2027-02-15 12:00'));

        self::assertSame('2027-05-31 09:00', $mensal?->format('Y-m-d H:i'));
        self::assertSame('2027-02-21 09:00', $semanal?->format('Y-m-d H:i'));
    }
}
