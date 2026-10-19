<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\DiasUteisNacionais;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Dias úteis forenses nacionais: porte do `diaUtil`/`somaUteis`/`uteisAte` do desenho 1.2.3
 * (dc L.5089-5091), com os feriados do `CalendarioDoSistema` (mais a segunda de Carnaval do
 * `FERIADOS_NAC`, dc L.5079).
 */
#[CoversClass(DiasUteisNacionais::class)]
final class DiasUteisNacionaisTest extends TestCase
{
    private DiasUteisNacionais $dias;

    protected function setUp(): void
    {
        $this->dias = new DiasUteisNacionais(new CalendarioDoSistema());
    }

    private static function d(string $data): \DateTimeImmutable
    {
        return new \DateTimeImmutable($data);
    }

    /** @return iterable<string, array{string}> */
    public static function naoUteis(): iterable
    {
        yield 'sábado' => ['2026-10-03'];
        yield 'domingo' => ['2026-10-04'];
        yield 'N. Sra. Aparecida (fixo)' => ['2026-10-12'];
        yield 'Finados' => ['2026-11-02'];
        yield 'Consciência Negra' => ['2026-11-20'];
        yield 'segunda de Carnaval 2026 (do FERIADOS_NAC)' => ['2026-02-16'];
        yield 'terça de Carnaval 2026' => ['2026-02-17'];
        yield 'Sexta-feira Santa 2026' => ['2026-04-03'];
        yield 'Corpus Christi 2026' => ['2026-06-04'];
        yield 'segunda de Carnaval 2027' => ['2027-02-08'];
        yield 'Sexta-feira Santa 2027 (móvel de outro ano)' => ['2027-03-26'];
        yield 'Corpus Christi 2027' => ['2027-05-27'];
        yield 'começo do recesso (20/12)' => ['2026-12-21'];
        yield 'recesso na virada do ano' => ['2027-01-04'];
        yield 'fim do recesso (20/01)' => ['2027-01-20'];
    }

    #[DataProvider('naoUteis')]
    #[TestDox('Não é dia útil: $data')]
    public function testNaoEhDiaUtil(string $data): void
    {
        self::assertFalse($this->dias->ehDiaUtil(self::d($data)));
    }

    /** @return iterable<string, array{string}> */
    public static function uteis(): iterable
    {
        yield 'segunda comum' => ['2026-10-19'];
        yield 'véspera do recesso (19/12 é sábado; 18/12 sexta)' => ['2026-12-18'];
        yield 'primeiro dia depois do recesso' => ['2027-01-21'];
        yield 'quarta de Cinzas (não é feriado nacional)' => ['2026-02-18'];
    }

    #[DataProvider('uteis')]
    #[TestDox('É dia útil: $data')]
    public function testEhDiaUtil(string $data): void
    {
        self::assertTrue($this->dias->ehDiaUtil(self::d($data)));
    }

    #[TestDox('Exemplo do desenho: publicação em 02/10/2026 + 15 dias úteis termina em 26/10/2026 (pula o 12/10)')]
    public function testSomaDoExemploDoDesenho(): void
    {
        self::assertSame('2026-10-26', $this->dias->somarUteis(self::d('2026-10-02'), 15)->format('Y-m-d'));
    }

    #[TestDox('O dia da publicação não conta: 1 dia útil é o primeiro útil seguinte')]
    public function testPrimeiroDiaEhOUtilSeguinte(): void
    {
        self::assertSame('2026-10-05', $this->dias->somarUteis(self::d('2026-10-02'), 1)->format('Y-m-d'));
        self::assertSame('2026-10-13', $this->dias->somarUteis(self::d('2026-10-09'), 1)->format('Y-m-d'), 'sexta antes do feriado de segunda');
    }

    #[TestDox('Virada de ano: prazo publicado antes do recesso retoma em 21/01')]
    public function testPrazoAtravessaORecesso(): void
    {
        // 18/12/2026 (sexta): 21/01 = 1, 22 = 2, 25 = 3, 26 = 4, 27 = 5.
        self::assertSame('2027-01-27', $this->dias->somarUteis(self::d('2026-12-18'), 5)->format('Y-m-d'));
    }

    #[TestDox('Faltam 5 dias úteis de 19/10/2026 a 26/10/2026; 0 no próprio dia; -1 depois do fim')]
    public function testUteisAte(): void
    {
        self::assertSame(5, $this->dias->uteisAte(self::d('2026-10-26'), self::d('2026-10-19 15:30')));
        self::assertSame(0, $this->dias->uteisAte(self::d('2026-10-26'), self::d('2026-10-26 08:00')));
        self::assertSame(-1, $this->dias->uteisAte(self::d('2026-10-26'), self::d('2026-10-27')));
    }
}
