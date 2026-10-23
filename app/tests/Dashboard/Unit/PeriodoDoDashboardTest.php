<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Periodo\PeriodoDoDashboard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Período padrão "Este mês" e janelas do cartão do colaborador (spec dashboard-parte-1). */
#[CoversClass(PeriodoDoDashboard::class)]
#[Group('dashboard')]
final class PeriodoDoDashboardTest extends TestCase
{
    #[TestDox('Sem data na URL: Este mês, de 1º do mês até hoje, sem mexer nos outros filtros')]
    public function testSemDataAbreEmEsteMes(): void
    {
        $filtros = PeriodoDoDashboard::comPadrao(['data_de' => '', 'data_ate' => '', 'cargo' => 'Advogado'], false, new \DateTimeImmutable('2026-10-23 15:00'));

        self::assertSame('2026-10-01', $filtros['data_de']);
        self::assertSame('2026-10-23', $filtros['data_ate']);
        self::assertSame('Advogado', $filtros['cargo']);
    }

    #[TestDox('Data enviada (mesmo vazia) é escolha do usuário: o padrão não entra')]
    public function testDataEnviadaNaoRecebePadrao(): void
    {
        $filtros = ['data_de' => '', 'data_ate' => ''];

        self::assertSame($filtros, PeriodoDoDashboard::comPadrao($filtros, true, new \DateTimeImmutable('2026-10-23')));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function janelas(): iterable
    {
        yield 'mês'                    => ['mes', '2026-08-14', '2026-08-01'];
        yield 'trimestre do 3º'        => ['trimestre', '2026-08-14', '2026-07-01'];
        yield 'trimestre do 1º'        => ['trimestre', '2026-03-31', '2026-01-01'];
        yield 'trimestre do 4º'        => ['trimestre', '2026-12-02', '2026-10-01'];
        yield '1º semestre'            => ['semestre', '2026-06-30', '2026-01-01'];
        yield '2º semestre'            => ['semestre', '2026-07-01', '2026-07-01'];
        yield 'ano'                    => ['ano', '2026-08-14', '2026-01-01'];
        yield 'desconhecida vira mês'  => ['semana', '2026-08-14', '2026-08-01'];
    }

    #[DataProvider('janelas')]
    #[TestDox('Janela $janela em $hoje começa em $inicio e termina hoje')]
    public function testJanelas(string $janela, string $hoje, string $inicio): void
    {
        $periodo = PeriodoDoDashboard::daJanela($janela, new \DateTimeImmutable($hoje));

        self::assertSame(['data_de' => $inicio, 'data_ate' => $hoje], $periodo);
    }

    #[TestDox('Intervalo: começo do primeiro dia e fim do último; ponta vazia ou inválida fica aberta')]
    public function testIntervalo(): void
    {
        [$de, $ate] = PeriodoDoDashboard::intervalo(['data_de' => '2026-10-01', 'data_ate' => '2026-10-23']);
        self::assertSame('2026-10-01 00:00:00', $de?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-23 23:59:59', $ate?->format('Y-m-d H:i:s'));

        self::assertSame([null, null], PeriodoDoDashboard::intervalo(['data_de' => '', 'data_ate' => '2026-02-31']));
    }

    #[TestDox('Janela válida passa; qualquer outra vira Mês')]
    public function testJanelaValida(): void
    {
        self::assertSame('semestre', PeriodoDoDashboard::janelaValida('semestre'));
        self::assertSame('mes', PeriodoDoDashboard::janelaValida('<script>'));
    }
}
