<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\AjusteDaMarcacao;
use App\Ponto\Model\BatidaAjustada;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\HistoricoDeJornadas;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use App\Ponto\Repository\LotacaoComVigenciaRepository;
use App\Ponto\Service\FeriadosDaPessoa;

#[CoversClass(FolhaPontoBuilder::class)]
final class FolhaPontoBuilderTest extends TestCase
{
    private FolhaPontoBuilder $builder;

    /** A jornada pessoal que o repositório devolve para o escritório da folha (spec seguranca-b-jornada §3.2). */
    private ?JornadaColaborador $jornadaNoEscritorio = null;

    protected function setUp(): void
    {
        $jornadas = $this->createStub(JornadaColaboradorRepository::class);
        $jornadas->method('historicoDe')->willReturnCallback(
            fn (): ?HistoricoDeJornadas => $this->jornadaNoEscritorio === null ? null : HistoricoDeJornadas::deUma($this->jornadaNoEscritorio)
        );

        $this->builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $this->createStub(LancamentoHorasPagasRepository::class),
            $jornadas,
            new FeriadosDaPessoa($this->createStub(LotacaoComVigenciaRepository::class)),
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

    /**
     * Item 12 da E17: sem jornada pessoal no escritório, o banco conta os dias pela jornada vazia, que o JornadaResolver
     * completa com a padrão (aqui, sem jornada do escritório: 8h de segunda a sexta), a mesma regra da folha da /ponto.
     * Até a E17 o resultado era zero. Sem batida nenhuma, cada dia útil é falta.
     */
    public function testCalcularSaldoAteMesSemJornadaPessoalContaPelaPadrao(): void
    {
        $user = new User();
        $user->setEmail('a@b.com')->setFullName('Teste');

        $resultado = $this->builder->calcularSaldoAteMes($user, 2026, 3, [], null, $this->contagemAntiga(), new Tenant());

        $diasUteis = 0;
        for ($dia = $this->contagemAntiga(); $dia <= new \DateTimeImmutable('2026-03-31'); $dia = $dia->modify('+1 day')) {
            $diasUteis += (int) $dia->format('N') <= 5 ? 1 : 0;
        }
        self::assertSame(-480 * $diasUteis, $resultado);
    }

    public function testOmitirInicioContagemFalhaEmVezDeZerarASilenciosamente(): void
    {
        $jornada   = $this->jornadaSimples();
        $inicioMes = new \DateTimeImmutable('2026-04-01');

        // Omitir o parâmetro é erro de chamada — não pode virar "folha vazia" sem ninguém perceber.
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->buildRows($inicioMes, $inicioMes, [], true, false, $jornada, [], []);
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

    public function testCalcularSaldoAteDezembroIgnoraMesesAnterioresAoPrimeiroRegistro(): void
    {
        // Cenário do veterano: admitido há anos, mas só passou a registrar ponto no fim de 2025.
        $user = $this->usuarioComJornada();

        // O stub de horas pagas soma zero e mantém a comparação isolada nas batidas: a diferença
        // medida vem só da janela de contagem.
        $soNovembroEDezembro = $this->builder->calcularSaldoAteMes($user, 2025, 12, [], null, new \DateTimeImmutable('2025-11-01'), new Tenant());
        $anoInteiro          = $this->builder->calcularSaldoAteMes($user, 2025, 12, [], null, new \DateTimeImmutable('2025-01-01'), new Tenant());

        self::assertLessThan(0, $soNovembroEDezembro);
        // Contar o ano inteiro seria MUITO mais negativo — é exatamente o débito fantasma que a regra evita.
        self::assertLessThan($soNovembroEDezembro, $anoInteiro);
    }

    public function testCalcularSaldoAteDezembroSemPrimeiroRegistroRetornaZero(): void
    {
        $user = $this->usuarioComJornada();

        self::assertSame(0, $this->builder->calcularSaldoAteMes($user, 2025, 12, [], null, null, new Tenant()));
    }

    public function testCalcularSaldoAteMesSemPrimeiroRegistroRetornaZero(): void
    {
        $user = $this->usuarioComJornada();

        self::assertSame(0, $this->builder->calcularSaldoAteMes($user, 2026, 3, [], null, null, new Tenant()));
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
        $this->jornadaNoEscritorio = $jornada;   // é a jornada da pessoa no escritório da folha

        // createdAt ANTIGO de propósito: é o que faz os testes de saldo discriminarem a regra nova
        // da que quebrou a produção. Com o createdAt padrão (= agora), a regra antiga
        // (`dataAdmissao ?? createdAt`) devolveria 0 por acidente e os testes passariam à toa.
        $user->setCreatedAt(new \DateTimeImmutable('2020-01-01'));

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

    // ──────────────────────────────────────────────────────────────────
    // Abono técnico não perdoa jornada · dia mal batido não vira falta
    // Ver docs/specs/ponto-abono-nao-perdoa-jornada.md
    // ──────────────────────────────────────────────────────────────────

    /** Terça 07/04/2026, jornada de 480 min com intervalo previsto: 7h batidas = saldo -60. */
    private function diaDeSeteHoras(): array
    {
        return [
            $this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_REPOUSO, '12:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_RETORNO, '13:00', '2026-04-07'),
            $this->batida(RegistroPonto::TIPO_SAIDA,   '17:00', '2026-04-07'),
        ];
    }

    private function linhaDoDia7(array $batidas, array $justificativas): array
    {
        $rows = $this->builder->buildRows(
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-30'),
            $batidas,
            true,
            false,
            $this->jornadaSimples(),
            [],
            $justificativas,
            null,
            $this->contagemAntiga(),
        );

        return $rows[6]; // dia 07 = índice 6
    }

    public function testEsquecimentoDeRegistroNaoZeraMaisOSaldoNegativo(): void
    {
        // O coração da mudança: a aprovação do esquecimento já repôs a batida; o déficit que sobra
        // é atraso/saída antecipada e não tem relação com o registro esquecido.
        $linha = $this->linhaDoDia7(
            $this->diaDeSeteHoras(),
            ['2026-04-07' => $this->justificativaAbonada('esquecimento_registro')],
        );

        self::assertSame(-60, $linha['saldoDia']);
        self::assertTrue($linha['justificadoDia'], 'segue marcado como justificado na folha');
    }

    public function testDemaisTiposTecnicosTambemNaoZeram(): void
    {
        foreach (['registro_incorreto', 'ajuste_manual_autorizado', 'falha_sistema', 'correcao_ponto'] as $tipo) {
            $linha = $this->linhaDoDia7(
                $this->diaDeSeteHoras(),
                ['2026-04-07' => $this->justificativaAbonada($tipo)],
            );

            self::assertSame(-60, $linha['saldoDia'], sprintf('%s não pode abonar', $tipo));
        }
    }

    public function testAbonoLegalOuOperacionalContinuaZerandoONegativo(): void
    {
        foreach (['atestado_medico', 'dispensa_abonada'] as $tipo) {
            $linha = $this->linhaDoDia7(
                $this->diaDeSeteHoras(),
                ['2026-04-07' => $this->justificativaAbonada($tipo)],
            );

            self::assertSame(0, $linha['saldoDia'], sprintf('%s continua abonando', $tipo));
            self::assertTrue($linha['justificadoDia']);
        }
    }

    public function testAbonoParcialOperacionalContinuaSomando(): void
    {
        $linha = $this->linhaDoDia7(
            $this->diaDeSeteHoras(),
            ['2026-04-07' => $this->justificativaAbonada('ajuste_jornada', true, '14:00', '17:48')],
        );

        // -60 + 228 (janela 14:00–17:48) = +168
        self::assertSame(168, $linha['saldoDia']);
    }

    public function testJustificativaSemTipoContinuaZerando(): void
    {
        // Retrocompatibilidade: `tipo` é anulável e a tela de edição aceita limpá-lo. O ramo que
        // zera é o default; só os tipos listados em abonaSaldo() saem dele.
        $justificativa = $this->justificativaAbonada('dispensa_abonada');
        $justificativa->setTipo(null);

        $linha = $this->linhaDoDia7($this->diaDeSeteHoras(), ['2026-04-07' => $justificativa]);

        self::assertSame(0, $linha['saldoDia']);
    }

    public function testDiaComBatidaIncompletaFicaEmZeroEMarcaPendencia(): void
    {
        // 24/07/2026 na folha real: bateu a entrada e esqueceu o resto. Antes: -480.
        $linha = $this->linhaDoDia7(
            [$this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07')],
            [],
        );

        self::assertSame(0, $linha['saldoDia']);
        self::assertTrue($linha['registroIncompleto']);
    }

    public function testDiaIncompletoIgnoraAteOAbonoParcial(): void
    {
        // Discrimina a premissa: antes seria -480 + 228 = -252. A pendência existe para o admin
        // corrigir as batidas — abonar por cima devolveria ao dia um número que ninguém mediu.
        $linha = $this->linhaDoDia7(
            [$this->batida(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07')],
            ['2026-04-07' => $this->justificativaAbonada('ajuste_jornada', true, '14:00', '17:48')],
        );

        self::assertSame(0, $linha['saldoDia']);
        self::assertTrue($linha['registroIncompleto']);
    }

    public function testDiaCompletoNaoMarcaPendencia(): void
    {
        $linha = $this->linhaDoDia7($this->diaDeSeteHoras(), []);

        self::assertSame(-60, $linha['saldoDia']);
        self::assertFalse($linha['registroIncompleto']);
    }

    public function testDiaSemBatidaNenhumaNaoMarcaPendenciaESegueSendoFalta(): void
    {
        $linha = $this->linhaDoDia7([], []);

        self::assertSame(-480, $linha['saldoDia']);
        self::assertFalse($linha['registroIncompleto'], 'ausência não é registro incompleto');
    }

    // ──────────────────────────────────────────────────────────────────
    // Uma escolha só: célula, link, intervalo, minutos e saldo leem as mesmas batidas
    // Ver docs/specs/ponto-folha-uma-batida-por-tipo.md §9.3
    // ──────────────────────────────────────────────────────────────────

    public function testCelulasIdsIntervaloEContaSaemDasMesmasBatidas(): void
    {
        $repousoCedo = $this->batidaComId(RegistroPonto::TIPO_REPOUSO, '09:00', '2026-04-07', 11);
        $repousoReal = $this->batidaComId(RegistroPonto::TIPO_REPOUSO, '12:00', '2026-04-07', 12);
        $retorno     = $this->batidaComId(RegistroPonto::TIPO_RETORNO, '13:00', '2026-04-07', 13);

        $linha = $this->linhaDoDia7([
            $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '08:00', '2026-04-07', 10),
            $repousoCedo,
            $repousoReal,
            $retorno,
            $this->batidaComId(RegistroPonto::TIPO_SAIDA, '18:00', '2026-04-07', 14),
        ], []);

        // A célula, o link de editar/excluir e o intervalo apontam para o MESMO repouso que a conta usa.
        self::assertSame('09:00:00', $linha['repouso']);
        self::assertSame(11, $linha['repousoId']);
        self::assertSame(240, $linha['minutosIntervalo'], 'intervalo do repouso exibido (09:00) ao retorno (13:00)');
        self::assertSame(360, $linha['minutosTrabalhadosDia'], '08:00→09:00 + 13:00→18:00');
        self::assertSame(-120, $linha['saldoDia']);
    }

    public function testDiaAmbiguoTrazAMarcaEAsBatidasQueFicaramDeFora(): void
    {
        $linha = $this->linhaDoDia7([
            $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '08:00', '2026-04-07', 10),
            $this->batidaComId(RegistroPonto::TIPO_REPOUSO, '09:00', '2026-04-07', 11),
            $this->batidaComId(RegistroPonto::TIPO_REPOUSO, '12:00', '2026-04-07', 12),
            $this->batidaComId(RegistroPonto::TIPO_RETORNO, '13:00', '2026-04-07', 13),
            $this->batidaComId(RegistroPonto::TIPO_SAIDA, '18:00', '2026-04-07', 14),
        ], []);

        self::assertSame([BatidasEscolhidas::MARCA_REPOUSOS_DISTINTOS], $linha['aConferir']);
        self::assertSame(
            [['id' => 12, 'alvo' => 'b12', 'cabeca' => null, 'tipo' => RegistroPonto::TIPO_REPOUSO, 'hora' => '12:00:00']],
            $linha['batidasDesconsideradas']
        );
    }

    public function testRepeticaoDoMesmoRegistroFicaDeForaSemMarcarODia(): void
    {
        $linha = $this->linhaDoDia7([
            $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07', 10),
            $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07', 15),
            $this->batidaComId(RegistroPonto::TIPO_SAIDA, '16:00', '2026-04-07', 14),
        ], []);

        self::assertSame(10, $linha['entradaId']);
        self::assertSame([], $linha['aConferir'], 'repetir o mesmo toque não torna o dia ambíguo');
        self::assertSame([['id' => 15, 'alvo' => 'b15', 'cabeca' => null, 'tipo' => RegistroPonto::TIPO_ENTRADA, 'hora' => '09:00:00']], $linha['batidasDesconsideradas']);
    }

    public function testBatidaAjustadaForaDaContaLevaACabecaDaLinhagem(): void
    {
        // E10: o "x" do "Fora da conta" manda a cabeça de volta; sem ela, a inclusão e a batida ajustada voltavam "mudou".
        $ajuste = new AjusteDaMarcacao(TipoDoAjuste::Alteracao, 'Gestora', new \DateTimeImmutable('2026-04-08 10:00:00'), null);
        $inclusao = new BatidaAjustada(null, 40, 40, 5, RegistroPonto::TIPO_ENTRADA, new \DateTimeImmutable('2026-04-07 10:00:00'), $ajuste);
        $ajustada = new BatidaAjustada(16, null, 44, 5, RegistroPonto::TIPO_ENTRADA, new \DateTimeImmutable('2026-04-07 11:00:00'), $ajuste);

        $linha = $this->linhaDoDia7([
            $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '08:00', '2026-04-07', 10),
            $inclusao,
            $ajustada,
            $this->batidaComId(RegistroPonto::TIPO_SAIDA, '17:00', '2026-04-07', 14),
        ], []);

        self::assertSame(10, $linha['entradaId']);
        self::assertSame([
            ['id' => null, 'alvo' => 'a40', 'cabeca' => 40, 'tipo' => RegistroPonto::TIPO_ENTRADA, 'hora' => '10:00:00'],
            ['id' => 16, 'alvo' => 'b16', 'cabeca' => 44, 'tipo' => RegistroPonto::TIPO_ENTRADA, 'hora' => '11:00:00'],
        ], $linha['batidasDesconsideradas']);
    }

    public function testDiaSemBatidaNaoTemMarcaNemBatidaDesconsiderada(): void
    {
        $linha = $this->linhaDoDia7([], []);

        self::assertSame([], $linha['aConferir']);
        self::assertSame([], $linha['batidasDesconsideradas']);
    }

    public function testSeloDeHomeOfficeSaiSoDasBatidasEscolhidas(): void
    {
        // Como sempre foi: o selo olha as batidas que a folha mostra, não as que ficaram fora da conta.
        $entradaNaSede = $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07', 10);
        $repeticaoEmCasa = $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '09:01', '2026-04-07', 11, static fn (RegistroPonto $r) => $r->setHomeOffice(true));

        $linha = $this->linhaDoDia7([$entradaNaSede, $repeticaoEmCasa, $this->batidaComId(RegistroPonto::TIPO_SAIDA, '16:00', '2026-04-07', 12)], []);
        self::assertFalse($linha['homeOffice'], 'a repetição fora da conta não acende o selo');

        $saidaEmCasa = $this->batidaComId(RegistroPonto::TIPO_SAIDA, '16:00', '2026-04-07', 13, static fn (RegistroPonto $r) => $r->setHomeOffice(true));
        $linha = $this->linhaDoDia7([$entradaNaSede, $saidaEmCasa], []);
        self::assertTrue($linha['homeOffice'], 'a batida escolhida em home office acende o selo');
    }

    public function testSeloSemInternetSaiSoDasBatidasEscolhidas(): void
    {
        // E14 (spec ponto-desenho-0811): como o home office, o selo olha só as batidas que a folha mostra.
        $entrada = $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07', 10);
        $repeticaoDaFila = $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '09:01', '2026-04-07', 11, static fn (RegistroPonto $r) => $r->setEnviadaSemInternet(true));

        $linha = $this->linhaDoDia7([$entrada, $repeticaoDaFila, $this->batidaComId(RegistroPonto::TIPO_SAIDA, '16:00', '2026-04-07', 12)], []);
        self::assertFalse($linha['semInternet'], 'a repetição fora da conta não acende o selo');

        $saidaDaFila = $this->batidaComId(RegistroPonto::TIPO_SAIDA, '16:00', '2026-04-07', 13, static fn (RegistroPonto $r) => $r->setEnviadaSemInternet(true));
        $linha = $this->linhaDoDia7([$entrada, $saidaDaFila], []);
        self::assertTrue($linha['semInternet'], 'a batida escolhida que veio da fila acende o selo');
    }

    public function testTrabalhoExternoSemSeloNaFolha(): void
    {
        // E12, decisão C1 de 10/11/2026: o trabalho externo não tem selo na folha (o desenho, L153, só mostra o dia da
        // semana); a batida conta e aparece "Em análise" na coluna Justificativa.
        $entrada = $this->batidaComId(RegistroPonto::TIPO_ENTRADA, '09:00', '2026-04-07', 10);
        $saidaExterna = $this->batidaComId(RegistroPonto::TIPO_SAIDA, '16:00', '2026-04-07', 13, static fn (RegistroPonto $b) => $b->setTrabalhoExterno(true));

        $linha = $this->linhaDoDia7([$entrada, $saidaExterna], []);
        self::assertArrayNotHasKey('trabalhoExterno', $linha);
        self::assertSame(['16:00:00', false, false], [$linha['saida'], $linha['homeOffice'], $linha['semInternet']]);
    }

    public function testJustificativaLigadaABatidaNaoAbonaSaldoMesmoAbonada(): void
    {
        // E12: aprovar o trabalho externo só confirma a batida, que já conta; o déficit do dia continua.
        $ligada = $this->justificativaAbonada('trabalho_externo');
        $ligada->setRegistroPonto($this->diaDeSeteHoras()[0]);
        $linha = $this->linhaDoDia7($this->diaDeSeteHoras(), ['2026-04-07' => $ligada]);
        self::assertSame(-60, $linha['saldoDia']);

        $parcialLigada = $this->justificativaAbonada('trabalho_externo', true, '14:00', '16:00');
        $parcialLigada->setRegistroPonto($this->diaDeSeteHoras()[0]);
        self::assertSame(-60, $this->linhaDoDia7($this->diaDeSeteHoras(), ['2026-04-07' => $parcialLigada])['saldoDia'], 'nem o abono parcial soma');

        // A justificativa de trabalho externo avulsa (pela janela de justificar, sem batida) continua abonando.
        self::assertSame(0, $this->linhaDoDia7($this->diaDeSeteHoras(), ['2026-04-07' => $this->justificativaAbonada('trabalho_externo')])['saldoDia']);
    }

    /** @param (callable(RegistroPonto): mixed)|null $antesDeGravar E10: depois do id a batida não muda mais. */
    private function batidaComId(string $tipo, string $hora, string $data, int $id, ?callable $antesDeGravar = null): RegistroPonto
    {
        $registro = $this->batida($tipo, $hora, $data);
        if ($antesDeGravar !== null) {
            $antesDeGravar($registro);
        }
        (new \ReflectionProperty(RegistroPonto::class, 'id'))->setValue($registro, $id);

        return $registro;
    }
}
