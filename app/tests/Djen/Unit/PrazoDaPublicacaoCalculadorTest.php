<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\DTO\PrazoDaPublicacao;
use App\Djen\Service\PrazoDaPublicacaoCalculador;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Agenda\Service\CalendarioDoSistema;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Pasta\Service\CalculadoraDoPrazoDoPush;
use App\Pasta\Service\DeterminacoesDoJuizo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrazoDaPublicacaoCalculador::class)]
final class PrazoDaPublicacaoCalculadorTest extends TestCase
{
    private function calculador(): PrazoDaPublicacaoCalculador
    {
        return new PrazoDaPublicacaoCalculador(new CalculadoraDoPrazoDoPush(new DeterminacoesDoJuizo($this->createStub(PublicacaoDjenRepository::class))), new DiasUteisDoEscritorio(new CalendarioDoSistema()));
    }

    #[Test]
    public function semDiasNemPrazoDoTeorNaoHaPrazo(): void
    {
        self::assertNull($this->calculador()->calcular(new \DateTimeImmutable('2026-10-01'), null, null, false, 'SP', new \DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function prazoDoTeorUsaADataPronta(): void
    {
        $prazo = $this->calculador()->calcular(new \DateTimeImmutable('2026-10-01'), null, new \DateTimeImmutable('2026-10-30'), false, 'SP', new \DateTimeImmutable('2026-10-27'));

        self::assertSame(PrazoDaPublicacao::ORIGEM_TEOR, $prazo->origem);
        self::assertSame('2026-10-30', $prazo->vencimento->format('Y-m-d'));
        self::assertSame(3, $prazo->restante);
    }

    #[Test]
    public function diasEscolhidosVencemAntesDeHoje(): void
    {
        $prazo = $this->calculador()->calcular(new \DateTimeImmutable('2026-07-06'), 5, null, false, 'SP', new \DateTimeImmutable('2026-10-27'));

        self::assertTrue($prazo->vencido());
        self::assertSame(5, $prazo->dias);
    }

    #[Test]
    public function venceHojeTemRestanteZero(): void
    {
        $prazo = $this->calculador()->calcular(new \DateTimeImmutable('2026-10-01'), null, new \DateTimeImmutable('2026-10-27'), false, 'SP', new \DateTimeImmutable('2026-10-27'));

        self::assertSame(0, $prazo->restante);
    }
}
