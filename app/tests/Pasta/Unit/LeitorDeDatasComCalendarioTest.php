<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Pasta\Service\DiasUteisDaUf;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Pasta\Service\LeitorDeDatas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Estimativa de prazo com o calendário do sistema (docs/specs/prazo-feriado-calendario.md, tabela da seção 4):
 * "antes" é o leitor sem calendário, "depois" com o calendário da UF do escritório.
 */
#[CoversClass(LeitorDeDatas::class)]
#[CoversClass(DiasUteisDoEscritorio::class)]
#[CoversClass(DiasUteisDaUf::class)]
final class LeitorDeDatasComCalendarioTest extends TestCase
{
    private DiasUteisDoEscritorio $dias;

    protected function setUp(): void
    {
        $this->dias = new DiasUteisDoEscritorio(new CalendarioDoSistema());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> texto, base, antes, depois */
    public static function tabela(): iterable
    {
        yield 'Sexta-feira Santa (03/04) passa a não contar' => ['Prazo de 5 dias para manifestação.', '2026-03-30', '2026-04-06', '2026-04-07'];
        yield 'Carnaval é ponto facultativo e continua útil' => ['Prazo de 3 dias para manifestação.', '2026-02-13', '2026-02-18', '2026-02-18'];
        yield 'Corpus Christi é ponto facultativo e continua útil' => ['Prazo de 3 dias para manifestação.', '2026-06-02', '2026-06-05', '2026-06-05'];
        yield 'Emancipação de Alagoas (16/09) não conta em AL' => ['Prazo de 2 dias para manifestação.', '2026-09-15', '2026-09-17', '2026-09-18'];
        yield 'corridos que caem no sábado vão à segunda' => ['Prazo de 10 dias corridos.', '2026-04-08', '2026-04-18', '2026-04-20'];
        yield 'recesso de 20/12 a 20/01 igual' => ['Prazo de 5 dias para manifestação.', '2026-12-18', '2027-01-27', '2027-01-27'];
        yield 'data escrita não muda' => ['Prazo até 03/04/2026.', '2026-03-30', '2026-04-03', '2026-04-03'];
        yield 'audiência em feriado não muda' => ['Audiência de instrução em 16/09/2026 às 14h.', '2026-09-01', '2026-09-16', '2026-09-16'];
    }

    #[DataProvider('tabela')]
    #[TestDox('escritório em AL: "$texto" em $base fica $antes sem calendário e $depois com ele')]
    public function testTabelaDaSpec(string $texto, string $base, string $antes, string $depois): void
    {
        $b = new \DateTimeImmutable($base);

        self::assertSame($antes, LeitorDeDatas::ler($texto, $b)[0]['data']);
        self::assertSame($antes, LeitorDeDatas::ler($texto, $b, null)[0]['data'], 'null é o cálculo antigo');
        self::assertSame($depois, LeitorDeDatas::ler($texto, $b, $this->dias->daUf('AL'))[0]['data']);
    }

    #[TestDox('o modo novo só muda a data: tipo, título, hora, estimado e frase são os mesmos')]
    public function testSoADataMuda(): void
    {
        $b = new \DateTimeImmutable('2026-03-30');
        $antes = LeitorDeDatas::ler('Prazo de 5 dias para manifestação.', $b)[0];
        $depois = LeitorDeDatas::ler('Prazo de 5 dias para manifestação.', $b, $this->dias->daUf('AL'))[0];

        self::assertTrue($depois['estimado']);
        unset($antes['data'], $depois['data']);
        self::assertSame($antes, $depois);
    }

    #[TestDox('escritório em MG (sem estadual no calendário) ou sem UF: só os nacionais e o recesso')]
    public function testUfSemEstadual(): void
    {
        $setembro = new \DateTimeImmutable('2026-09-15');
        $marco = new \DateTimeImmutable('2026-03-30');
        foreach (['MG', null, ''] as $uf) {
            $cal = $this->dias->daUf($uf);
            self::assertSame('2026-09-17', LeitorDeDatas::ler('Prazo de 2 dias para manifestação.', $setembro, $cal)[0]['data']);
            self::assertSame('2026-04-07', LeitorDeDatas::ler('Prazo de 5 dias para manifestação.', $marco, $cal)[0]['data'], 'Sexta-feira Santa vale em todo lugar');
        }
    }

    #[TestDox('"amanhã" não é estimativa e não muda, mesmo caindo no sábado')]
    public function testAmanhaNaoMuda(): void
    {
        self::assertSame('2026-04-18', LeitorDeDatas::ler('Prazo vence amanhã.', new \DateTimeImmutable('2026-04-17'), $this->dias->daUf('AL'))[0]['data']);
    }

    /** @return iterable<string, array{0: string, 1: ?string, 2: bool}> */
    public static function dias(): iterable
    {
        yield 'sábado' => ['2026-04-18', 'AL', false];
        yield 'domingo' => ['2026-04-19', 'AL', false];
        yield 'Sexta-feira Santa' => ['2026-04-03', null, false];
        yield 'Tiradentes' => ['2026-04-21', null, false];
        yield 'estadual de AL em AL' => ['2026-09-16', 'AL', false];
        yield 'estadual de AL em AL, minúsculo' => ['2026-09-16', 'al', false];
        yield 'estadual de AL em SP' => ['2026-09-16', 'SP', true];
        yield 'recesso em dezembro' => ['2026-12-21', null, false];
        yield 'recesso em janeiro' => ['2027-01-20', null, false];
        yield 'fim do recesso' => ['2027-01-21', null, true];
        yield 'Carnaval (ponto facultativo)' => ['2026-02-17', null, true];
        yield 'Segunda de Carnaval (tribunal)' => ['2026-02-16', null, true];
        yield 'Corpus Christi (ponto facultativo)' => ['2026-06-04', null, true];
        yield 'Dia do Advogado (tribunal)' => ['2026-08-11', null, true];
        yield 'Dia do Servidor (tribunal)' => ['2026-10-28', null, true];
        yield 'Dia do Cliente (comemorativa)' => ['2026-09-15', null, true];
        yield 'dia comum' => ['2026-10-19', 'AL', true];
    }

    #[DataProvider('dias')]
    #[TestDox('$dia em $uf é útil: $util')]
    public function testEhUtil(string $dia, ?string $uf, bool $util): void
    {
        self::assertSame($util, $this->dias->ehUtil(new \DateTimeImmutable($dia), $uf));
    }

    #[TestDox('próximo útil: o próprio dia se for útil; senão pula fim de semana, feriado e recesso')]
    public function testProximoUtil(): void
    {
        $p = fn (string $d, ?string $uf = 'AL'): string => $this->dias->proximoUtil(new \DateTimeImmutable($d . ' 15:30'), $uf)->format('Y-m-d H:i');

        self::assertSame('2026-10-19 00:00', $p('2026-10-19'));
        self::assertSame('2026-04-06', substr($p('2026-04-03'), 0, 10), 'Sexta Santa, sábado e domingo');
        self::assertSame('2026-09-17', substr($p('2026-09-16'), 0, 10));
        self::assertSame('2026-09-16', substr($p('2026-09-16', 'MG'), 0, 10));
        self::assertSame('2027-01-21', substr($p('2026-12-24'), 0, 10));
    }

    #[TestDox('o calendário fica guardado por ano e UF: perguntar de novo não monta outro')]
    public function testCachePorAnoEUf(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->dias->ehUtil(new \DateTimeImmutable('2026-09-16'), 'AL');
            $this->dias->ehUtil(new \DateTimeImmutable('2026-03-02'), 'al');
        }
        $this->dias->ehUtil(new \DateTimeImmutable('2026-09-16'), 'SP');
        $this->dias->ehUtil(new \DateTimeImmutable('2027-09-16'), 'AL');
        $this->dias->ehUtil(new \DateTimeImmutable('2027-09-19'), 'AL');

        $guardados = (new \ReflectionProperty(DiasUteisDoEscritorio::class, 'naoUteis'))->getValue($this->dias);
        self::assertSame(['2026|AL', '2026|SP', '2027|AL'], array_keys($guardados));
    }
}
