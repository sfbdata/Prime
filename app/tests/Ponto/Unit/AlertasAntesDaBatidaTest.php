<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\AlertasAntesDaBatida;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\IntervaloDaBatida;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Os alertas que não bloqueiam (spec docs/specs/ponto-desenho-0811.md, P21 e E11): o texto exato de cada alerta com os
 * mínimos da Jornada do escritório (aqui 30 minutos de intervalo e 8 horas de interjornada, não o 1h e 11h fixos do
 * desenho), o 7º dia seguido, as validações desligadas e a chave `ponto-alertas` desligada (a recusa de antes).
 */
#[CoversClass(AlertasAntesDaBatida::class)]
#[CoversClass(IntervaloDaBatida::class)]
#[Group('ponto')]
final class AlertasAntesDaBatidaTest extends TestCase
{
    private const MOMENTO = '2026-11-10 13:40:00';

    private User $user;
    private Tenant $tenant;
    private JornadaTenant $jornada;
    private RegistroPontoRepository&Stub $registros;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->tenant = new Tenant();
        $this->jornada = new JornadaTenant();
        $this->jornada->setTenant($this->tenant);
        $this->jornada->setMinimoMinutosRepouso(30);
        $this->jornada->setMinimoMinutosInterjornada(480);
        $this->tenant->setJornadaTenant($this->jornada);
        $this->registros = $this->createStub(RegistroPontoRepository::class);
    }

    #[TestDox('O mínimo sai no formato do aviso do cartão: 1h, 30min, 1h30, 11h')]
    public function testDuracao(): void
    {
        self::assertSame(['1h', '30min', '1h30', '11h', '8h'], array_map(AlertasAntesDaBatida::duracao(...), [60, 30, 90, 660, 480]));
    }

    #[TestDox('O que falta sai no formato do desenho (ft): minutos para cima, "2h10" a partir de 60, "1 minuto" no singular')]
    public function testTempoQueFalta(): void
    {
        self::assertSame('18 minutos', AlertasAntesDaBatida::tempoQueFalta(18 * 60));
        self::assertSame('1 minuto', AlertasAntesDaBatida::tempoQueFalta(60));
        self::assertSame('1 minuto', AlertasAntesDaBatida::tempoQueFalta(1));
        self::assertSame('1h00', AlertasAntesDaBatida::tempoQueFalta(59 * 60 + 1));
        self::assertSame('2h10', AlertasAntesDaBatida::tempoQueFalta(130 * 60));
    }

    #[TestDox('Só o true do JSON confirma o alerta')]
    public function testConfirmou(): void
    {
        self::assertTrue(AlertasAntesDaBatida::confirmou(['confirmarAlerta' => true]));
        self::assertFalse(AlertasAntesDaBatida::confirmou(['confirmarAlerta' => 'true']));
        self::assertFalse(AlertasAntesDaBatida::confirmou(['confirmarAlerta' => 1]));
        self::assertFalse(AlertasAntesDaBatida::confirmou([]));
    }

    #[TestDox('Retorno 12 minutos depois do repouso, com o intervalo de 30 minutos do escritório: o texto exato do alerta')]
    public function testAlertaDoIntervalo(): void
    {
        $this->registros->method('findRepousoDoDia')->willReturn($this->batida(RegistroPonto::TIPO_REPOUSO, '2026-11-10 13:28:00'));

        $intervalo = $this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_RETORNO, $this->momento());

        self::assertSame('Faltam 18 minutos para completar o intervalo mínimo de 30min. Se registrar agora, a folha marcará o intervalo intrajornada como não conforme.', $intervalo->alerta);
        self::assertNull($intervalo->recusa);
        self::assertNull($intervalo->avisoDepois);
    }

    #[TestDox('Retorno com o intervalo do escritório cumprido: nada avisa')]
    public function testIntervaloCumprido(): void
    {
        $this->registros->method('findRepousoDoDia')->willReturn($this->batida(RegistroPonto::TIPO_REPOUSO, '2026-11-10 13:10:00'));

        self::assertEquals(IntervaloDaBatida::livre(), $this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_RETORNO, $this->momento()));
    }

    #[TestDox('Entrada 7 horas depois da saída de ontem, com a interjornada de 8 horas do escritório: o texto exato do alerta')]
    public function testAlertaDaInterjornada(): void
    {
        $this->registros->method('ultimaSaidaQueFechouTurno')->willReturn($this->batida(RegistroPonto::TIPO_SAIDA, '2026-11-09 22:00:00'));

        $intervalo = $this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, new \DateTimeImmutable('2026-11-10 05:00:00'));

        self::assertSame('Faltam 1h00 para completar 8h de descanso desde a saída de ontem. Se registrar agora, a folha marcará a interjornada como não conforme.', $intervalo->alerta);
        self::assertNull($intervalo->recusa);
    }

    #[TestDox('A saída do mesmo dia diz "de hoje"; a de antes de ontem, a data')]
    public function testDiaDaSaida(): void
    {
        $this->registros->method('ultimaSaidaQueFechouTurno')->willReturnOnConsecutiveCalls(
            $this->batida(RegistroPonto::TIPO_SAIDA, '2026-11-10 12:00:00'),
            $this->batida(RegistroPonto::TIPO_SAIDA, '2026-11-08 23:00:00'),
        );
        $this->jornada->setMinimoMinutosInterjornada(2400);   // 40 h, para a saída de antes de ontem ainda contar

        $deHoje = $this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento());
        $daData = $this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento());

        self::assertStringContainsString('completar 40h de descanso desde a saída de hoje.', (string) $deHoje->alerta);
        self::assertStringContainsString('completar 40h de descanso desde a saída de 08/11.', (string) $daData->alerta);
    }

    #[TestDox('7º dia seguido: seis dias antes de hoje com registro, o texto exato do alerta')]
    public function testSetimoDiaAvisa(): void
    {
        $this->registros->method('batidasDoPeriodoNoEscritorio')->willReturn($this->diasAntesDeHoje(6));

        $intervalo = $this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento());

        self::assertSame('Este será o 7º dia seguido com registro, sem o descanso semanal.', $intervalo->alerta);
    }

    #[TestDox('6º dia seguido: cinco dias antes de hoje com registro, nada avisa')]
    public function testSextoDiaNaoAvisa(): void
    {
        $this->registros->method('batidasDoPeriodoNoEscritorio')->willReturn($this->diasAntesDeHoje(5));

        self::assertNull($this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento())->alerta);
    }

    #[TestDox('8º dia seguido: o número acompanha a sequência')]
    public function testOitavoDia(): void
    {
        $this->registros->method('batidasDoPeriodoNoEscritorio')->willReturn($this->diasAntesDeHoje(7));

        self::assertSame('Este será o 8º dia seguido com registro, sem o descanso semanal.', $this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento())->alerta);
    }

    #[TestDox('Um dia sem registro no meio quebra a sequência')]
    public function testDiaSemRegistroQuebraASequencia(): void
    {
        $batidas = $this->diasAntesDeHoje(7);
        unset($batidas[2]);   // anteontem menos um: o 3º dia antes de hoje sem batida
        $this->registros->method('batidasDoPeriodoNoEscritorio')->willReturn(array_values($batidas));

        self::assertNull($this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento())->alerta);
    }

    #[TestDox('A entrada do segundo turno (hoje já tem registro) não repete o alerta do 7º dia')]
    public function testSegundoTurnoNaoRepete(): void
    {
        $batidas = $this->diasAntesDeHoje(6);
        $batidas[] = $this->batida(RegistroPonto::TIPO_ENTRADA, '2026-11-10 08:00:00');
        $this->registros->method('batidasDoPeriodoNoEscritorio')->willReturn($batidas);

        self::assertNull($this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento())->alerta);
    }

    #[TestDox('A interjornada vem antes do 7º dia: só o primeiro alerta vai à janela')]
    public function testInterjornadaAntesDoSetimoDia(): void
    {
        $this->registros->method('ultimaSaidaQueFechouTurno')->willReturn($this->batida(RegistroPonto::TIPO_SAIDA, '2026-11-10 12:00:00'));
        $this->registros->method('batidasDoPeriodoNoEscritorio')->willReturn($this->diasAntesDeHoje(6));

        self::assertStringStartsWith('Faltam 6h20 para completar 8h de descanso', (string) $this->alertas()->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento())->alerta);
    }

    #[TestDox('Validações do intervalo e da interjornada desligadas na Jornada do escritório: nada avisa, nem o 7º dia')]
    public function testValidacoesDesligadas(): void
    {
        $this->jornada->setValidacaoRepousoHabilitada(false);
        $this->jornada->setValidacaoInterjornadaHabilitada(false);
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->expects(self::never())->method('findRepousoDoDia');
        $registros->expects(self::never())->method('ultimaSaidaQueFechouTurno');
        $registros->expects(self::never())->method('batidasDoPeriodoNoEscritorio');

        $alertas = $this->alertas(true, $registros);

        self::assertEquals(IntervaloDaBatida::livre(), $alertas->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_RETORNO, $this->momento()));
        self::assertEquals(IntervaloDaBatida::livre(), $alertas->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento()));
    }

    #[TestDox('Chave ponto-alertas desligada: a recusa de antes da E11, com o mínimo do escritório; o 7º dia não é olhado')]
    public function testChaveDesligadaRecusa(): void
    {
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->method('findRepousoDoDia')->willReturn($this->batida(RegistroPonto::TIPO_REPOUSO, '2026-11-10 13:28:00'));
        $registros->method('ultimaSaidaQueFechouTurno')->willReturnOnConsecutiveCalls(
            $this->batida(RegistroPonto::TIPO_SAIDA, '2026-11-09 22:00:00'),
            null,
        );
        $registros->expects(self::never())->method('batidasDoPeriodoNoEscritorio');
        $alertas = $this->alertas(false, $registros);

        $retorno = $alertas->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_RETORNO, $this->momento());
        $entrada = $alertas->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, new \DateTimeImmutable('2026-11-10 05:00:00'));
        $semSaida = $alertas->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento());

        self::assertSame('Intervalo mínimo de repouso é de 30 minuto(s). Aguarde mais 18 minuto(s).', $retorno->recusa);
        self::assertNull($retorno->alerta);
        self::assertSame('Interjornada mínima é de 8h. Aguarde mais 1h00m.', $entrada->recusa);
        self::assertNull($entrada->alerta);
        self::assertEquals(IntervaloDaBatida::livre(), $semSaida);
    }

    #[TestDox('Saída vinda de esquecimento (R4): sem alerta nem recusa, a batida grava e avisa depois')]
    public function testSaidaDeEsquecimentoSoAvisaDepois(): void
    {
        $saida = $this->batida(RegistroPonto::TIPO_SAIDA, '2026-11-10 10:40:00');
        $saida->setObservacao(RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO);
        $this->registros->method('ultimaSaidaQueFechouTurno')->willReturn($saida);
        $justificativas = $this->createStub(JustificativaPontoRepository::class);
        $justificativas->method('existeEsquecimentoAbonado')->willReturn(true);

        foreach ([true, false] as $chave) {
            $intervalo = $this->alertas($chave, null, $justificativas)->avaliar($this->user, $this->tenant, RegistroPonto::TIPO_ENTRADA, $this->momento());

            self::assertNull($intervalo->alerta);
            self::assertNull($intervalo->recusa);
            self::assertSame(sprintf(SaidaDeEsquecimento::AVISO_INTERJORNADA, '10/11', '10:40', 8), $intervalo->avisoDepois);
        }
    }

    #[TestDox('Repouso e saída não passam pelas regras de intervalo')]
    public function testOutrosTiposLivres(): void
    {
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->expects(self::never())->method(self::anything());

        foreach ([RegistroPonto::TIPO_REPOUSO, RegistroPonto::TIPO_SAIDA] as $tipo) {
            self::assertEquals(IntervaloDaBatida::livre(), $this->alertas(true, $registros)->avaliar($this->user, $this->tenant, $tipo, $this->momento()));
        }
    }

    // ----------------------------------------------------------------- apoio

    private function momento(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::MOMENTO);
    }

    private function batida(string $tipo, string $quando): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->user);
        $registro->setTenant($this->tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($quando));

        return $registro;
    }

    /** @return list<RegistroPonto> uma entrada às 08:00 em cada um dos `$dias` dias antes de hoje, de ontem para trás */
    private function diasAntesDeHoje(int $dias): array
    {
        $batidas = [];
        for ($d = 1; $d <= $dias; ++$d) {
            $batidas[] = $this->batida(RegistroPonto::TIPO_ENTRADA, $this->momento()->modify(sprintf('-%d days', $d))->format('Y-m-d') . ' 08:00:00');
        }

        return $batidas;
    }

    private function alertas(bool $chaveLigada = true, ?RegistroPontoRepository $registros = null, ?JustificativaPontoRepository $justificativas = null): AlertasAntesDaBatida
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $gravados = [];
        if (!$chaveLigada) {
            $gravados[FuncoesDoPonto::ALERTAS] = new RecursoEstado(FuncoesDoPonto::ALERTAS);
            $gravados[FuncoesDoPonto::ALERTAS]->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));
        }
        $estados->method('todosPorChave')->willReturn($gravados);
        $regra = new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class));
        $recursos = new RecursosDoSistema($estados, new MockClock(self::MOMENTO), $this->createStub(Security::class), $regra, new RequestStack(), new NullLogger());
        $funcoes = new FuncoesDoPonto($recursos, $this->createStub(ConfiguracaoDoPontoRepository::class), $this->createStub(PermissionChecker::class));
        $esquecimento = new SaidaDeEsquecimento($justificativas ?? $this->createStub(JustificativaPontoRepository::class));

        return new AlertasAntesDaBatida($registros ?? $this->registros, $esquecimento, $funcoes);
    }
}
