<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\CatalogoDeEscalas;
use App\Ponto\Service\EscalaDoCatalogo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As 4 escalas da coluna "Escala" da aba Equipe (desenho 16 L409, `ESC`; spec ponto-desenho-0811, E5, P56 a): os ids e
 * os rótulos do desenho, a correspondência pelos minutos de cada dia e os blocos do modelo (entrada 09:00, repouso
 * 12:00, retorno 13:00, saída pela carga do dia).
 */
#[CoversClass(CatalogoDeEscalas::class)]
#[CoversClass(EscalaDoCatalogo::class)]
#[Group('ponto')]
final class CatalogoDeEscalasTest extends TestCase
{
    #[TestDox('As 4 escalas do desenho, na ordem, com os ids e os rótulos literais')]
    public function testEscalasDoDesenho(): void
    {
        self::assertSame([
            '44-6' => '44h · seg a sáb (7h20)',
            '44-5' => '44h · seg a sex (8h48)',
            '40-5' => '40h · seg a sex (8h)',
            '30-5' => '30h · seg a sex (6h)',
        ], array_column(array_map(static fn (EscalaDoCatalogo $e): array => [$e->id, $e->rotulo], CatalogoDeEscalas::todas()), 1, 0));
        self::assertSame(2640, CatalogoDeEscalas::pelo('44-6')?->cargaSemanal);
        self::assertNull(CatalogoDeEscalas::pelo('12x36'));
        self::assertNull(CatalogoDeEscalas::pelo(''));
    }

    /**
     * @param list<int>                               $dias
     * @param array{string, string, string, string, int} $bloco
     */
    #[DataProvider('modelos')]
    #[TestDox('O modelo da escala $id: um bloco, dias e horários do desenho, minutos do dia')]
    public function testBlocosDoModelo(string $id, array $dias, array $bloco): void
    {
        $blocos = CatalogoDeEscalas::pelo($id)?->blocos() ?? [];

        self::assertCount(1, $blocos);
        self::assertSame($dias, $blocos[0]->getDiasSemana());
        self::assertSame($bloco, [$blocos[0]->getEntrada(), $blocos[0]->getRepouso(), $blocos[0]->getRetorno(), $blocos[0]->getSaida(), $blocos[0]->getMinutosBloco()]);
    }

    /** @return iterable<string, array{string, list<int>, array{string, string, string, string, int}}> */
    public static function modelos(): iterable
    {
        yield '44h seg a sáb' => ['44-6', [1, 2, 3, 4, 5, 6], ['09:00', '12:00', '13:00', '17:20', 440]];
        yield '44h seg a sex' => ['44-5', [1, 2, 3, 4, 5], ['09:00', '12:00', '13:00', '18:48', 528]];
        yield '40h seg a sex' => ['40-5', [1, 2, 3, 4, 5], ['09:00', '12:00', '13:00', '18:00', 480]];
        yield '30h seg a sex' => ['30-5', [1, 2, 3, 4, 5], ['09:00', '12:00', '13:00', '16:00', 360]];
    }

    #[TestDox('A escala marcada é a dos mesmos minutos em cada dia, sem olhar o horário; nenhuma igual, nenhuma')]
    public function testCorrespondencia(): void
    {
        $semana = static fn (array $dias, int $minutos): array => array_replace(array_fill(1, 7, 0), array_fill_keys($dias, $minutos));

        self::assertSame('44-6', CatalogoDeEscalas::correspondente($semana([1, 2, 3, 4, 5, 6], 440))?->id);
        self::assertSame('44-5', CatalogoDeEscalas::correspondente($semana([1, 2, 3, 4, 5], 528))?->id);
        self::assertSame('40-5', CatalogoDeEscalas::correspondente($semana([1, 2, 3, 4, 5], 480))?->id);
        self::assertSame('30-5', CatalogoDeEscalas::correspondente($semana([1, 2, 3, 4, 5], 360))?->id);
        self::assertNull(CatalogoDeEscalas::correspondente(array_replace($semana([1, 2, 3, 4], 540), [5 => 480])), '44h de seg a qui 9h e sex 8h não é nenhuma');
        self::assertNull(CatalogoDeEscalas::correspondente($semana([1, 2, 3, 4, 5, 6], 480)), '48h');
        self::assertNull(CatalogoDeEscalas::correspondente(array_fill(1, 7, 0)), 'sem jornada');
    }

    #[TestDox('A carga da opção que descreve a atual: "44h", "42h30"')]
    public function testHoras(): void
    {
        self::assertSame('44h', CatalogoDeEscalas::horas(2640));
        self::assertSame('42h30', CatalogoDeEscalas::horas(2550));
        self::assertSame('0h', CatalogoDeEscalas::horas(0));
        self::assertSame('40h05', CatalogoDeEscalas::horas(2405));
    }
}
