<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Pagarme;

use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\Pagarme\GatewayPagarme;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Tests\Contribua\Contrato\TesteDeContratoDoGatewayDeAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpClient\Response\MockResponse;

/** O adaptador do Pagar.me passa o contrato comum dos gateways de cartão. */
#[CoversClass(GatewayPagarme::class)]
final class PagarmeContratoTest extends TesteDeContratoDoGatewayDeAssinatura
{
    protected function gatewayQueIniciaComSucesso(): GatewayDeAssinatura
    {
        return RespostasDoPagarme::gateway([RespostasDoPagarme::json(['data' => [], 'paging' => ['total' => 0]])]);
    }

    protected function gatewayForaDoAr(): GatewayDeAssinatura
    {
        return RespostasDoPagarme::gateway(static fn (): MockResponse => new MockResponse('', ['error' => 'Pagar.me fora do ar']));
    }

    protected function gatewayComAssinaturaNaSituacao(SituacaoDaAssinatura $situacao): array
    {
        [$status, $faturas] = match ($situacao) {
            SituacaoDaAssinatura::Aguardando => ['active', [RespostasDoPagarme::fatura('pending')]],
            SituacaoDaAssinatura::Ativa => ['active', [RespostasDoPagarme::fatura('paid')]],
            SituacaoDaAssinatura::Inadimplente => ['active', [RespostasDoPagarme::fatura('paid', '2026-09-19T00:00:00Z', '2026-10-18T23:59:59Z'), RespostasDoPagarme::fatura('failed')]],
            SituacaoDaAssinatura::Cancelada => ['canceled', [RespostasDoPagarme::fatura('paid')]],
        };

        return [RespostasDoPagarme::gateway([RespostasDoPagarme::json(RespostasDoPagarme::assinatura($status)), RespostasDoPagarme::faturas($faturas)]), RespostasDoPagarme::ID];
    }

    protected function avisoAutentico(): array
    {
        $corpo = RespostasDoPagarme::aviso('invoice.paid', RespostasDoPagarme::fatura('paid') + ['customer' => ['id' => 'cus_017228NmffGbA3d4']]);

        return [
            'gateway' => RespostasDoPagarme::gateway([]),
            'cabecalhos' => ['x-webhook-asymmetric-signature' => RespostasDoPagarme::assinar($corpo), 'content-type' => 'application/json'],
            'corpo' => $corpo,
            'idDoEvento' => 'hook_AbCdEfGhIjKlMnOp',
            'idDaAssinatura' => RespostasDoPagarme::ID,
            'referencia' => null,
            'idDoCliente' => 'cus_017228NmffGbA3d4',
        ];
    }

    protected function avisoSemCredencial(): array
    {
        $corpo = RespostasDoPagarme::aviso('invoice.paid', RespostasDoPagarme::fatura('paid'));

        return [
            'gateway' => RespostasDoPagarme::gateway([], [RespostasDoPagarme::jwks(), RespostasDoPagarme::jwks()]),
            'cabecalhos' => ['x-webhook-asymmetric-signature' => RespostasDoPagarme::assinar($corpo, chave: RespostasDoPagarme::novaChave())],
            'corpo' => $corpo,
        ];
    }

    protected function ehHospedado(): bool
    {
        return false;
    }
}
