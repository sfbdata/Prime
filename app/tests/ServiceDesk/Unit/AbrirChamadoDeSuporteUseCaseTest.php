<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\DTO\AbrirChamadoInput;
use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Entity\PrecoDoAtendimento;
use App\ServiceDesk\Entity\SaldoDoEscritorio;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;
use App\ServiceDesk\Exception\SaldoInsuficiente;
use App\ServiceDesk\Repository\ChamadoDeSuporteRepository;
use App\ServiceDesk\Repository\HistoricoDoChamadoRepository;
use App\ServiceDesk\Repository\PrecoDoAtendimentoRepository;
use App\ServiceDesk\Repository\SaldoDoEscritorioRepository;
use App\ServiceDesk\Service\TransacaoDoRazao;
use App\ServiceDesk\UseCase\AbrirChamadoDeSuporteUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbrirChamadoDeSuporteUseCase::class)]
final class AbrirChamadoDeSuporteUseCaseTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
    }

    private function useCase(int $saldoCentavos, ?PrecoDoAtendimento $preco, ?ChamadoDeSuporte &$gravado = null): AbrirChamadoDeSuporteUseCase
    {
        $saldo = new SaldoDoEscritorio($this->tenant);
        if ($saldoCentavos > 0) {
            $saldo->creditar($saldoCentavos);
        }
        $saldos = $this->createStub(SaldoDoEscritorioRepository::class);
        $saldos->method('travarDoEscritorio')->willReturn($saldo);
        $chamados = $this->createMock(ChamadoDeSuporteRepository::class);
        $chamados->method('proximoNumero')->willReturn(7);
        $chamados->method('salvar')->willReturnCallback(function (ChamadoDeSuporte $c) use (&$gravado): void {
            $gravado = $c;
        });
        $precos = $this->createStub(PrecoDoAtendimentoRepository::class);
        $precos->method('doTipo')->willReturn($preco);
        $transacao = new class () implements TransacaoDoRazao {
            public function emTransacao(\Closure $trabalho): mixed
            {
                return $trabalho();
            }
        };

        return new AbrirChamadoDeSuporteUseCase($chamados, $this->createStub(HistoricoDoChamadoRepository::class), $precos, $saldos, $transacao);
    }

    private function pedido(TipoDeChamado $tipo = TipoDeChamado::Suporte, string $assunto = 'Impressora'): AbrirChamadoInput
    {
        return new AbrirChamadoInput($this->tenant, null, $tipo, PrioridadeDoChamado::Alta, $assunto, '');
    }

    public function testAbreComONumeroEOPrecoConfigurado(): void
    {
        $gravado = null;
        $out = $this->useCase(2_000, new PrecoDoAtendimento(TipoDeChamado::Suporte, 2_500), $gravado)->executar($this->pedido());

        self::assertSame(7, $out->numero);
        self::assertSame('#0007', $out->numeroFormatado());
        self::assertSame(2_500, $gravado?->getValorEstimadoCentavos());
    }

    public function testSemPrecoDefinidoNaoHaEstimativa(): void
    {
        $gravado = null;
        $this->useCase(2_000, null, $gravado)->executar($this->pedido());

        self::assertNull($gravado?->getValorEstimadoCentavos());
    }

    public function testSaldoAbaixoDeVinteReaisNaoAbre(): void
    {
        $gravado = null;
        try {
            $this->useCase(1_999, null, $gravado)->executar($this->pedido());
            self::fail('Esperava SaldoInsuficiente');
        } catch (SaldoInsuficiente $e) {
            self::assertSame('Saldo insuficiente para abrir o chamado.', $e->getMessage());
            self::assertNull($gravado);
        }
    }

    public function testAssuntoVazioFalhaAntesDeTravarOSaldo(): void
    {
        $saldos = $this->createMock(SaldoDoEscritorioRepository::class);
        $saldos->expects(self::never())->method('travarDoEscritorio');
        $transacao = $this->createMock(TransacaoDoRazao::class);
        $transacao->expects(self::never())->method('emTransacao');
        $useCase = new AbrirChamadoDeSuporteUseCase(
            $this->createStub(ChamadoDeSuporteRepository::class),
            $this->createStub(HistoricoDoChamadoRepository::class),
            $this->createStub(PrecoDoAtendimentoRepository::class),
            $saldos,
            $transacao,
        );

        $this->expectExceptionMessage('Informe o assunto.');
        $useCase->executar($this->pedido(assunto: ' '));
    }
}
