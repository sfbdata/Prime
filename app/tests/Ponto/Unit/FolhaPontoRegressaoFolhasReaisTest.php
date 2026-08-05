<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regressão contra as folhas REAIS de 06 e 07/2026 (`docs/folha-de-ponto/`), que originaram a frente.
 *
 * Existe porque os dois defeitos corrigidos **interagem**: o abono de "Esquecimento de Registro"
 * estava tapando dias de batida incompleta (24/06 e 16/07), e os testes por-regra não pegam isso —
 * cada um exercita sua regra num dia limpo. Aqui os 61 dias entram juntos, com as justificativas que
 * existiam de verdade.
 *
 * ⚠️ Os totais aqui NÃO são os do PDF de produção. As batidas reais têm segundos e `diffMinutos`
 * descarta a fração (`$diff->i`), perdendo até 1 min por par de batidas — ~11 min por mês nestes
 * dois. Como o PDF só publica HH:MM, os segundos originais não são recuperáveis; este teste usa
 * `:00` e portanto mede a jornada **sem** essa perda. O truncamento é defeito conhecido e está
 * registrado fora do escopo na spec. Em produção estes dois meses saem em +6:29 e −30:33.
 *
 * @see docs/specs/ponto-abono-nao-perdoa-jornada.md
 */
