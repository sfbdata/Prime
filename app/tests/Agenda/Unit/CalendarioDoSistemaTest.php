<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\DTO\DataDoSistemaOutput as D;
use App\Agenda\Service\CalendarioDoSistema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CalendarioDoSistema::class)]
#[CoversClass(D::class)]
final class CalendarioDoSistemaTest extends TestCase
{
    private CalendarioDoSistema $sut;

    protected function setUp(): void
    {
        $this->sut = new CalendarioDoSistema();
    }

    /** @return iterable<string, array{int, string}> */
    public static function pascoas(): iterable
    {
        yield '2024' => [2024, '2024-03-31'];
        yield '2025' => [2025, '2025-04-20'];
        yield '2026' => [2026, '2026-04-05'];
        yield '2027' => [2027, '2027-03-28'];
        yield '2028' => [2028, '2028-04-16'];
        yield '2038' => [2038, '2038-04-25'];
    }

    #[DataProvider('pascoas')]
    #[TestDox('Páscoa de $ano cai em $esperada')]
    public function testPascoa(int $ano, string $esperada): void
    {
        self::assertSame($esperada, $this->sut->pascoa($ano)->format('Y-m-d'));
    }

    #[TestDox('Datas móveis de 2026: Carnaval e Corpus Christi como ponto facultativo, Sexta-feira Santa como feriado')]
    public function testMoveisDe2026(): void
    {
        $datas = $this->doAno(2026);

        self::assertSame(D::TIPO_PONTO_FACULTATIVO, $this->achar($datas, '2026-02-17', D::CAL_NACIONAL)->tipo);
        self::assertSame('Carnaval · ponto facultativo', $this->achar($datas, '2026-02-17', D::CAL_NACIONAL)->titulo);
        self::assertSame(D::TIPO_FERIADO, $this->achar($datas, '2026-04-03', D::CAL_NACIONAL)->tipo);
        self::assertSame(D::TIPO_PONTO_FACULTATIVO, $this->achar($datas, '2026-06-04', D::CAL_NACIONAL)->tipo);
        self::assertSame('Quarta de Cinzas · expediente a partir das 14h', $this->achar($datas, '2026-02-18', D::CAL_TRIBUNAL)->titulo);
    }

    #[TestDox('Doze datas no calendário de feriados nacionais por ano, com a fonte legal')]
    public function testDozeNacionaisComFonte(): void
    {
        $nacionais = array_filter($this->doAno(2027), static fn (D $d): bool => D::CAL_NACIONAL === $d->calendario);

        self::assertCount(12, $nacionais);
        foreach ($nacionais as $d) {
            self::assertNotEmpty($d->fonte, $d->titulo . ' sem fonte');
        }
        self::assertSame('Consciência Negra', $this->achar($this->doAno(2027), '2027-11-20', D::CAL_NACIONAL)->titulo);
    }

