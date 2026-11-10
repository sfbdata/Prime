<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\HistoricoDeLotacoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A lotação da pessoa no escritório dia a dia (spec `docs/specs/ponto-desenho-0811.md`, E6: lotação com vigência, no
 * molde do `HistoricoDeJornadas`).
 */
#[CoversClass(HistoricoDeLotacoes::class)]
#[Group('ponto')]
final class HistoricoDeLotacoesTest extends TestCase
{
    #[TestDox('Sem versões, a lotação de hoje do vínculo vale para todos os dias; sem vínculo, nenhuma')]
    public function testSemVersoes(): void
    {
        self::assertSame(11, HistoricoDeLotacoes::doVinculo(11, [])->lotacaoNoDia(new \DateTimeImmutable('1999-01-01')));
        self::assertSame(11, HistoricoDeLotacoes::doVinculo(11, [])->lotacaoNoDia(new \DateTimeImmutable('2099-01-01')));
        self::assertNull(HistoricoDeLotacoes::semVinculo()->lotacaoNoDia(new \DateTimeImmutable('2026-11-09')));
    }

    #[TestDox('Cada versão vale da sua data até a véspera da seguinte; a inicial vale para trás; a última, para a frente')]
    public function testVersoes(): void
    {
        $historico = HistoricoDeLotacoes::doVinculo(13, [
            ['desde' => '2026-11-09', 'lotacao' => 13],
            ['desde' => null, 'lotacao' => 11],
            ['desde' => '2026-06-01', 'lotacao' => null],
        ]);

        self::assertSame(11, $historico->lotacaoNoDia(new \DateTimeImmutable('2001-01-01')), 'a inicial vale para trás');
        self::assertSame(11, $historico->lotacaoNoDia(new \DateTimeImmutable('2026-05-31')));
        self::assertNull($historico->lotacaoNoDia(new \DateTimeImmutable('2026-06-01')), 'sem lotação naquele período');
        self::assertNull($historico->lotacaoNoDia(new \DateTimeImmutable('2026-11-08 23:59')), 'só a data conta');
        self::assertSame(13, $historico->lotacaoNoDia(new \DateTimeImmutable('2026-11-09 00:00')));
        self::assertSame(13, $historico->lotacaoNoDia(new \DateTimeImmutable('2030-01-01')));
    }

    #[TestDox('A lotação de hoje do vínculo não conta quando há versões: o histórico manda')]
    public function testHistoricoMandaSobreOVinculo(): void
    {
        $historico = HistoricoDeLotacoes::doVinculo(99, [['desde' => null, 'lotacao' => 11]]);

        self::assertSame(11, $historico->lotacaoNoDia(new \DateTimeImmutable('2026-11-09')));
    }
}
