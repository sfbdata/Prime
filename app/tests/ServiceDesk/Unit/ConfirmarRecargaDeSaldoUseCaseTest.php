<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\DTO\ConfirmarRecargaInput;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Entity\SaldoDoEscritorio;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Enum\ResultadoDaConfirmacao;
use App\ServiceDesk\Repository\MovimentoDeSaldoRepository;
use App\ServiceDesk\Repository\SaldoDoEscritorioRepository;
use App\ServiceDesk\Service\TransacaoDoRazao;
use App\ServiceDesk\UseCase\ConfirmarRecargaDeSaldoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfirmarRecargaDeSaldoUseCase::class)]
final class ConfirmarRecargaDeSaldoUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private SaldoDoEscritorio $saldo;
    private MovimentoDeSaldoRepository $movimentos;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->saldo = new SaldoDoEscritorio($this->tenant);
        $this->movimentos = $this->createMock(MovimentoDeSaldoRepository::class);
    }

    private function useCase(): ConfirmarRecargaDeSaldoUseCase
    {
        $saldos = $this->createStub(SaldoDoEscritorioRepository::class);
        $saldos->method('travarDoEscritorio')->willReturn($this->saldo);
        $transacao = new class () implements TransacaoDoRazao {
            public function emTransacao(\Closure $trabalho): mixed
            {
                return $trabalho();
            }
        };

        return new ConfirmarRecargaDeSaldoUseCase($this->movimentos, $saldos, $transacao);
    }

    private function recargaPendente(int $centavos = 5_000): MovimentoDeSaldo
    {
        $recarga = MovimentoDeSaldo::recargaPendente($this->tenant, $centavos, 'BJSDABC', FormaDeRecarga::Pix, 'Recarga', null);
        $this->movimentos->method('recargaPorReferenciaTravando')->with('BJSDABC')->willReturn($recarga);

        return $recarga;
    }

    public function testConfirmaECreditaOSaldoGravandoAOrigem(): void
    {
        $recarga = $this->recargaPendente();

        $out = $this->useCase()->executar(new ConfirmarRecargaInput('BJSDABC', 5_000, 'E2E1', 'cora'));

        self::assertSame(ResultadoDaConfirmacao::Confirmada, $out->resultado);
        self::assertTrue($out->creditou());
        self::assertSame(5_000, $this->saldo->getSaldoCentavos());
        self::assertFalse($recarga->estaPendente());
        self::assertSame('E2E1', $recarga->getIdExterno());
        self::assertSame('cora', $recarga->getOrigemDaConfirmacao());
    }

    public function testConfirmacaoManualGuardaOrigemManual(): void
    {
        $recarga = $this->recargaPendente();

        $this->useCase()->executar(new ConfirmarRecargaInput('BJSDABC', 5_000));

        self::assertSame('manual', $recarga->getOrigemDaConfirmacao());
    }

    public function testRepeticaoDoWebhookNaoCreditaDeNovo(): void
    {
        $this->recargaPendente();
        $useCase = $this->useCase();
        $useCase->executar(new ConfirmarRecargaInput('BJSDABC', 5_000, 'E2E1'));

        $segunda = $useCase->executar(new ConfirmarRecargaInput('BJSDABC', 5_000, 'E2E1'));

        self::assertSame(ResultadoDaConfirmacao::JaConfirmada, $segunda->resultado);
        self::assertSame(5_000, $this->saldo->getSaldoCentavos());
    }

    public function testValorPagoMenorNaoCreditaEGravaADivergencia(): void
    {
        $recarga = $this->recargaPendente();

        $out = $this->useCase()->executar(new ConfirmarRecargaInput('BJSDABC', 4_999, 'E2E1'));

        self::assertSame(ResultadoDaConfirmacao::ValorDivergente, $out->resultado);
        self::assertSame(0, $this->saldo->getSaldoCentavos());
        self::assertTrue($recarga->estaPendente());
        self::assertSame(4_999, $recarga->getValorRecebidoCentavos());
    }

    public function testValorPagoMaiorCreditaOPagoEDevolveADiferenca(): void
    {
        $recarga = $this->recargaPendente();

        $out = $this->useCase()->executar(new ConfirmarRecargaInput('BJSDABC', 5_300, 'E2E1'));

        self::assertSame(ResultadoDaConfirmacao::Confirmada, $out->resultado);
        self::assertSame(300, $out->diferencaCentavos);
        self::assertSame(5_300, $this->saldo->getSaldoCentavos());
        self::assertFalse($recarga->estaPendente());
        self::assertSame(5_300, $recarga->getValorRecebidoCentavos());
    }

    public function testValorExatoNaoTemDiferenca(): void
    {
        $this->recargaPendente();

        $out = $this->useCase()->executar(new ConfirmarRecargaInput('BJSDABC', 5_000, 'E2E1'));

        self::assertSame(0, $out->diferencaCentavos);
    }

    public function testMesmoPagamentoNaoConfirmaOutraRecarga(): void
    {
        $recarga = $this->recargaPendente();
        $this->movimentos->method('existeComIdExterno')->with('E2E1')->willReturn(true);

        $out = $this->useCase()->executar(new ConfirmarRecargaInput('BJSDABC', 5_000, 'E2E1'));

        self::assertSame(ResultadoDaConfirmacao::PagamentoRepetido, $out->resultado);
        self::assertSame(0, $this->saldo->getSaldoCentavos());
        self::assertTrue($recarga->estaPendente());
    }

    public function testReferenciaDesconhecidaNaoLancaExcecao(): void
    {
        $this->movimentos->method('recargaPorReferenciaTravando')->willReturn(null);

        $out = $this->useCase()->executar(new ConfirmarRecargaInput('NADA', 5_000));

        self::assertSame(ResultadoDaConfirmacao::NaoEncontrada, $out->resultado);
        self::assertNull($out->saldoCentavos);
    }
}