    #[TestDox('Dia das Mães e Dia dos Pais caem no segundo domingo de maio e de agosto')]
    public function testMaesEPais(): void
    {
        self::assertSame('Dia das Mães', $this->achar($this->doAno(2026), '2026-05-10', D::CAL_COMEMORATIVA)->titulo);
        self::assertSame('Dia dos Pais', $this->achar($this->doAno(2026), '2026-08-09', D::CAL_COMEMORATIVA)->titulo);
        self::assertSame('Dia das Mães', $this->achar($this->doAno(2027), '2027-05-09', D::CAL_COMEMORATIVA)->titulo);
        self::assertSame('Natal', $this->achar($this->doAno(2027), '2027-12-25', D::CAL_NACIONAL)->titulo);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function eleicoes(): iterable
    {
        yield '2026' => [2026, '2026-10-04', '2026-10-25'];
        yield '2028, quando 1º + 21 dias erraria' => [2028, '2028-10-01', '2028-10-29'];
    }

    #[DataProvider('eleicoes')]
    #[TestDox('Eleições de $ano: 1º turno no 1º domingo e 2º turno no último domingo de outubro')]
    public function testEleicoes(int $ano, string $primeiro, string $segundo): void
    {
        $datas = $this->doAno($ano);

        self::assertSame(D::TIPO_ELEICAO, $this->achar($datas, $primeiro, D::CAL_TRIBUNAL)->tipo);
        self::assertSame('Eleições · 2º turno (onde houver)', $this->achar($datas, $segundo, D::CAL_TRIBUNAL)->titulo);
    }

    #[TestDox('Ano ímpar não tem eleição')]
    public function testAnoImparSemEleicao(): void
    {
        $eleicoes = array_filter($this->doAno(2027), static fn (D $d): bool => D::TIPO_ELEICAO === $d->tipo);

        self::assertSame([], $eleicoes);
    }

    #[TestDox('Recesso de 20/12 a 06/01 e prazos suspensos de 07/01 a 20/01, atravessando o ano')]
    public function testRecessoAtravessaOAno(): void
    {
        $datas = $this->sut->doPeriodo(new \DateTimeImmutable('2026-12-19'), new \DateTimeImmutable('2027-01-21'), []);
        $recesso = array_values(array_filter($datas, static fn (D $d): bool => D::CAL_FORENSE === $d->calendario));

        self::assertCount(32, $recesso);
        self::assertSame('2026-12-20', $recesso[0]->data);
        self::assertSame('Recesso forense', $this->achar($datas, '2027-01-06', D::CAL_FORENSE)->titulo);
        self::assertSame('Prazos suspensos', $this->achar($datas, '2027-01-07', D::CAL_FORENSE)->titulo);
        self::assertSame('2027-01-20', $recesso[31]->data);
    }

    #[TestDox('Recesso de janeiro vem do ano anterior quando o período começa em janeiro')]
    public function testRecessoDoAnoAnterior(): void
    {
        $datas = $this->sut->doPeriodo(new \DateTimeImmutable('2027-01-10'), new \DateTimeImmutable('2027-01-10'), []);

        self::assertSame('Prazos suspensos', $this->achar($datas, '2027-01-10', D::CAL_FORENSE)->titulo);
    }

    #[TestDox('Feriados estaduais só das UFs pedidas; com duas UFs o nome leva a sigla')]
    public function testEstaduais(): void
    {
        $so = $this->sut->doPeriodo(new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable('2026-11-30'), ['AL']);
        $duas = $this->sut->doPeriodo(new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable('2026-11-30'), ['AL', 'DF']);
        $nenhuma = $this->sut->doPeriodo(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-09'), []);

        self::assertSame('Dia do Evangélico', $this->achar($so, '2026-11-30', D::CAL_ESTADUAL)->titulo);
        self::assertSame('AL', $this->achar($so, '2026-11-30', D::CAL_ESTADUAL)->uf);
        $titulos = array_map(static fn (D $d): string => $d->titulo, array_filter($duas, static fn (D $d): bool => D::CAL_ESTADUAL === $d->calendario));
        self::assertSame(['Dia do Evangélico · AL', 'Dia do Evangélico · DF'], array_values($titulos));
        self::assertSame([], array_filter($nenhuma, static fn (D $d): bool => D::CAL_ESTADUAL === $d->calendario));
    }

    #[TestDox('Só devolve datas dentro do período, em ordem, sem marcar nada como contando no ponto')]
    public function testPeriodoOrdenado(): void
    {
        $datas = $this->sut->doPeriodo(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), []);
        $dias = array_map(static fn (D $d): string => $d->data, $datas);

        self::assertNotEmpty($datas);
        self::assertSame($dias, (static function (array $x): array {
            sort($x);

            return $x;
        })($dias));
        self::assertGreaterThanOrEqual('2026-10-01', $dias[0]);
        self::assertLessThanOrEqual('2026-10-31', end($dias));
        self::assertSame([], array_filter($datas, static fn (D $d): bool => $d->contaNoPonto));
    }

    #[TestDox('Ids são únicos em todo ano de 2000 a 2100, inclusive quando a Sexta-feira Santa cai em 21/04')]
    public function testIdsUnicos(): void
    {
        $ufs = array_keys(CalendarioDoSistema::ESTADUAIS);
        for ($ano = 2000; $ano <= 2100; ++$ano) {
            $ids = array_map(static fn (D $d): string => $d->id, $this->sut->doPeriodo(
                new \DateTimeImmutable($ano . '-01-01'),
                new \DateTimeImmutable($ano . '-12-31'),
                $ufs,
            ));
            self::assertSame(count($ids), count(array_unique($ids)), 'ids repetidos em ' . $ano);
        }
    }

    #[TestDox('Id tem o formato estável calendário-data-nome')]
    public function testFormatoDoId(): void
    {
        $datas = $this->doAno(2026);

        self::assertSame('fer-2026-02-17-carnaval', $this->achar($datas, '2026-02-17', D::CAL_NACIONAL)->id);
        self::assertSame('com-2026-05-10-dia-das-maes', $this->achar($datas, '2026-05-10', D::CAL_COMEMORATIVA)->id);
        self::assertSame('trib-2026-10-25-eleicoes', $this->achar($datas, '2026-10-25', D::CAL_TRIBUNAL)->id);
    }

    /** @return list<D> */
    private function doAno(int $ano): array
    {
        return $this->sut->doPeriodo(new \DateTimeImmutable($ano . '-01-01'), new \DateTimeImmutable($ano . '-12-31'), []);
    }

    /** @param list<D> $datas */
    private function achar(array $datas, string $data, string $calendario): D
    {
        foreach ($datas as $d) {
            if ($d->data === $data && $d->calendario === $calendario) {
                return $d;
            }
        }
        self::fail("Nenhuma data {$calendario} em {$data}");
    }
}
