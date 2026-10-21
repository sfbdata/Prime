<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Formato\FormatoDoIdioma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormatoDoIdioma::class)]
final class FormatoDoIdiomaTest extends TestCase
{
    #[TestDox('em português a saída é idêntica aos formatos fixos de hoje (d/m/Y, d/m/Y H:i, H:i, número com , e .)')]
    public function testPortuguesIgualAoDeHoje(): void
    {
        $formato = new FormatoDoIdioma();
        $momento = new \DateTimeImmutable('2026-03-05 09:07:00', new \DateTimeZone('America/Sao_Paulo'));

        self::assertSame($momento->format('d/m/Y'), $formato->data($momento, 'pt_BR'));
        self::assertSame($momento->format('d/m/Y H:i'), $formato->dataHora($momento, 'pt_BR'));
        self::assertSame($momento->format('H:i'), $formato->hora($momento, 'pt_BR'));
        self::assertSame(number_format(1234567.891, 2, ',', '.'), $formato->numero(1234567.891, 'pt_BR', 2));
        self::assertSame(number_format(1234, 0, ',', '.'), $formato->numero(1234, 'pt_BR'));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function outrosIdiomas(): iterable
    {
        yield 'inglês' => ['en', '03/05/2026', '9:07 AM', '1,234.50'];
        yield 'alemão' => ['de', '05.03.2026', '09:07', '1.234,50'];
        yield 'japonês' => ['ja', '2026/03/05', '9:07', '1,234.50'];
    }

    #[DataProvider('outrosIdiomas')]
    #[TestDox('cada idioma escreve no formato dele: $locale')]
    public function testOutrosIdiomas(string $locale, string $data, string $hora, string $numero): void
    {
        $formato = new FormatoDoIdioma();
        $momento = new \DateTimeImmutable('2026-03-05 09:07:00', new \DateTimeZone('America/Sao_Paulo'));

        self::assertSame($data, $formato->data($momento, $locale));
        self::assertSame($hora, str_replace("\u{202F}", ' ', $formato->hora($momento, $locale)), 'o ICU novo separa o AM com espaço fino');
        self::assertSame($numero, $formato->numero(1234.5, $locale, 2));
    }

    #[TestDox('o momento é escrito no fuso dele')]
    public function testFusoDoMomento(): void
    {
        $utc = new \DateTimeImmutable('2026-03-05 23:30:00', new \DateTimeZone('UTC'));

        self::assertSame('05/03/2026 23:30', (new FormatoDoIdioma())->dataHora($utc, 'pt_BR'));
        self::assertSame('05/03/2026 20:30', (new FormatoDoIdioma())->dataHora($utc->setTimezone(new \DateTimeZone('America/Sao_Paulo')), 'pt_BR'));
    }
}
