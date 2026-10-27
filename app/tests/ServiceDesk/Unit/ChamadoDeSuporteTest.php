<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\StatusDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChamadoDeSuporte::class)]
final class ChamadoDeSuporteTest extends TestCase
{
    private function abrir(TipoDeChamado $tipo, string $assunto = 'Impressora', ?\DateTimeImmutable $data = null, ?string $hora = null): ChamadoDeSuporte
    {
        return ChamadoDeSuporte::abrir(new Tenant(), 1, null, $tipo, PrioridadeDoChamado::Media, $assunto, ' texto ', $data, $hora, null);
    }

    public function testNasceAbertoSemDebito(): void
    {
        $c = $this->abrir(TipoDeChamado::Suporte);

        self::assertSame(StatusDoChamado::Aberto, $c->getStatus());
        self::assertNull($c->getValorDebitadoCentavos());
        self::assertSame('texto', $c->getDescricao());
    }

    public function testAssuntoVazioEhRecusado(): void
    {
        $this->expectExceptionMessage('Informe o assunto.');
        $this->abrir(TipoDeChamado::Suporte, '   ');
    }

    public function testTreinamentoExigeDataEHorario(): void
    {
        $this->expectExceptionMessage('Escolha a data e o horário do agendamento.');
        $this->abrir(TipoDeChamado::Agenda);
    }

    public function testHorarioForaDoFormatoEhRecusado(): void
    {
        $this->expectExceptionMessage('Informe o horário no formato HH:MM.');
        $this->abrir(TipoDeChamado::Agenda, 'Treino', new \DateTimeImmutable('2026-11-03'), '25:99');
    }

    public function testTreinamentoGuardaDataEHorario(): void
    {
        $c = $this->abrir(TipoDeChamado::Agenda, 'Treino', new \DateTimeImmutable('2026-11-03 14:20'), '14:00');

        self::assertSame('2026-11-03', $c->getDataAgendada()?->format('Y-m-d'));
        self::assertSame('14:00', $c->getHoraAgendada());
    }

    public function testSuporteDescartaAgendamentoInformadoSemQuerer(): void
    {
        $c = $this->abrir(TipoDeChamado::Suporte, 'Dúvida', new \DateTimeImmutable('2026-11-03'), '14:00');

        self::assertNull($c->getDataAgendada());
        self::assertNull($c->getHoraAgendada());
    }

    public function testAssuntoLongoEhCortadoEm255(): void
    {
        self::assertSame(255, mb_strlen($this->abrir(TipoDeChamado::Suporte, str_repeat('a', 300))->getAssunto()));
    }
}
