<?php

declare(strict_types=1);

namespace App\Tests\Pagamento\Unit;

use App\Entity\Tenant\Tenant;
use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Enum\MetodoDePagamento;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\ResultadoDaConfirmacao;
use App\Pagamento\Enum\StatusDoPagamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pagamento::class)]
final class PagamentoTest extends TestCase
{
    private function cobranca(int $valor = 150_000, ?\DateTimeImmutable $expiraEm = null): Pagamento
    {
        return new Pagamento(new Tenant(), OrigemDoPagamento::TrabalhoExterno, 7, 'TE0042-1', $valor, MetodoDePagamento::Pix, 'cora', $expiraEm);
    }

    private function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-27 10:00:00');
    }

    #[TestDox('Valor exato: paga, grava quem confirmou, quando e quanto')]
    public function testValorExato(): void
    {
        $p = $this->cobranca();

        self::assertSame(ResultadoDaConfirmacao::Confirmado, $p->confirmar(150_000, 'E123', $this->agora(), 'banco'));
        self::assertSame(StatusDoPagamento::Paga, $p->getStatus());
        self::assertSame(150_000, $p->getValorPagoCentavos());
        self::assertSame('E123', $p->getEndToEndId());
        self::assertSame('banco', $p->getConfirmadoPor());
        self::assertEquals($this->agora(), $p->getPagoEm());
    }

    #[TestDox('Valor maior: libera e o resultado diz que houve diferença')]
    public function testValorMaior(): void
    {
        $p = $this->cobranca();

        self::assertSame(ResultadoDaConfirmacao::ConfirmadoComDiferenca, $p->confirmar(150_500, 'E1', $this->agora(), 'banco'));
        self::assertSame(StatusDoPagamento::Paga, $p->getStatus());
        self::assertTrue(ResultadoDaConfirmacao::ConfirmadoComDiferenca->liberaOConsumidor());
    }

    #[TestDox('Valor menor: fica divergente e NÃO libera')]
    public function testValorMenor(): void
    {
        $p = $this->cobranca();

        self::assertSame(ResultadoDaConfirmacao::ValorMenor, $p->confirmar(149_999, 'E1', $this->agora(), 'banco'));
        self::assertSame(StatusDoPagamento::Divergente, $p->getStatus());
        self::assertFalse(ResultadoDaConfirmacao::ValorMenor->liberaOConsumidor());
        self::assertNull($p->getConfirmadoPor());
    }

    #[TestDox('Segunda confirmação não muda nada (idempotente)')]
    public function testIdempotente(): void
    {
        $p = $this->cobranca();
        $p->confirmar(150_000, 'E1', $this->agora(), 'banco');

        self::assertSame(ResultadoDaConfirmacao::JaConfirmado, $p->confirmar(999_999, 'E2', $this->agora()->modify('+1 day'), 'banco'));
        self::assertSame(150_000, $p->getValorPagoCentavos());
        self::assertSame('E1', $p->getEndToEndId());
    }

    /** @return iterable<string, array{string}> */
    public static function inativas(): iterable
    {
        yield 'cancelada' => ['cancelar'];
        yield 'expirada' => ['expirar'];
    }

    #[DataProvider('inativas')]
    #[TestDox('Dinheiro numa cobrança $acao: vira divergente para o Master, sem liberar')]
    public function testCobrancaInativaQueRecebe(string $acao): void
    {
        $p = $this->cobranca(150_000, $this->agora()->modify('-1 hour'));
        $acao === 'cancelar' ? $p->cancelar() : $p->expirar($this->agora());

        self::assertSame(ResultadoDaConfirmacao::CobrancaInativa, $p->confirmar(150_000, 'E9', $this->agora(), 'banco'));
        self::assertSame(StatusDoPagamento::Divergente, $p->getStatus());
        self::assertSame(150_000, $p->getValorPagoCentavos());
    }

    #[TestDox('O Master aceita a divergência com motivo: vira paga e registra quem')]
    public function testAceitarDivergencia(): void
    {
        $p = $this->cobranca();
        $p->confirmar(100_000, 'E1', $this->agora(), 'banco');

        $p->aceitarDivergencia('  Cliente pagou o resto em dinheiro  ', 15);

        self::assertSame(StatusDoPagamento::Paga, $p->getStatus());
        self::assertSame('manual:15', $p->getConfirmadoPor());
        self::assertSame('Cliente pagou o resto em dinheiro', $p->getMotivoManual());
    }

    #[TestDox('Aceitar à mão exige divergência e motivo')]
    public function testAceitarDivergenciaExigencias(): void
    {
        $p = $this->cobranca();
        try {
            $p->aceitarDivergencia('motivo', 1);
            self::fail('Aguardando não pode ser aceita à mão.');
        } catch (\DomainException) {
            self::assertSame(StatusDoPagamento::Aguardando, $p->getStatus());
        }
        $p->confirmar(1, null, $this->agora(), 'banco');
        $this->expectException(\DomainException::class);
        $p->aceitarDivergencia('   ', 1);
    }

    #[TestDox('Expira só depois da validade e só se estiver aguardando')]
    public function testExpirar(): void
    {
        $p = $this->cobranca(150_000, $this->agora());

        self::assertFalse($p->expirar($this->agora()->modify('-1 second')));
        self::assertTrue($p->expirar($this->agora()));
        self::assertSame(StatusDoPagamento::Expirada, $p->getStatus());
        self::assertFalse($p->expirar($this->agora()->modify('+1 day')));
    }

    #[TestDox('Cobrança sem validade nunca expira')]
    public function testSemValidade(): void
    {
        self::assertFalse($this->cobranca()->expirar($this->agora()->modify('+10 years')));
    }

    #[TestDox('Valor inválido e referência vazia não criam cobrança')]
    public function testInvariantes(): void
    {
        foreach ([0, -1, Pagamento::VALOR_MAXIMO_CENTAVOS + 1] as $valor) {
            try {
                $this->cobranca($valor);
                self::fail("Valor $valor deveria ser recusado.");
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\DomainException::class);
        new Pagamento(new Tenant(), OrigemDoPagamento::ServiceDesk, 1, '', 2000, MetodoDePagamento::Pix, 'cora');
    }

    #[TestDox('O pagador guarda só os 4 últimos dígitos do documento')]
    public function testPagadorSemDocumentoInteiro(): void
    {
        $p = $this->cobranca();
        $p->registrarPagador('Maria da Silva', '123.456.789-09');

        self::assertSame('Maria da Silva', $p->getPagadorNome());
        self::assertSame('8909', $p->getPagadorDocumentoFinal());
    }

    #[TestDox('Cada cobrança nasce com uma chave de idempotência própria')]
    public function testChaveDeIdempotencia(): void
    {
        self::assertNotSame($this->cobranca()->getIdempotencyKey(), $this->cobranca()->getIdempotencyKey());
        self::assertSame(32, \strlen($this->cobranca()->getIdempotencyKey()));
    }
}
