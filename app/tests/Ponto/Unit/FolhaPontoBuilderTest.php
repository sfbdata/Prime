<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JustificativaPonto;
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

        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [$feriado], [], null, $this->contagemAntiga());

        $diaFeriado = $rows[20]; // dia 21 = índice 20
        self::assertTrue($diaFeriado['isFeriado']);
        self::assertSame('Tiradentes', $diaFeriado['nomeFeriado']);
    }

    public function testBuildRowsNomeFeriadoEhNullEmDiaNormal(): void
    {
        $jornada = $this->jornadaSimples();

        $inicioMes = new \DateTimeImmutable('2026-04-01');
        $fimMes    = new \DateTimeImmutable('2026-04-30');

        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [], [], null, $this->contagemAntiga());

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

        $rows = $this->builder->buildRows($inicioMes, $fimMes, $batidas, true, false, $jornada, [], [], null, $this->contagemAntiga());

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

        $rows = $this->builder->buildRows($inicioMes, $fimMes, $batidas, true, false, $jornada, [], [], null, $this->contagemAntiga());

        self::assertNull($rows[0]['minutosIntervalo']);
    }

    public function testBuildRowsDispensaAbonadaDiaInteiroZeraSaldoNegativo(): void
    {
        $jornada = $this->jornadaSimples();
        $dia = new \DateTimeImmutable('2026-04-07'); // terça, dia útil passado, sem batidas

        // Sem justificativa, um dia útil sem batidas gera saldo negativo (falta).
        $baseline = $this->builder->buildRows($dia, $dia, [], true, false, $jornada, [], [], null, $this->contagemAntiga())[0]['saldoDia'];
        self::assertLessThan(0, $baseline);

        // Dispensa Abonada de dia inteiro deve zerar a falta e marcar o dia como justificado.
        $justificativa = $this->justificativaAbonada('dispensa_abonada');
        $rows = $this->builder->buildRows(
            $dia,
            $dia,
            [],
            true,
            false,
            $jornada,
            [],
            ['2026-04-07' => $justificativa],
            null,
            $this->contagemAntiga(),
        );

        self::assertSame(0, $rows[0]['saldoDia']);
        self::assertTrue($rows[0]['justificadoDia']);
    }

    public function testBuildRowsDispensaAbonadaDiaInteiroComSaidaAntecipadaAbonaSoODeficit(): void
    {
        $jornada = $this->jornadaSimples(); // 480 min/dia (09-12 / 13-18)
        $dia = new \DateTimeImmutable('2026-04-07'); // terça, dia útil passado

        // Colaborador bateu entrada e saiu antecipado: trabalhou só 5h (300 min) das 8h.
        $batidas = [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_SAIDA,   '15:00', '2026-04-07'),
        ];

        // Sem justificativa: déficit de 180 min (3h que faltaram para fechar a jornada).
        $baseline = $this->builder->buildRows($dia, $dia, $batidas, true, false, $jornada, [], [], null, $this->contagemAntiga())[0]['saldoDia'];
        self::assertSame(-180, $baseline);

        // Dispensa Abonada de dia inteiro (SEM abono parcial): zera o déficit, não credita 8h por cima.
        $justificativa = $this->justificativaAbonada('dispensa_abonada');
        $rows = $this->builder->buildRows(
            $dia,
            $dia,
            $batidas,
            true,
            false,
            $jornada,
            [],
            ['2026-04-07' => $justificativa],
            null,
            $this->contagemAntiga(),
        );

        self::assertSame(0, $rows[0]['saldoDia']); // dia neutro: só o restante que faltava foi abonado
        self::assertTrue($rows[0]['justificadoDia']);
    }

    public function testBuildRowsSistemaIndisponivelAbonoParcialSomaMinutosAbonados(): void
    {
        $jornada = $this->jornadaSimples();
        $dia = new \DateTimeImmutable('2026-04-07'); // terça, dia útil passado, sem batidas

        $baseline = $this->builder->buildRows($dia, $dia, [], true, false, $jornada, [], [], null, $this->contagemAntiga())[0]['saldoDia'];

        // Sistema Indisponível em abono parcial (14:00–16:00 = 120 min) soma os minutos ao saldo.
        $justificativa = $this->justificativaAbonada('sistema_indisponivel', true, '14:00', '16:00');
        self::assertSame(120, $justificativa->getMinutosAbonados());

        $rows = $this->builder->buildRows(
            $dia,
            $dia,
            [],
            true,
            false,
            $jornada,
            [],
            ['2026-04-07' => $justificativa],
            null,
            $this->contagemAntiga(),
        );

        self::assertSame($baseline + 120, $rows[0]['saldoDia']);
        self::assertTrue($rows[0]['justificadoDia']);
    }

    public function testCalcularSaldoAteMesRetornaZeroSemJornada(): void
    {
        $user = new User();
        $user->setEmail('a@b.com')->setFullName('Teste');

        $resultado = $this->builder->calcularSaldoAteMes($user, 2026, 3, []);

        self::assertSame(0, $resultado);
    }

    // ──────────────────────────────────────────────────────────────────
    // Início da contagem: começa no primeiro registro de ponto
    // ──────────────────────────────────────────────────────────────────

    public function testBuildRowsIgnoraDiasAnterioresAoPrimeiroRegistro(): void
    {
        $jornada   = $this->jornadaSimples();
        $inicioMes = new \DateTimeImmutable('2026-04-01'); // qua
        $fimMes    = new \DateTimeImmutable('2026-04-03'); // sex
        $contagem  = new \DateTimeImmutable('2026-04-03'); // primeira batida na sexta

        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [], [], null, $contagem);

        // 01 e 02/04: antes do primeiro registro → fora da contagem
        self::assertTrue($rows[0]['antesDoPrimeiroRegistro']);
        self::assertNull($rows[0]['saldoDia']);
        self::assertNull($rows[0]['saldoAcumulado']);
        self::assertTrue($rows[1]['antesDoPrimeiroRegistro']);
        self::assertNull($rows[1]['saldoDia']);

        // 03/04: dia do primeiro registro (útil, sem batidas) → conta normalmente
        self::assertFalse($rows[2]['antesDoPrimeiroRegistro']);
        self::assertSame(-480, $rows[2]['saldoDia']);
    }

    public function testBuildRowsNaoAcumulaDiasAnterioresAoPrimeiroRegistro(): void
    {
        $jornada   = $this->jornadaSimples();
        $inicioMes = new \DateTimeImmutable('2026-04-01');
        $fimMes    = new \DateTimeImmutable('2026-04-03');
        $contagem  = new \DateTimeImmutable('2026-04-03');

        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [], [], null, $contagem);

        // O banco do 1º dia contado é só o próprio dia (-480), não arrasta 01 e 02
        self::assertSame(-480, $rows[2]['saldoAcumulado']);
    }

    public function testBuildRowsSemPrimeiroRegistroNenhumDiaConta(): void
    {
        $jornada   = $this->jornadaSimples();
        $inicioMes = new \DateTimeImmutable('2026-04-01');
        $fimMes    = new \DateTimeImmutable('2026-04-03');

        // Colaborador que nunca registrou ponto: nada entra no banco (nem débito fantasma).
        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [], [], null, null);

        foreach ($rows as $row) {
            self::assertTrue($row['antesDoPrimeiroRegistro']);
            self::assertNull($row['saldoDia']);
            self::assertNull($row['saldoAcumulado']);
        }
    }

    public function testBuildRowsInicioAnteriorAoMesNaoTemEfeito(): void
    {
        $jornada   = $this->jornadaSimples();
        $inicioMes = new \DateTimeImmutable('2026-04-01');
        $fimMes    = new \DateTimeImmutable('2026-04-03');
        $contagem  = new \DateTimeImmutable('2026-03-15');

        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [], [], null, $contagem);

        self::assertFalse($rows[0]['antesDoPrimeiroRegistro']);
        self::assertSame(-480, $rows[0]['saldoDia']);
    }

    public function testBuildRowsInicioPosteriorAoMesNadaConta(): void
    {
        $jornada   = $this->jornadaSimples();
        $inicioMes = new \DateTimeImmutable('2026-04-01');
        $fimMes    = new \DateTimeImmutable('2026-04-03');
        $contagem  = new \DateTimeImmutable('2026-05-01');

        $rows = $this->builder->buildRows($inicioMes, $fimMes, [], true, false, $jornada, [], [], null, $contagem);

        foreach ($rows as $row) {
            self::assertTrue($row['antesDoPrimeiroRegistro']);
            self::assertNull($row['saldoDia']);
        }
    }

    public function testBuildRowsDiaSemBatidaDepoisDoInicioContinuaSendoFalta(): void
    {
        $jornada  = $this->jornadaSimples();
        $dia      = new \DateTimeImmutable('2026-04-07'); // terça útil, sem batidas
        $contagem = new \DateTimeImmutable('2026-04-01'); // já registrava ponto antes

        $rows = $this->builder->buildRows($dia, $dia, [], true, false, $jornada, [], [], null, $contagem);

        // Buraco DEPOIS do início da contagem é ausência real — continua negativo.
        self::assertFalse($rows[0]['antesDoPrimeiroRegistro']);
        self::assertSame(-480, $rows[0]['saldoDia']);
    }

    public function testCalcularSaldoAnualIgnoraMesesAnterioresAoPrimeiroRegistro(): void
    {
        // Cenário do veterano: admitido há anos, mas só passou a registrar ponto no fim de 2025.
        $user = $this->usuarioComJornada();

        $soNovembroEDezembro = $this->builder->calcularSaldoAnual($user, 2025, [], null, new \DateTimeImmutable('2025-11-01'));
        $anoInteiro          = $this->builder->calcularSaldoAnual($user, 2025, [], null, new \DateTimeImmutable('2025-01-01'));

        self::assertLessThan(0, $soNovembroEDezembro);
        // Contar o ano inteiro seria MUITO mais negativo — é exatamente o débito fantasma que a regra evita.
        self::assertLessThan($soNovembroEDezembro, $anoInteiro);
    }

    public function testCalcularSaldoAnualSemPrimeiroRegistroRetornaZero(): void
    {
        $user = $this->usuarioComJornada();

        self::assertSame(0, $this->builder->calcularSaldoAnual($user, 2025, [], null, null));
    }

    public function testCalcularSaldoAteMesSemPrimeiroRegistroRetornaZero(): void
    {
        $user = $this->usuarioComJornada();

        self::assertSame(0, $this->builder->calcularSaldoAteMes($user, 2026, 3, [], null, null));
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /** Início da contagem bem antes dos cenários: nesses testes todo dia do período deve contar. */
    private function contagemAntiga(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2020-01-01');
    }

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

    private function usuarioComJornada(): User
    {
        $jornada = $this->jornadaSimples();
        $user    = $jornada->getUser();
        $user->setJornadaColaborador($jornada); // linka os dois lados: getJornadaColaborador() != null

        return $user;
    }

    private function justificativaAbonada(
        string $tipo,
        bool $abonoParcial = false,
        ?string $horaInicio = null,
        ?string $horaFim = null,
    ): JustificativaPonto {
        $justificativa = new JustificativaPonto();
        $justificativa->setTipo($tipo);
        $justificativa->setStatus('abonado');
        $justificativa->setAbonoParcial($abonoParcial);

        if ($abonoParcial) {
            $justificativa->setHoraInicioAbono(new \DateTimeImmutable($horaInicio));
            $justificativa->setHoraFimAbono(new \DateTimeImmutable($horaFim));
        }

        return $justificativa;
    }

    private function batida(string $tipo, string $hora, string $data): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTimeImmutable("{$data} {$hora}:00"));

        return $registro;
    }
}