#[CoversClass(FolhaPontoBuilder::class)]
#[CoversClass(CalculadoraJornada::class)]
final class FolhaPontoRegressaoFolhasReaisTest extends TestCase
{
    private FolhaPontoBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $this->createStub(LancamentoHorasPagasRepository::class),
        );
    }

    /**
     * Batidas de 06/2026, como impressas na folha. Ordem: entrada · repouso · retorno · saída;
     * '-' é batida ausente.
     */
    private const BATIDAS_JUNHO = [
        '01' => ['07:53', '-',     '-',     '18:00'],
        '02' => ['13:41', '-',     '-',     '20:10'],
        '03' => ['09:00', '-',     '-',     '14:00'],
        '05' => ['08:59', '-',     '-',     '17:39'],
        '08' => ['10:03', '12:32', '13:32', '18:19'],
        '09' => ['09:09', '-',     '-',     '15:08'],
        '10' => ['10:20', '-',     '-',     '15:44'],
        '11' => ['13:54', '-',     '-',     '19:00'],
        '12' => ['10:01', '-',     '-',     '15:00'],
        '13' => ['09:28', '-',     '-',     '14:27'],  // sábado
        '15' => ['08:47', '-',     '13:23', '18:28'],
        '16' => ['08:55', '-',     '-',     '18:57'],
        '17' => ['08:54', '12:29', '13:29', '19:00'],
        '18' => ['09:43', '12:31', '13:30', '18:00'],
        '19' => ['09:14', '12:33', '13:33', '18:30'],
        '22' => ['08:53', '12:35', '13:37', '19:43'],
        '23' => ['09:09', '11:33', '12:33', '18:30'],
        '24' => ['08:56', '12:32', '13:32', '-'],
        '25' => ['08:48', '12:34', '13:38', '19:04'],
        '26' => ['08:48', '13:01', '14:01', '19:04'],
        '27' => ['-',     '-',     '-',     '14:00'],  // sábado, só a saída
        '29' => ['08:02', '12:30', '13:30', '18:20'],
        '30' => ['09:07', '12:42', '13:43', '18:10'],
    ];

    private const BATIDAS_JULHO = [
        '01' => ['09:00', '12:52', '13:52', '18:00'],
        '02' => ['09:44', '12:31', '13:33', '20:00'],
        '03' => ['08:44', '12:35', '13:36', '18:00'],
        '06' => ['09:00', '12:41', '13:42', '19:00'],
        '07' => ['09:06', '12:58', '13:59', '18:16'],
        '08' => ['09:08', '12:41', '13:41', '19:00'],
        '10' => ['09:01', '13:14', '14:14', '19:00'],
        '13' => ['10:02', '13:21', '14:21', '19:16'],
        '14' => ['09:10', '12:44', '13:44', '19:00'],
        '16' => ['09:07', '12:31', '13:32', '-'],
        '20' => ['09:00', '12:33', '13:34', '18:01'],
        '21' => ['-',     '12:49', '13:49', '18:47'],
        '22' => ['08:52', '12:59', '14:02', '19:00'],
        '23' => ['10:56', '13:04', '14:13', '18:38'],
        '24' => ['08:56', '-',     '-',     '-'],
        '27' => ['09:02', '-',     '-',     '18:10'],
        '28' => ['09:09', '12:59', '14:03', '19:21'],
        '29' => ['09:06', '-',     '-',     '-'],
        '30' => ['08:55', '12:43', '13:43', '18:51'],
        '31' => ['09:07', '-',     '-',     '-'],
    ];

    /** Ajuste de jornada = rebaseline do meio período dela (5h/dia até 12/06), janela 14:00–17:48. */
    private const JUSTIFICATIVAS_JUNHO = [
        '01' => 'ajuste_jornada_parcial',
        '02' => 'ajuste_jornada_parcial',
        '03' => 'esquecimento_registro',
        '05' => 'ajuste_jornada_parcial',
        '08' => 'ajuste_jornada_parcial',
        '09' => 'ajuste_jornada_parcial',
        '10' => 'ajuste_jornada_parcial',
        '11' => 'ajuste_jornada_parcial',
        '12' => 'esquecimento_registro',
        '15' => 'esquecimento_registro',
        '16' => 'esquecimento_registro',
        '17' => 'esquecimento_registro',
        '18' => 'esquecimento_registro',
        '19' => 'esquecimento_registro',
        '23' => 'esquecimento_registro',
        '24' => 'dispensa_abonada',
        '26' => 'esquecimento_registro',
        '27' => 'esquecimento_registro',
        '29' => 'esquecimento_registro',
    ];

    private const JUSTIFICATIVAS_JULHO = [
        '01' => 'esquecimento_registro',
        '02' => 'esquecimento_registro',
        '03' => 'esquecimento_registro',
        '06' => 'esquecimento_registro',
        '07' => 'esquecimento_registro',
        '08' => 'esquecimento_registro',
        '13' => 'esquecimento_registro',
        '14' => 'esquecimento_registro',
        '16' => 'esquecimento_registro',
        '20' => 'esquecimento_registro',
    ];

    // ──────────────────────────────────────────────────────────────────
    // Os totais dos dois meses
    // ──────────────────────────────────────────────────────────────────

    public function testJunhoFechaEmSeisHorasEQuarentaMinutos(): void
    {
        $rows = $this->montarMes('2026-06');

        // Antes da frente: +22:51 (1371 min).
        self::assertSame(400, $this->builder->saldoAcumuladoFinal($rows));
    }

    public function testJulhoFechaEmTrintaHorasEVinteEDoisMinutosNegativos(): void
    {
        $rows = $this->montarMes('2026-07');

        // Antes da frente: −62:02 (−3722 min).
        self::assertSame(-1822, $this->builder->saldoAcumuladoFinal($rows));
    }

    // ──────────────────────────────────────────────────────────────────
    // Os dias que motivaram cada regra — o total sozinho poderia bater
    // por compensação entre um erro para cima e outro para baixo
    // ──────────────────────────────────────────────────────────────────

    public function testOsDiasQueODonoApontouAgoraNegativam(): void
    {
        $rows = $this->indexarPorDia($this->montarMes('2026-06'));

        // 18 e 19/06: quatro batidas, jornada não cumprida, esquecimento abonado.
        // Antes valiam 0 — o abono apagava atraso e saída antecipada.
        self::assertSame(-90, $rows['18']['saldoDia'], '18/06: 43 min de atraso + 48 min de saída antecipada');
        self::assertSame(-32, $rows['19']['saldoDia'], '19/06: 14 min de atraso + 18 min de saída antecipada');
        self::assertFalse($rows['18']['registroIncompleto']);
        self::assertFalse($rows['19']['registroIncompleto']);
    }

    public function testOsQuatroDiasDeJulhoDeixamDeCobrarFaltaCheia(): void
    {
        $rows = $this->indexarPorDia($this->montarMes('2026-07'));

        // Antes: −528 cada (−35:12 somados) em dias com batida no relógio.
        foreach (['21', '24', '29', '31'] as $dia) {
            self::assertSame(0, $rows[$dia]['saldoDia'], "dia {$dia}/07 não pode debitar a jornada inteira");
            self::assertTrue($rows[$dia]['registroIncompleto'], "dia {$dia}/07 deve marcar pendência");
        }
    }

    public function testDiaSemBatidaDeIntervaloDeixaDeCreditarOAlmoco(): void
    {
        $rows = $this->indexarPorDia($this->montarMes('2026-07'));

        // 27/07: 09:02→18:10 sem bater o almoço. Antes creditava +20 com a hora de almoço dentro.
        self::assertSame(0, $rows['27']['saldoDia']);
        self::assertTrue($rows['27']['registroIncompleto']);
    }

    public function testAsTresFaltasDeJulhoContinuamSendoFaltas(): void
    {
        $rows = $this->indexarPorDia($this->montarMes('2026-07'));

        // Sem batida nenhuma e sem justificativa: ausência real, decisão explícita do dono.
        foreach (['09', '15', '17'] as $dia) {
            self::assertSame(-528, $rows[$dia]['saldoDia'], "dia {$dia}/07 é falta");
            self::assertFalse($rows[$dia]['registroIncompleto'], 'ausência não é registro incompleto');
        }
    }

    public function testOSabadoTrabalhadoContinuaCreditandoIntegral(): void
    {
        $rows = $this->indexarPorDia($this->montarMes('2026-06'));

        // 13/06, sábado: 09:28→14:27 = 299 min. Fora da escala não há intervalo previsto, então
        // entrada e saída bastam — exigir as quatro batidas descartaria hora extra real.
        self::assertSame(299, $rows['13']['saldoDia']);
        self::assertFalse($rows['13']['registroIncompleto']);
    }

    public function testDiaTapadoPeloAbonoAgoraApareceComoPendencia(): void
    {
        $junho = $this->indexarPorDia($this->montarMes('2026-06'));
        $julho = $this->indexarPorDia($this->montarMes('2026-07'));

        // 24/06 e 16/07 têm batida incompleta e só não apareciam porque o abono os tapava.
        // O saldo continua 0, mas agora pelo motivo certo — e com pendência para o admin resolver.
        self::assertSame(0, $junho['24']['saldoDia']);
        self::assertTrue($junho['24']['registroIncompleto']);
        self::assertSame(0, $julho['16']['saldoDia']);
        self::assertTrue($julho['16']['registroIncompleto']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Montagem
    // ──────────────────────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $rows */
    private function indexarPorDia(array $rows): array
    {
        $porDia = [];
        foreach ($rows as $row) {
            $porDia[$row['diaMes']] = $row;
        }

        return $porDia;
    }

    /** @return array<int, array<string, mixed>> */
    private function montarMes(string $competencia): array
    {
        $batidasFonte       = $competencia === '2026-06' ? self::BATIDAS_JUNHO : self::BATIDAS_JULHO;
        $justificativaFonte = $competencia === '2026-06' ? self::JUSTIFICATIVAS_JUNHO : self::JUSTIFICATIVAS_JULHO;

        $batidas = [];
        foreach ($batidasFonte as $dia => $horarios) {
            $tipos = [
                RegistroPonto::TIPO_ENTRADA,
                RegistroPonto::TIPO_REPOUSO,
                RegistroPonto::TIPO_RETORNO,
                RegistroPonto::TIPO_SAIDA,
            ];
            foreach ($horarios as $i => $hora) {
                if ($hora === '-') {
                    continue;
                }
                $registro = new RegistroPonto();
                $registro->setTipo($tipos[$i]);
                $registro->setDataHora(new \DateTimeImmutable("{$competencia}-{$dia} {$hora}:00"));
                $batidas[] = $registro;
            }
        }

        $justificativas = [];
        foreach ($justificativaFonte as $dia => $tipo) {
            $justificativas["{$competencia}-{$dia}"] = $this->justificativa($tipo);
        }

        $feriados = [];
        if ($competencia === '2026-06') {
            $corpusChristi = new Feriado();
            $corpusChristi->setNome('Corpus Christi');
            $corpusChristi->setData(new \DateTimeImmutable('2026-06-04'));
            $corpusChristi->setRecorrente(false);
            $feriados[] = $corpusChristi;
        }

        $inicioMes = new \DateTimeImmutable("{$competencia}-01");

        return $this->builder->buildRows(
            $inicioMes,
            $inicioMes->modify('last day of this month'),
            $batidas,
            true,
            false,
            $this->jornadaReal(),
            $feriados,
            $justificativas,
            null,
            new \DateTimeImmutable('2026-05-18'), // primeira batida dela
        );
    }

    /** Jornada de produção: 09:00 · 12:30 · 13:30 · 18:48 = 528 min (44h ÷ 5 dias), seg a sex. */
    private function jornadaReal(): JornadaColaborador
    {
        $user = new User();
        $user->setEmail('edlucia@exemplo.test')->setFullName('Colaboradora Teste');

        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana([1, 2, 3, 4, 5]);
        $bloco->setEntrada('09:00');
        $bloco->setRepouso('12:30');
        $bloco->setRetorno('13:30');
        $bloco->setSaida('18:48');
        $bloco->setMinutosBloco(528);

        $jornada = new JornadaColaborador();
        $jornada->setUser($user);
        $jornada->setDiasSemana([1, 2, 3, 4, 5]);
        $jornada->setCargaHorariaDiaria(528);
        $jornada->addBloco($bloco);
        $user->setJornadaColaborador($jornada);

        return $jornada;
    }

    private function justificativa(string $tipo): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setStatus('abonado');

        if ($tipo === 'ajuste_jornada_parcial') {
            $justificativa->setTipo('ajuste_jornada');
            $justificativa->setAbonoParcial(true);
            $justificativa->setHoraInicioAbono(new \DateTimeImmutable('14:00'));
            $justificativa->setHoraFimAbono(new \DateTimeImmutable('17:48'));

            return $justificativa;
        }

        $justificativa->setTipo($tipo);
        $justificativa->setAbonoParcial(false);

        return $justificativa;
    }
}
