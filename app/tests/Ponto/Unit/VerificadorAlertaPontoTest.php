<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\Service\VerificadorAlertaPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerificadorAlertaPonto::class)]
final class VerificadorAlertaPontoTest extends TestCase
{
    private MockObject $jornadaResolver;
    private MockObject $registroRepository;
    private MockObject $user;
    private VerificadorAlertaPonto $sut;

    protected function setUp(): void
    {
        $this->jornadaResolver    = $this->createMock(JornadaResolver::class);
        $this->registroRepository = $this->createMock(RegistroPontoRepository::class);
        $this->user               = $this->createMock(User::class);
        $this->sut = new VerificadorAlertaPonto($this->jornadaResolver, $this->registroRepository);
    }

    private function criarBatida(string $tipo, string $horario, string $data = '2026-04-30'): RegistroPonto
    {
        $batida = $this->createMock(RegistroPonto::class);
        $batida->method('getTipo')->willReturn($tipo);
        $batida->method('getDataHora')->willReturn(new \DateTime($data . ' ' . $horario));

        return $batida;
    }

    public function testRetornaFalseQuandoAlertaDesabilitado(): void
    {
        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(false);

        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 08:00:00'), null);

        self::assertFalse($resultado['alertar']);
    }

    public function testAlertaEntradaQuandoNaoRegistradaHoje(): void
    {
        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([]);

        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 14:00:00'), null);

        self::assertTrue($resultado['alertar']);
        self::assertSame('entrada', $resultado['tipo']);
    }

    public function testAlertaRepousoPorHorarioConfiguradoDentroDeTolerancia(): void
    {
        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([
            $this->criarBatida(RegistroPonto::TIPO_ENTRADA, '08:00:00'),
        ]);
        $this->jornadaResolver->method('resolverBatidasEsperadasHoje')->willReturn([
            ['horario' => '12:00', 'tipo' => 'repouso', 'mensagem' => 'Hora de registrar o repouso!'],
        ]);

        // 12:02 — dentro de ±5 min do repouso configurado (12:00)
        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 12:02:00'), null);

        self::assertTrue($resultado['alertar']);
        self::assertSame('repouso', $resultado['tipo']);
        self::assertSame('12:00', $resultado['horario']);
    }

    public function testAlertaAviso6hCltApos6HorasContínuasSemRepouso(): void
    {
        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([
            $this->criarBatida(RegistroPonto::TIPO_ENTRADA, '08:00:00'),
        ]);
        // Repouso configurado às 12:00, mas agora é 14:01 (fora da janela ±5 min)
        $this->jornadaResolver->method('resolverBatidasEsperadasHoje')->willReturn([
            ['horario' => '12:00', 'tipo' => 'repouso', 'mensagem' => 'Hora de registrar o repouso!'],
        ]);

        // 14:01 — 361 min desde entrada às 08:00 → supera 360 min (6h CLT)
        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 14:01:00'), null);

        self::assertTrue($resultado['alertar']);
        self::assertSame('aviso_repouso_6h', $resultado['tipo']);
    }

    public function testHorarioRepousoTemPrioridadeSobre6hQuandoAmbosAtivos(): void
    {
        // Repouso configurado às 14:00; usuário entrou às 08:00 → exatamente 6h às 14:00
        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([
            $this->criarBatida(RegistroPonto::TIPO_ENTRADA, '08:00:00'),
        ]);
        $this->jornadaResolver->method('resolverBatidasEsperadasHoje')->willReturn([
            ['horario' => '14:00', 'tipo' => 'repouso', 'mensagem' => 'Hora de registrar o repouso!'],
        ]);

        // 14:00 — simultaneamente 6h desde entrada E dentro de ±5 min do repouso configurado
        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 14:00:00'), null);

        self::assertTrue($resultado['alertar']);
        self::assertSame('repouso', $resultado['tipo']); // schedule ganha
    }

    public function testAlertaRetornoQuandoIntervaloMinimoAtingido(): void
    {
        $jornadaTenant = $this->createMock(JornadaTenant::class);
        $jornadaTenant->method('getMinimoMinutosRepouso')->willReturn(60);

        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([
            $this->criarBatida(RegistroPonto::TIPO_ENTRADA, '08:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_REPOUSO, '12:00:00'),
        ]);

        // 13:01 — 61 min desde repouso às 12:00 → supera mínimo de 60 min
        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 13:01:00'), $jornadaTenant);

        self::assertTrue($resultado['alertar']);
        self::assertSame('retorno', $resultado['tipo']);
    }

    public function testSemAlertaRetornoAntesDoIntervaloMinimo(): void
    {
        $jornadaTenant = $this->createMock(JornadaTenant::class);
        $jornadaTenant->method('getMinimoMinutosRepouso')->willReturn(60);

        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([
            $this->criarBatida(RegistroPonto::TIPO_ENTRADA, '08:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_REPOUSO, '12:00:00'),
        ]);

        // 12:30 — apenas 30 min desde repouso, mínimo é 60 min
        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 12:30:00'), $jornadaTenant);

        self::assertFalse($resultado['alertar']);
    }

    public function testAlertaSaidaComRepousoMaiorQueMinimo(): void
    {
        // Entrada 08:00, repouso 12:00, retorno 13:30 (90 min de repouso)
        // Jornada = 8h (480 min); trabalhado antes do repouso = 4h; restante = 4h
        // Saída prevista = 13:30 + 4h = 17:30
        $jornadaTenant = $this->createMock(JornadaTenant::class);

        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([
            $this->criarBatida(RegistroPonto::TIPO_ENTRADA, '08:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_REPOUSO, '12:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_RETORNO, '13:30:00'),
        ]);
        $this->jornadaResolver->method('resolverMetaDia')->willReturn(480);

        // 17:30 — anteRepouso=240, aposRetorno=240, total=480 >= 480
        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 17:30:00'), $jornadaTenant);

        self::assertTrue($resultado['alertar']);
        self::assertSame('saida', $resultado['tipo']);
    }

    public function testSemAlertaSaidaQuandoJornadaIncompleta(): void
    {
        $jornadaTenant = $this->createMock(JornadaTenant::class);

        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([
            $this->criarBatida(RegistroPonto::TIPO_ENTRADA, '08:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_REPOUSO, '12:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_RETORNO, '13:30:00'),
        ]);
        $this->jornadaResolver->method('resolverMetaDia')->willReturn(480);

        // 17:00 — anteRepouso=240, aposRetorno=210, total=450 < 480
        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 17:00:00'), $jornadaTenant);

        self::assertFalse($resultado['alertar']);
    }

    public function testSemAlertaQuandoTodasBatidasRegistradas(): void
    {
        $this->jornadaResolver->method('resolverAlertaHabilitado')->willReturn(true);
        $this->registroRepository->method('findBatidasDoDia')->willReturn([
            $this->criarBatida(RegistroPonto::TIPO_ENTRADA, '08:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_REPOUSO, '12:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_RETORNO, '13:00:00'),
            $this->criarBatida(RegistroPonto::TIPO_SAIDA, '17:00:00'),
        ]);

        $resultado = $this->sut->verificar($this->user, new \DateTimeImmutable('2026-04-30 17:05:00'), null);

        self::assertFalse($resultado['alertar']);
    }
}
