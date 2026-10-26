<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\DTO\SolicitarRecargaInput;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Pagamento\CobrancaNaoCriada;
use App\ServiceDesk\Pagamento\InstrucaoDePagamento;
use App\ServiceDesk\Pagamento\PedidoDeRecarga;
use App\ServiceDesk\Pagamento\ProvedorDeRecarga;
use App\ServiceDesk\Repository\MovimentoDeSaldoRepository;
use App\ServiceDesk\UseCase\SolicitarRecargaDeSaldoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SolicitarRecargaDeSaldoUseCase::class)]
final class SolicitarRecargaDeSaldoUseCaseTest extends TestCase
{
    public function testGravaRecargaPendenteComAReferenciaQueOProvedorRecebeu(): void
    {
        $gravado = null;
        $movimentos = $this->createMock(MovimentoDeSaldoRepository::class);
        $movimentos->expects(self::once())->method('salvar')
            ->willReturnCallback(function (MovimentoDeSaldo $m, bool $flush) use (&$gravado): void {
                $gravado = $m;
                self::assertTrue($flush);
            });
        $recebido = null;
        $provedor = $this->createMock(ProvedorDeRecarga::class);
        $provedor->method('iniciar')->willReturnCallback(function (PedidoDeRecarga $p) use (&$recebido): InstrucaoDePagamento {
            $recebido = $p;

            return new InstrucaoDePagamento('000201...', 'Pague o PIX.');
        });

        $out = (new SolicitarRecargaDeSaldoUseCase($movimentos, $provedor))
            ->executar(new SolicitarRecargaInput(new Tenant(), null, 5_000, FormaDeRecarga::Pix));

        self::assertInstanceOf(MovimentoDeSaldo::class, $gravado);
        self::assertTrue($gravado->estaPendente());
        self::assertSame($recebido->referencia, $gravado->getReferencia());
        self::assertSame($recebido->referencia, $out->referencia);
        self::assertSame(5_000, $recebido->valorCentavos);
        self::assertSame(FormaDeRecarga::Pix, $recebido->forma);
        self::assertSame(FormaDeRecarga::Pix, $gravado->getForma());
        self::assertSame('000201...', $out->instrucao->copiaECola);
        self::assertMatchesRegularExpression('/^BJSD[0-9A-F]{12}$/', $out->referencia);
    }

    public function testProvedorQueFalhaDesfazOPendente(): void
    {
        $movimentos = $this->createMock(MovimentoDeSaldoRepository::class);
        $movimentos->expects(self::once())->method('salvar');
        $movimentos->expects(self::once())->method('remover');
        $provedor = $this->createStub(ProvedorDeRecarga::class);
        $provedor->method('iniciar')->willThrowException(new CobrancaNaoCriada('credencial ausente'));

        $this->expectException(CobrancaNaoCriada::class);
        (new SolicitarRecargaDeSaldoUseCase($movimentos, $provedor))
            ->executar(new SolicitarRecargaInput(new Tenant(), null, 5_000, FormaDeRecarga::Pix));
    }

    public function testFalhaAmbiguaDoProvedorMantemOPendente(): void
    {
        $movimentos = $this->createMock(MovimentoDeSaldoRepository::class);
        $movimentos->expects(self::once())->method('salvar');
        $movimentos->expects(self::never())->method('remover');
        $provedor = $this->createStub(ProvedorDeRecarga::class);
        $provedor->method('iniciar')->willThrowException(new \RuntimeException('timeout'));

        $this->expectException(\RuntimeException::class);
        (new SolicitarRecargaDeSaldoUseCase($movimentos, $provedor))
            ->executar(new SolicitarRecargaInput(new Tenant(), null, 5_000, FormaDeRecarga::Pix));
    }

    public function testValorAbaixoDoMinimoNemGravaNemChegaAoProvedor(): void
    {
        $provedor = $this->createMock(ProvedorDeRecarga::class);
        $provedor->expects(self::never())->method('iniciar');

        $this->expectException(\DomainException::class);
        (new SolicitarRecargaDeSaldoUseCase($this->createStub(MovimentoDeSaldoRepository::class), $provedor))
            ->executar(new SolicitarRecargaInput(new Tenant(), null, 1_999, FormaDeRecarga::Pix));
    }

    public function testRecusaQuandoHaPendentesDemais(): void
    {
        $movimentos = $this->createMock(MovimentoDeSaldoRepository::class);
        $movimentos->method('quantasRecargasPendentesDoEscritorio')->willReturn(SolicitarRecargaDeSaldoUseCase::MAXIMO_DE_PENDENTES);
        $movimentos->expects(self::never())->method('salvar');
        $provedor = $this->createMock(ProvedorDeRecarga::class);
        $provedor->expects(self::never())->method('iniciar');

        $this->expectException(\DomainException::class);
        (new SolicitarRecargaDeSaldoUseCase($movimentos, $provedor))
            ->executar(new SolicitarRecargaInput(new Tenant(), null, 5_000, FormaDeRecarga::Pix));
    }
}
