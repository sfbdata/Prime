<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Enum\TipoDeMovimento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MovimentoDeSaldo::class)]
final class MovimentoDeSaldoTest extends TestCase
{
    public function testRecargaNasceCreditoPendente(): void
    {
        $m = MovimentoDeSaldo::recargaPendente(new Tenant(), 5_000, 'BJSDABC', FormaDeRecarga::Pix, 'Recarga', null);

        self::assertSame(TipoDeMovimento::Credito, $m->getTipo());
        self::assertTrue($m->estaPendente());
        self::assertSame('BJSDABC', $m->getReferencia());
    }

    public function testConfirmarGravaOIdExternoEFechaAPendencia(): void
    {
        $m = MovimentoDeSaldo::recargaPendente(new Tenant(), 5_000, 'BJSDABC', FormaDeRecarga::Pix, 'Recarga', null);
        $m->confirmar('E2E123', 'cora', null);

        self::assertFalse($m->estaPendente());
        self::assertSame('E2E123', $m->getIdExterno());
        self::assertSame('cora', $m->getOrigemDaConfirmacao());
        self::assertSame(FormaDeRecarga::Pix, $m->getForma());
    }

    public function testNaoConfirmaDuasVezes(): void
    {
        $m = MovimentoDeSaldo::recargaPendente(new Tenant(), 5_000, 'BJSDABC', FormaDeRecarga::Pix, 'Recarga', null);
        $m->confirmar(null, 'manual', null);

        $this->expectException(\DomainException::class);
        $m->confirmar(null, 'manual', null);
    }

    #[DataProvider('recargasForaDaFaixa')]
    public function testRecargaForaDaFaixaEhRecusada(int $centavos): void
    {
        $this->expectException(\DomainException::class);
        MovimentoDeSaldo::recargaPendente(new Tenant(), $centavos, 'BJSDABC', FormaDeRecarga::Pix, 'Recarga', null);
    }

    /** @return iterable<string, array{int}> */
    public static function recargasForaDaFaixa(): iterable
    {
        yield 'abaixo de R$ 20,00' => [1_999];
        yield 'acima de R$ 10.000,00' => [1_000_001];
    }

    public function testDivergenciaGravaOValorRecebidoEMantemPendente(): void
    {
        $m = MovimentoDeSaldo::recargaPendente(new Tenant(), 5_000, 'BJSDABC', FormaDeRecarga::Pix, 'Recarga', null);
        $m->registrarDivergencia(4_000);

        self::assertTrue($m->estaPendente());
        self::assertTrue($m->temDivergencia());
        self::assertSame(4_000, $m->getValorRecebidoCentavos());
    }

    public function testDebitoEReembolsoNascemConfirmados(): void
    {
        $debito = MovimentoDeSaldo::debitoDeChamado(new Tenant(), 2_000, 7, 'Chamado #0007', null);
        $reembolso = MovimentoDeSaldo::reembolsoDeChamado(new Tenant(), 2_000, 7, 'Chamado #0007', null);

        self::assertFalse($debito->estaPendente());
        self::assertFalse($reembolso->estaPendente());
        self::assertSame(-1, $debito->getTipo()->sinal());
        self::assertSame(1, $reembolso->getTipo()->sinal());
    }
}
