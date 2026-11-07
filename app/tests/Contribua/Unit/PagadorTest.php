<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\Pagador;
use App\Contribua\UseCase\MontarPagamentoDoContribuaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O CPF/CNPJ do titular: exigido no formulário de cartão, ausente (null) na página hospedada, que o pede
 * ela mesma (decisão de 07/11/2026).
 */
#[CoversClass(Pagador::class)]
#[CoversClass(MontarPagamentoDoContribuaUseCase::class)]
final class PagadorTest extends TestCase
{
    #[TestDox('Na página hospedada o pagador vai sem documento')]
    public function testSemDocumento(): void
    {
        self::assertNull((new Pagador('Escritório', null, 'a@b.test'))->documento);
    }

    #[TestDox('CPF (11 dígitos) e CNPJ (14 dígitos) passam')]
    public function testDocumentoValido(): void
    {
        self::assertSame('12345678909', (new Pagador('Escritório', '12345678909', 'a@b.test'))->documento);
        self::assertSame('12345678000195', (new Pagador('Escritório', '12345678000195', 'a@b.test'))->documento);
    }

    /** @return iterable<string, array{string}> */
    public static function documentosInvalidos(): iterable
    {
        yield 'vazio' => [''];
        yield 'curto' => ['1234567890'];
        yield 'entre CPF e CNPJ' => ['123456789012'];
        yield 'com pontuação' => ['123.456.789-09'];
    }

    #[DataProvider('documentosInvalidos')]
    #[TestDox('Documento informado e inválido é recusado ($documento)')]
    public function testDocumentoInvalido(string $documento): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Informe o CPF ou CNPJ do titular.');

        new Pagador('Escritório', $documento, 'a@b.test');
    }

    #[TestDox('O modo da janela: sem gateway e Asaas são página hospedada; os outros, formulário de cartão')]
    public function testModoDoGateway(): void
    {
        self::assertSame(InicioDoCheckout::REDIRECIONAR, MontarPagamentoDoContribuaUseCase::modoDoGateway(null));
        self::assertSame(InicioDoCheckout::REDIRECIONAR, MontarPagamentoDoContribuaUseCase::modoDoGateway('asaas'));
        self::assertSame(InicioDoCheckout::FORMULARIO, MontarPagamentoDoContribuaUseCase::modoDoGateway('stripe'));
        self::assertSame(InicioDoCheckout::FORMULARIO, MontarPagamentoDoContribuaUseCase::modoDoGateway('mercado_pago'));
        self::assertSame(InicioDoCheckout::FORMULARIO, MontarPagamentoDoContribuaUseCase::modoDoGateway('pagarme'));
    }
}
