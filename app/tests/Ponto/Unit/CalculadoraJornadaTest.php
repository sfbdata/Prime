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

    // ──────────────────────────────────────────────────────────────────
    // Registro incompleto — dia mal batido não vira falta cheia
    //
    // Cenários calcados nas folhas reais de 06 e 07/2026 (docs/folha-de-ponto/): jornada
    // 09:00 · 12:30 · 13:30 · 18:48 = 528 min. Ver docs/specs/ponto-abono-nao-perdoa-jornada.md.
    // ──────────────────────────────────────────────────────────────────

    /** Jornada com intervalo previsto: a escala pede as QUATRO batidas. */
    private function jornadaComIntervalo(): JornadaColaborador
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setEntrada('09:00');
        $bloco->setRepouso('12:30');
        $bloco->setRetorno('13:30');
        $bloco->setSaida('18:48');
        $bloco->setMinutosBloco(528);

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);

        return $jornada;
    }

    /** Sábado 2026-04-25 — fora da escala seg-sex, sem intervalo previsto. */
    private function sabado(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-25');
    }

    public function testDiaUtilComApenasEntradaNaoDebitaAJornadaInteira(): void
    {
        $jornada = $this->jornadaComIntervalo();
        $user = $this->novoUsuario($jornada);

        // 24/07/2026: bateu 08:56 e esqueceu o resto. Antes valia -528 (a jornada inteira).
        $batidas = [$this->batida(RegistroPonto::TIPO_ENTRADA, '08:56')];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(0, $saldo);
    }

    public function testDiaUtilSemEntradaNaoDebitaAJornadaInteira(): void
    {
        $jornada = $this->jornadaComIntervalo();
        $user = $this->novoUsuario($jornada);

        // 21/07/2026: três das quatro batidas, faltando só a entrada. Antes valia -528.
        $batidas = [
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:49'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:49'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:47'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(0, $saldo);
    }

    public function testDiaUtilSemBatidasDeIntervaloContaOSpanInteiro(): void
    {
        $jornada = $this->jornadaComIntervalo();
        $user = $this->novoUsuario($jornada);

        // 27/07/2026: 09:02→18:10 sem bater o almoço. **É permitido trabalhar sem tirar almoço**
        // (dono, 31/08/2026): o span inteiro são 548 min contra a meta de 528, então o dia credita
        // +20. A escala prevê intervalo e isso deixou de importar — a meta já vem líquida dele.
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:02'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:10'),
        ];

        $this->assertFalse($this->calculadora->registroIncompleto($batidas));
        $this->assertSame(20, $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []));
    }

    public function testDiaUtilSemBatidaNenhumaContinuaSendoFalta(): void
    {
        $jornada = $this->jornadaComIntervalo();
        $user = $this->novoUsuario($jornada);

        // Guarda da regra: ausência NÃO é registro incompleto. Sem esta distinção a mudança
        // apagaria toda falta do sistema — o oposto do que ela existe para fazer.
        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), [], $jornada, []);

        $this->assertSame(-528, $saldo);
    }

    public function testDiaForaDaEscalaBastaEntradaESaidaEPreservaOCredito(): void
    {
        $jornada = $this->jornadaComIntervalo();
        $user = $this->novoUsuario($jornada);

        // Sábado 13/06/2026: 09:28→14:27 = 299 min. Não há intervalo previsto num dia fora da
        // escala, então exigir as quatro batidas descartaria hora extra real de fim de semana.
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:28', '2026-04-25'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '14:27', '2026-04-25'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->sabado(), $batidas, $jornada, []);

        $this->assertSame(299, $saldo);
    }

    public function testDiaForaDaEscalaSemEntradaNaoCredita(): void
    {
        $jornada = $this->jornadaComIntervalo();
        $user = $this->novoUsuario($jornada);

        // Sábado 27/06/2026: só a saída às 14:00. Fora da escala ainda exige entrada e saída.
        $batidas = [$this->batida(RegistroPonto::TIPO_SAIDA, '14:00', '2026-04-25')];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->sabado(), $batidas, $jornada, []);

        $this->assertSame(0, $saldo);
    }

    public function testDiaCompletoContinuaSendoApuradoNormalmente(): void
    {
        $jornada = $this->jornadaComIntervalo();
        $user = $this->novoUsuario($jornada);

        // 19/06/2026: as quatro batidas presentes → 8:16 contra meta de 8:48 = -32.
        // É o dia que o dono apontou: negativa, sim — quem apagava era o abono, não o cálculo.
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:14'),
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:33'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:33'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:30'),
        ];

        $saldo = $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []);

        $this->assertSame(-32, $saldo);
    }

    public function testRegistroIncompletoEhFalsoQuandoNaoHaBatidaNenhuma(): void
    {
        $this->assertFalse(
            $this->calculadora->registroIncompleto([])
        );
    }

    public function testRegistroIncompletoEhVerdadeiroQuandoBateuSoMetadeDoIntervalo(): void
    {
        // Bateu o `repouso` e esqueceu o `retorno`: PROVOU que saiu para almoçar, mas não por
        // quanto tempo. Contar o span inteiro creditaria esse almoço — por isso a exceção à regra
        // de "entrada e saída bastam". Decisão do dono em 31/08/2026, perguntado explicitamente.
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:07'),
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:31'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:00'),
        ];

        $this->assertTrue(
            $this->calculadora->registroIncompleto($batidas)
        );
    }

    public function testRegistroIncompletoEhVerdadeiroQuandoBateuSoORetornoDoIntervalo(): void
    {
        // Espelho do anterior: bateu o `retorno` sem o `repouso`. Mesma incógnita, mesmo veredito —
        // e é o que impede a regra do XOR de valer só para um dos lados.
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:07'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:31'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:00'),
        ];

        $this->assertTrue($this->calculadora->registroIncompleto($batidas));
    }

    public function testDiaComAlmocoBatidoMasSemSaidaContinuaIncompleto(): void
    {
        $jornada = $this->jornadaComIntervalo();
        $user = $this->novoUsuario($jornada);

        // 16/07/2026: três batidas, faltando só a saída. Não há fim para medir, então o dia não
        // credita nem debita — vale mesmo com o intervalo inteiro registrado.
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:07'),
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:31'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:32'),
        ];

        $this->assertTrue($this->calculadora->registroIncompleto($batidas));
        $this->assertSame(0, $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []));
    }

    public function testJornadaSemIntervaloCadastradoNaoExigeBatidaDeAlmoco(): void
    {
        // Bloco sem repouso/retorno cadastrado: o dia é apurável com entrada e saída, como todo
        // dia agora é. Preserva o comportamento de quem nunca cadastrou horário de almoço — e o
        // teste continua aqui porque essa escala existe em produção e não pode regredir.
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setEntrada('09:00');
        $bloco->setSaida('18:00');
        $bloco->setMinutosBloco(480);

        $jornada = new JornadaColaborador();
        $jornada->addBloco($bloco);
        $user = $this->novoUsuario($jornada);

        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '18:30'),
        ];

        $this->assertFalse($this->calculadora->registroIncompleto($batidas));
        $this->assertSame(90, $this->calculadora->calcularSaldoDia($user, $this->segunda(), $batidas, $jornada, []));
    }
}
