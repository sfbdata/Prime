<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\CalendarioDasDatasComemorativas as Calendario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As 17 datas do desenho 05 VS 21.10.26 (dc L614-618; spec comercial-e-emails.md §13, F4). */
#[CoversClass(Calendario::class)]
final class CalendarioDasDatasComemorativasTest extends TestCase
{
    #[TestDox('As 17 datas na ordem do dc, com o nome e o ícone literais')]
    public function testAsDezessete(): void
    {
        self::assertSame(
            ['ano', 'carnaval', 'mulher', 'pascoa', 'tiradentes', 'trabalho', 'maes', 'namorados', 'pais', 'advogado', 'independencia', 'cliente', 'criancas', 'finados', 'consciencia', 'natal', 'reveillon'],
            Calendario::chaves(),
        );
        self::assertSame(['nome' => 'Dia do Advogado', 'icone' => 'bi-bank'], array_intersect_key(Calendario::data('advogado'), ['nome' => 1, 'icone' => 1]));
        self::assertSame('Réveillon', Calendario::data('reveillon')['nome']);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function datasNoAno(): iterable
    {
        yield 'Páscoa 2026' => ['pascoa', 2026, '2026-04-05'];
        yield 'Carnaval 2026 (Páscoa menos 47)' => ['carnaval', 2026, '2026-02-17'];
        yield 'Páscoa 2027' => ['pascoa', 2027, '2027-03-28'];
        yield 'Carnaval 2027' => ['carnaval', 2027, '2027-02-09'];
        yield 'Mães 2026 (2º domingo de maio)' => ['maes', 2026, '2026-05-10'];
        yield 'Pais 2026 (2º domingo de agosto)' => ['pais', 2026, '2026-08-09'];
        yield 'Mães 2027' => ['maes', 2027, '2027-05-09'];
        yield 'Pais 2027 (o 1º de agosto já é domingo)' => ['pais', 2027, '2027-08-08'];
        yield 'Natal' => ['natal', 2026, '2026-12-25'];
        yield 'Ano Novo' => ['ano', 2027, '2027-01-01'];
    }

    #[TestDox('$chave em $ano: $esperado')]
    #[DataProvider('datasNoAno')]
    public function testDataNoAno(string $chave, int $ano, string $esperado): void
    {
        self::assertSame($esperado, Calendario::noAno($chave, $ano)->format('Y-m-d'));
    }

    #[TestDox('A próxima ocorrência conta hoje e, passada a data, vai para o ano seguinte')]
    public function testProxima(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-21 15:00');

        self::assertSame('2026-11-02', Calendario::proxima('finados', $hoje)->format('Y-m-d'));
        self::assertSame('2027-10-12', Calendario::proxima('criancas', $hoje)->format('Y-m-d'));
        self::assertSame('2027-03-28', Calendario::proxima('pascoa', $hoje)->format('Y-m-d'));
        self::assertSame('2026-12-25', Calendario::proxima('natal', new \DateTimeImmutable('2026-12-25 23:00'))->format('Y-m-d'), 'no dia ainda é "hoje"');
    }

    #[TestDox('O dia de envio com a antecedência: o Ano Novo 1 dia antes sai em 31/12 e é do ano seguinte; no dia seguinte, nada')]
    public function testDevidaEm(): void
    {
        self::assertSame('2027-01-01', Calendario::devidaEm('ano', 1, new \DateTimeImmutable('2026-12-31 09:00'))?->format('Y-m-d'));
        self::assertSame('2026-12-25', Calendario::devidaEm('natal', 3, new \DateTimeImmutable('2026-12-22 10:00'))?->format('Y-m-d'));
        self::assertSame('2026-12-25', Calendario::devidaEm('natal', 0, new \DateTimeImmutable('2026-12-25 10:00'))?->format('Y-m-d'));
        self::assertNull(Calendario::devidaEm('natal', 0, new \DateTimeImmutable('2026-12-26 10:00')));
        self::assertNull(Calendario::devidaEm('ano', 1, new \DateTimeImmutable('2027-01-01 10:00')));
        self::assertSame('2026-12-31', Calendario::envio(Calendario::noAno('ano', 2027), 1)->format('Y-m-d'));
    }

    #[TestDox('O dia conta no fuso de São Paulo: 01:00 UTC de 25/12 ainda é 24/12 local')]
    public function testFuso(): void
    {
        $utc = new \DateTimeImmutable('2026-12-25 01:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-12-24', Calendario::diaLocal($utc)->format('Y-m-d'));
        self::assertNull(Calendario::devidaEm('natal', 0, $utc));
    }
}
