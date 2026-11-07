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
        $quarenta = $this->versao('2026-04-01', 2400)->setCobreDiasAnteriores(true);
        $quarentaEQuatro = $this->versao('2026-04-22', 2640);
        $historico = HistoricoDeJornadas::das([$quarentaEQuatro, $quarenta]);

        self::assertNotNull($historico);
        self::assertSame($quarenta, $historico->vigenteEm(new \DateTimeImmutable('2026-04-01')));
        self::assertSame($quarenta, $historico->vigenteEm(new \DateTimeImmutable('2026-04-21 23:59:59')));
        self::assertSame($quarentaEQuatro, $historico->vigenteEm(new \DateTimeImmutable('2026-04-22 00:00:00')));
        self::assertSame($quarentaEQuatro, $historico->vigenteEm(new \DateTimeImmutable('2027-01-10')));
        self::assertSame([$quarenta, $quarentaEQuatro], $historico->versoes());
    }

    #[TestDox('A versão mais antiga vale também para os dias anteriores à sua data, quando alguma versão cobre para trás')]
    public function testAMaisAntigaValeParaTras(): void
    {
        $unica = $this->versao('2026-11-07', 2640);
        self::assertSame($unica, HistoricoDeJornadas::deUma($unica)->vigenteEm(new \DateTimeImmutable('2025-01-01')));

        $migrada = $this->versao('2026-04-01', 2640)->setCobreDiasAnteriores(true);
        $nova = $this->versao('2026-05-01', 2400);
        $historico = HistoricoDeJornadas::das([$nova, $migrada]);
        self::assertNotNull($historico);
        self::assertSame($migrada, $historico->vigenteEm(new \DateTimeImmutable('2026-03-15')));

        // A marca vale para o histórico: a migrada pode virar a segunda versão (caso do Samuel, §10.1).
        $quarenta = $this->versao('2026-04-01', 2400);
        $migrada->setValeAPartirDe(new \DateTimeImmutable('2026-05-01'));
        $historico = HistoricoDeJornadas::das([$migrada, $quarenta]);
        self::assertNotNull($historico);
        self::assertSame($quarenta, $historico->vigenteEm(new \DateTimeImmutable('2026-03-15')));
    }

    #[TestDox('A primeira jornada criada pela tela não vale antes da sua data')]
    public function testPrimeiraVersaoDaTelaNaoValeParaTras(): void
    {
        $primeira = $this->versao('2026-12-01', 2640);
        $historico = HistoricoDeJornadas::das([$primeira]);

        self::assertNotNull($historico);
        self::assertNull($historico->vigenteEm(new \DateTimeImmutable('2026-11-30')), 'antes dela, a pessoa não tinha jornada pessoal');
        self::assertSame($primeira, $historico->vigenteEm(new \DateTimeImmutable('2026-12-01')));
    }

    #[TestDox('Uma versão agendada para o futuro não vale hoje')]
    public function testVersaoFuturaNaoValeHoje(): void
    {
        $atual = $this->versao('2026-04-01', 2400)->setCobreDiasAnteriores(true);
        $agendada = $this->versao('2026-12-01', 1800);
        $historico = HistoricoDeJornadas::das([$atual, $agendada]);

        self::assertNotNull($historico);
        self::assertSame($atual, $historico->vigenteEm(new \DateTimeImmutable('2026-11-30')));
        self::assertSame($agendada, $historico->vigenteEm(new \DateTimeImmutable('2026-12-01')));
    }

    #[TestDox('Antes da primeira versão da tela vale a jornada pedida por quem lê; se a primeira cobre para trás, não')]
    public function testJornadaAntesDaPrimeira(): void
    {
        $vazia = new JornadaColaborador();
        $dezembro = $this->versao('2026-12-01', 2400);

        $historico = HistoricoDeJornadas::das([$dezembro])?->comJornadaAntesDaPrimeira($vazia);
        self::assertSame($vazia, $historico?->vigenteEm(new \DateTimeImmutable('2026-11-07')));
        self::assertSame($dezembro, $historico?->vigenteEm(new \DateTimeImmutable('2026-12-01')));
        self::assertNull($historico?->semA($dezembro), 'sem versões, nenhum histórico');

        $migrada = $this->versao('2026-04-01', 2640)->setCobreDiasAnteriores(true);
        $comMigrada = HistoricoDeJornadas::das([$migrada, $dezembro])?->comJornadaAntesDaPrimeira($vazia);
        self::assertSame($migrada, $comMigrada?->vigenteEm(new \DateTimeImmutable('2026-03-10')));
        $semMigrada = HistoricoDeJornadas::das([$migrada, $dezembro])?->semA($migrada)?->comJornadaAntesDaPrimeira($vazia);
        self::assertSame($dezembro, $semMigrada?->vigenteEm(new \DateTimeImmutable('2026-03-10')), 'a marca da excluída continua valendo');
    }

    #[TestDox('Sem uma versão, os dias dela passam para a vizinha e os dias de antes continuam cobertos')]
    public function testSemAVersao(): void
    {
        $migrada = $this->versao('2026-04-01', 2640)->setCobreDiasAnteriores(true);
        $maio = $this->versao('2026-05-01', 2400);
        $historico = HistoricoDeJornadas::das([$migrada, $maio]);

        $semMigrada = $historico?->semA($migrada);
        self::assertSame($maio, $semMigrada?->vigenteEm(new \DateTimeImmutable('2026-03-10')));
        self::assertSame([$maio], $semMigrada?->versoes());
        self::assertSame($migrada, $historico?->semA($maio)?->vigenteEm(new \DateTimeImmutable('2026-06-10')));
        self::assertNull(HistoricoDeJornadas::das([$maio])?->semA($maio));
    }

    private function versao(string $valeAPartirDe, int $cargaSemanal): JornadaColaborador
    {
        return (new JornadaColaborador())
            ->setValeAPartirDe(new \DateTimeImmutable($valeAPartirDe))
            ->setCargaHorariaSemanal($cargaSemanal);
    }
}
