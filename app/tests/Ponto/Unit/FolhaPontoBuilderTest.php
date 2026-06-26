<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FolhaPontoBuilder::class)]
final class FolhaPontoBuilderTest extends TestCase
{
    private FolhaPontoBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
        );
    }

    public function testBuildRowsAdicionaNomeFeriadoQuandoDiaEhFeriado(): void
    {
        $jornada = $this->jornadaSimples();

        $feriado = new Feriado();
        $feriado->setNome('Tiradentes');
        $feriado->setData(new \DateTimeImmutable('2026-04-21'));
        $feriado->setRecorrente(false);

        $inicioMes = new \DateTimeImmutable('2026-04-01');
        $fimMes    = new \DateTimeImmutable('2026-04-30');

        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [$feriado], []);

        $diaFeriado = $rows[20]; // dia 21 = índice 20
        self::assertTrue($diaFeriado['isFeriado']);
        self::assertSame('Tiradentes', $diaFeriado['nomeFeriado']);
    }

    public function testBuildRowsNomeFeriadoEhNullEmDiaNormal(): void
    {
        $jornada = $this->jornadaSimples();

        $inicioMes = new \DateTimeImmutable('2026-04-01');
        $fimMes    = new \DateTimeImmutable('2026-04-30');

        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [], []);

        self::assertFalse($rows[0]['isFeriado']);
        self::assertNull($rows[0]['nomeFeriado']);
    }

    public function testBuildRowsCalculaMinutosIntervaloComRepousoERetorno(): void
    {
        $jornada = $this->jornadaSimples();

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_REPOUSO,  '12:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_RETORNO,  '13:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_SAIDA,    '18:00', '2026-04-07'),
        ];

        $inicioMes = new \DateTimeImmutable('2026-04-07');
        $fimMes    = new \DateTimeImmutable('2026-04-07');

        $rows = $this->builder->buildRows($inicioMes, $fimMes, $batidas, true, false, $jornada, [], []);

        self::assertSame(60, $rows[0]['minutosIntervalo']);
    }

    public function testBuildRowsMinutosIntervaloEhNullSemRepouso(): void
    {
        $jornada = $this->jornadaSimples();

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_SAIDA,   '18:00', '2026-04-07'),
        ];

        $inicioMes = new \DateTimeImmutable('2026-04-07');
        $fimMes    = new \DateTimeImmutable('2026-04-07');

        $rows = $this->builder->buildRows($inicioMes, $fimMes, $batidas, true, false, $jornada, [], []);

        self::assertNull($rows[0]['minutosIntervalo']);
    }

    public function testCalcularSaldoAteMesRetornaZeroSemJornada(): void
    {
        $user = new User();
        $user->setEmail('a@b.com')->setFullName('Teste');

        $resultado = $this->builder->calcularSaldoAteMes($user, 2026, 3, []);

        self::assertSame(0, $resultado);
    }

    public function testCalcularSaldoAteMesRetornaZeroSemCreatedAt(): void
    {
        $user    = new User();
        $jornada = new JornadaColaborador($user);

        $resultado = $this->builder->calcularSaldoAteMes($user, 2026, 3, []);

        self::assertSame(0, $resultado);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function jornadaSimples(): JornadaColaborador
    {
        $user = new User();
        $user->setEmail('t@t.com')->setFullName('T');

        $jornada = new JornadaColaborador();
        $jornada->setUser($user);
        $jornada->setDiasSemana([1, 2, 3, 4, 5]);
        $jornada->setCargaHorariaDiaria(480);
        $jornada->setEntrada1('09:00');
        $jornada->setSaida1('12:00');
        $jornada->setEntrada2('13:00');
        $jornada->setSaida2('18:00');

        return $jornada;
    }

    private function batida(string $tipo, string $hora, string $data): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTimeImmutable("{$data} {$hora}:00"));

        return $registro;
    }
}
