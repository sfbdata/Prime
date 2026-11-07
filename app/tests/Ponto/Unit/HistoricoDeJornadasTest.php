<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Service\HistoricoDeJornadas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Spec `docs/specs/ponto-jornada-vigencia.md` §3.1: qual versão da jornada vale em cada dia. */
#[CoversClass(HistoricoDeJornadas::class)]
final class HistoricoDeJornadasTest extends TestCase
{
    #[TestDox('Sem versão nenhuma não há histórico')]
    public function testSemVersaoNaoHaHistorico(): void
    {
        self::assertNull(HistoricoDeJornadas::das([]));
    }

    #[TestDox('Cada versão vale da sua data até a véspera da seguinte')]
    public function testCadaVersaoValeAteAVesperaDaSeguinte(): void
    {
        $quarenta = $this->versao('2026-04-01', 2400);
        $quarentaEQuatro = $this->versao('2026-04-22', 2640);
        $historico = HistoricoDeJornadas::das([$quarentaEQuatro, $quarenta]);

        self::assertNotNull($historico);
        self::assertSame($quarenta, $historico->vigenteEm(new \DateTimeImmutable('2026-04-01')));
        self::assertSame($quarenta, $historico->vigenteEm(new \DateTimeImmutable('2026-04-21 23:59:59')));
        self::assertSame($quarentaEQuatro, $historico->vigenteEm(new \DateTimeImmutable('2026-04-22 00:00:00')));
        self::assertSame($quarentaEQuatro, $historico->vigenteEm(new \DateTimeImmutable('2027-01-10')));
        self::assertSame([$quarenta, $quarentaEQuatro], $historico->versoes());
    }

    #[TestDox('A versão mais antiga vale também para os dias anteriores à sua data')]
    public function testAMaisAntigaValeParaTras(): void
    {
        $unica = $this->versao('2026-11-07', 2640);
        $historico = HistoricoDeJornadas::deUma($unica);

        self::assertSame($unica, $historico->vigenteEm(new \DateTimeImmutable('2025-01-01')));
        self::assertSame($unica, $historico->vigenteEm(new \DateTimeImmutable('2026-11-06')));
    }

    #[TestDox('Uma versão agendada para o futuro não vale hoje')]
    public function testVersaoFuturaNaoValeHoje(): void
    {
        $atual = $this->versao('2026-04-01', 2400);
        $agendada = $this->versao('2026-12-01', 1800);
        $historico = HistoricoDeJornadas::das([$atual, $agendada]);

        self::assertNotNull($historico);
        self::assertSame($atual, $historico->vigenteEm(new \DateTimeImmutable('2026-11-30')));
        self::assertSame($agendada, $historico->vigenteEm(new \DateTimeImmutable('2026-12-01')));
    }

    private function versao(string $valeAPartirDe, int $cargaSemanal): JornadaColaborador
    {
        return (new JornadaColaborador())
            ->setValeAPartirDe(new \DateTimeImmutable($valeAPartirDe))
            ->setCargaHorariaSemanal($cargaSemanal);
    }
}
