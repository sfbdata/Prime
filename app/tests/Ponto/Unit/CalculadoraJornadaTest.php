<?php

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\TestCase;

class CalculadoraJornadaTest extends TestCase
{
    private CalculadoraJornada $calculadora;

    protected function setUp(): void
    {
        $this->calculadora = new CalculadoraJornada(new JornadaResolver());
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function novoUsuario(?JornadaColaborador $jornada = null): User
    {
        $user = new User();
        $user->setEmail('teste@jusprime.com')->setFullName('Teste');
        if ($jornada !== null) {
            $user->setJornadaColaborador($jornada);
        }
        return $user;
    }

    private function batida(string $tipo, string $horaMinuto, string $data = '2026-04-20'): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTimeImmutable("{$data} {$horaMinuto}:00"));
        return $registro;
    }

    private function feriadoEm(string $data, bool $recorrente = false): Feriado
    {
        $feriado = new Feriado();
        $feriado->setData(new \DateTimeImmutable($data));
        $feriado->setNome('Feriado Teste');
        $feriado->setRecorrente($recorrente);
        return $feriado;
    }

    /** Segunda-feira 2026-04-20 (N=1) */
    private function segunda(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-20');
    }

    /** Domingo 2026-04-26 (N=7) */
    private function domingo(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-26');
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — jornada em blocos
    // ──────────────────────────────────────────────────────────────────

    public function testSaldoPositivoComBlocosUsuario(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480); // meta: 8h

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:30'), // 9h30 = 570 min → saldo +90
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(90, $saldo);
    }

    public function testSaldoNegativoComBlocosUsuario(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '16:00'), // 7h = 420 min → saldo -60
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(-60, $saldo);
    }

    public function testSaldoZeroComBlocosTenantFallback(): void
    {
        $jornada = new JornadaColaborador(); // sem blocos
        $user = $this->novoUsuario($jornada);

        $blocoTenant = new BlocoJornada();
        $blocoTenant->setDiasSemana([1, 2, 3, 4, 5]);
        $blocoTenant->setMinutosBloco(480);

        $jornadaTenant = new JornadaTenant();
        $jornadaTenant->addBloco($blocoTenant);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00'), // 8h = 480 min → saldo 0
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, [], $jornadaTenant);

        $this->assertSame(0, $saldo);
    }

    public function testBatidasComIntervaloAlmocoContamCorretamente(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480); // meta 8h

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);

        // 09:00–12:00 (3h) + 13:00–18:00 (5h) = 8h = 480 min
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:00'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:00'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(0, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — feriado
    // ──────────────────────────────────────────────────────────────────

    public function testFeriadoComBlocosRetornaZeroSemHorasExtras(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);
        $feriados = [$this->feriadoEm('2026-04-20')];

        // Feriado: meta fica 0; bateu 8h → saldo +480
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, $feriados);

        $this->assertSame(480, $saldo, 'Em feriado a meta é 0, então horas trabalhadas viram saldo positivo');
    }

    public function testFeriadoSemBatidasRetornaZero(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);
        $feriados = [$this->feriadoEm('2026-04-20')];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), [], $jornada, $feriados);

        $this->assertSame(0, $saldo);
    }

    public function testFeriadoRecorrenteEhDetectado(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);

        // Feriado recorrente em 04-20 (dia do mês) → deve casar com 2026-04-20
        $feriados = [$this->feriadoEm('2025-04-20', true)];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), [], $jornada, $feriados);

        $this->assertSame(0, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — tolerância de 5 minutos
    // ──────────────────────────────────────────────────────────────────

    public function testToleranciaAtraso4MinutosRetornaZero(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480); // meta 8h = 480 min

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);

        // 476 min trabalhados → saldo -4 (dentro da tolerância de 5 min)
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '16:56'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(0, $saldo);
    }

    public function testAtraso5MinutosDescontaExato(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);

        // 475 min trabalhados → saldo -5 (exatamente no limite: desconta)
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '16:55'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(-5, $saldo);
    }

    public function testHoraExtra1MinutoContabiliza(): void
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        $user = $this->novoUsuario($jornada);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:01'), // +1 min
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(1, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — domingo (N=7) sempre retorna 0
    // ──────────────────────────────────────────────────────────────────

    public function testDomingoSemMetaRetornaCreditoPositivo(): void
    {
        $jornada = new JornadaColaborador();
        $user = $this->novoUsuario($jornada);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-26'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00', '2026-04-26'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->domingo(), $batidas, $jornada, []);

        // Domingo não tem meta: 8h trabalhadas = +480 min de crédito
        $this->assertSame(480, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — retrocompatibilidade com campos planos
    // ──────────────────────────────────────────────────────────────────

    public function testRetrocompatibilidadeCamposPlanosDiaUtil(): void
    {
        $jornada = new JornadaColaborador(); // sem blocos; padrão 480 min, seg–sex
        $user = $this->novoUsuario($jornada);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00'), // 480 min exatos → saldo 0
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(0, $saldo);
    }

    public function testRetrocompatibilidadeCamposPlanosSabado(): void
    {
        $jornada = new JornadaColaborador();
        $jornada->setDiasSemana([1, 2, 3, 4, 5, 6]);
        $jornada->setCargaHorariaSabado(240); // 4h no sábado

        $user = $this->novoUsuario($jornada);

        $sabado = new \DateTimeImmutable('2026-04-25');
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-25'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '13:00', '2026-04-25'), // 240 min → saldo 0
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $sabado, $batidas, $jornada, []);

        $this->assertSame(0, $saldo);
    }

    public function testRetrocompatibilidadeDiaForaDaEscalaRetornaCreditoPositivo(): void
    {
        $jornada = new JornadaColaborador();
        $jornada->setDiasSemana([1, 2, 3, 4]); // sem sexta

        $user = $this->novoUsuario($jornada);

        $sexta = new \DateTimeImmutable('2026-04-24');
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-24'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00', '2026-04-24'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $sexta, $batidas, $jornada, []);

        // Dia fora da escala não tem meta: 8h trabalhadas = +480 min de crédito
        $this->assertSame(480, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularMinutosTrabalhados
    // ──────────────────────────────────────────────────────────────────

    public function testMinutosTrabalhadosSemEntradaRetornaZero(): void
    {
        $batidas = [
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00'),
        ];

        $this->assertSame(0, $this->calculadora->calcularMinutosTrabalhados($batidas));
    }

    public function testMinutosTrabalhadosSoEntradaRetornaZero(): void
    {
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
        ];

        $this->assertSame(0, $this->calculadora->calcularMinutosTrabalhados($batidas));
    }

    public function testMinutosTrabalhadosEntradaSaida(): void
    {
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00'),
        ];

        $this->assertSame(480, $this->calculadora->calcularMinutosTrabalhados($batidas));
    }

    public function testMinutosTrabalhadosComIntervalo(): void
    {
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:00'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:00'),
        ];

        // 3h + 5h = 8h = 480 min
        $this->assertSame(480, $this->calculadora->calcularMinutosTrabalhados($batidas));
    }
}
