<?php

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Ponto\BlocoEscalaUsuario;
use App\Entity\Ponto\BlocoJornada;
use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Ponto\Feriado;
use App\Entity\Ponto\JornadaTenant;
use App\Entity\Ponto\RegistroPonto;
use App\Service\Ponto\CalculadoraJornada;
use App\Service\Ponto\JornadaResolver;
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

    private function novoUsuario(?EscalaTrabalho $escala = null): User
    {
        $user = new User();
        $user->setEmail('teste@jusprime.com')->setFullName('Teste');
        if ($escala !== null) {
            $user->setEscalaTrabalho($escala);
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
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480); // meta: 8h

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:30'), // 9h30 = 570 min → saldo +90
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, []);

        $this->assertSame(90, $saldo);
    }

    public function testSaldoNegativoComBlocosUsuario(): void
    {
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '16:00'), // 7h = 420 min → saldo -60
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, []);

        $this->assertSame(-60, $saldo);
    }

    public function testSaldoZeroComBlocosTenantFallback(): void
    {
        $escala = new EscalaTrabalho(); // sem blocos
        $user = $this->novoUsuario($escala);

        $blocoTenant = new BlocoJornada();
        $blocoTenant->setDiasSemana([1, 2, 3, 4, 5]);
        $blocoTenant->setMinutosBloco(480);

        $jornadaTenant = new JornadaTenant();
        $jornadaTenant->addBloco($blocoTenant);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00'), // 8h = 480 min → saldo 0
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, [], $jornadaTenant);

        $this->assertSame(0, $saldo);
    }

    public function testBatidasComIntervaloAlmocoContamCorretamente(): void
    {
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480); // meta 8h

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);

        // 09:00–12:00 (3h) + 13:00–18:00 (5h) = 8h = 480 min
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:00'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:00'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, []);

        $this->assertSame(0, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — feriado
    // ──────────────────────────────────────────────────────────────────

    public function testFeriadoComBlocosRetornaZeroSemHorasExtras(): void
    {
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);
        $feriados = [$this->feriadoEm('2026-04-20')];

        // Feriado: meta fica 0; bateu 8h → saldo +480
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, $feriados);

        $this->assertSame(480, $saldo, 'Em feriado a meta é 0, então horas trabalhadas viram saldo positivo');
    }

    public function testFeriadoSemBatidasRetornaZero(): void
    {
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);
        $feriados = [$this->feriadoEm('2026-04-20')];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), [], $escala, $feriados);

        $this->assertSame(0, $saldo);
    }

    public function testFeriadoRecorrenteEhDetectado(): void
    {
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);

        // Feriado recorrente em 04-20 (dia do mês) → deve casar com 2026-04-20
        $feriados = [$this->feriadoEm('2025-04-20', true)];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), [], $escala, $feriados);

        $this->assertSame(0, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — tolerância de 5 minutos
    // ──────────────────────────────────────────────────────────────────

    public function testToleranciaAtraso4MinutosRetornaZero(): void
    {
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480); // meta 8h = 480 min

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);

        // 476 min trabalhados → saldo -4 (dentro da tolerância de 5 min)
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '16:56'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, []);

        $this->assertSame(0, $saldo);
    }

    public function testAtraso5MinutosDescontaExato(): void
    {
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);

        // 475 min trabalhados → saldo -5 (exatamente no limite: desconta)
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '16:55'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, []);

        $this->assertSame(-5, $saldo);
    }

    public function testHoraExtra1MinutoContabiliza(): void
    {
        $bloco = new BlocoEscalaUsuario();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setMinutosBloco(480);

        $escala = new EscalaTrabalho();
        $escala->addBloco($bloco);

        $user = $this->novoUsuario($escala);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:01'), // +1 min
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, []);

        $this->assertSame(1, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — domingo (N=7) sempre retorna 0
    // ──────────────────────────────────────────────────────────────────

    public function testDomingoSemMetaRetornaCreditoPositivo(): void
    {
        $escala = new EscalaTrabalho();
        $user = $this->novoUsuario($escala);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-26'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00', '2026-04-26'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->domingo(), $batidas, $escala, []);

        // Domingo não tem meta: 8h trabalhadas = +480 min de crédito
        $this->assertSame(480, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoDia — retrocompatibilidade com campos planos
    // ──────────────────────────────────────────────────────────────────

    public function testRetrocompatibilidadeCamposPlanosDiaUtil(): void
    {
        $escala = new EscalaTrabalho(); // sem blocos; padrão 480 min, seg–sex
        $user = $this->novoUsuario($escala);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00'), // 480 min exatos → saldo 0
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $escala, []);

        $this->assertSame(0, $saldo);
    }

    public function testRetrocompatibilidadeCamposPlanosSabado(): void
    {
        $escala = new EscalaTrabalho();
        $escala->setDiasSemana([1, 2, 3, 4, 5, 6]);
        $escala->setCargaHorariaSabado(240); // 4h no sábado

        $user = $this->novoUsuario($escala);

        $sabado = new \DateTimeImmutable('2026-04-25');
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-25'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '13:00', '2026-04-25'), // 240 min → saldo 0
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $sabado, $batidas, $escala, []);

        $this->assertSame(0, $saldo);
    }

    public function testRetrocompatibilidadeDiaForaDaEscalaRetornaCreditoPositivo(): void
    {
        $escala = new EscalaTrabalho();
        $escala->setDiasSemana([1, 2, 3, 4]); // sem sexta

        $user = $this->novoUsuario($escala);

        $sexta = new \DateTimeImmutable('2026-04-24');
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-24'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '17:00', '2026-04-24'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $sexta, $batidas, $escala, []);

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
