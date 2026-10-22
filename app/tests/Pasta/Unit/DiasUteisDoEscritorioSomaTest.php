<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Pasta\Service\DiasUteisDoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Soma e contagem de dias úteis para o prazo do despacho da meta (spec `minhas-metas-esteira-2210.md`, C1 e C12):
 * a mesma regra do escritório (fim de semana, feriado nacional, da UF e recesso de 20/12 a 20/01).
 */
#[CoversClass(DiasUteisDoEscritorio::class)]
final class DiasUteisDoEscritorioSomaTest extends TestCase
{
    private DiasUteisDoEscritorio $dias;

    protected function setUp(): void
    {
        $this->dias = new DiasUteisDoEscritorio(new CalendarioDoSistema());
    }

    /** @return iterable<string, array{string, int, ?string, string}> */
    public static function somas(): iterable
    {
        yield '5 úteis de segunda pulam a Sexta-feira Santa' => ['2026-03-30', 5, null, '2026-04-07'];
        yield 'de sexta, 1 útil é a segunda' => ['2026-04-10', 1, null, '2026-04-13'];
        yield 'feriado de AL não conta em AL' => ['2026-09-15', 1, 'AL', '2026-09-17'];
        yield 'o mesmo feriado conta fora de AL' => ['2026-09-15', 1, 'SP', '2026-09-16'];
        yield 'recesso de 20/12 a 20/01 é pulado' => ['2026-12-18', 1, null, '2027-01-21'];
        yield 'zero dias devolve o próprio dia' => ['2026-04-04', 0, null, '2026-04-04'];
    }

    #[DataProvider('somas')]
    #[TestDox('somarUteis: $_dataName')]
    public function testSomarUteis(string $inicio, int $dias, ?string $uf, string $esperado): void
    {
        self::assertSame($esperado, $this->dias->somarUteis(new \DateTimeImmutable($inicio . ' 15:30'), $dias, $uf)->format('Y-m-d'));
    }

    #[TestDox('naoUteisEntre lista fim de semana, feriado da UF e Sexta-feira Santa, nos dois limites')]
    public function testNaoUteisEntre(): void
    {
        self::assertSame(
            ['2026-04-03', '2026-04-04', '2026-04-05'],
            $this->dias->naoUteisEntre(new \DateTimeImmutable('2026-04-02'), new \DateTimeImmutable('2026-04-06'), null),
        );
        self::assertSame(
            ['2026-09-16'],
            $this->dias->naoUteisEntre(new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-09-17'), 'AL'),
        );
    }
}
