<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Stripe;

use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\Stripe\GatewayStripe;
use App\Tests\Contribua\Contrato\TesteDeContratoDoGatewayDeAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpClient\Response\MockResponse;

/** O adaptador do Stripe passa o contrato comum dos gateways de cartão. */
#[CoversClass(GatewayStripe::class)]
final class StripeContratoTest extends TesteDeContratoDoGatewayDeAssinatura
{
    protected function gatewayQueIniciaComSucesso(): GatewayDeAssinatura
    {
        return RespostasDoStripe::gateway([RespostasDoStripe::json(RespostasDoStripe::produto())]);
    }

    protected function gatewayForaDoAr(): GatewayDeAssinatura
    {
        return RespostasDoStripe::gateway(static fn (): MockResponse => new MockResponse('', ['error' => 'Stripe fora do ar']));
    }

    protected function gatewayComAssinaturaNaSituacao(SituacaoDaAssinatura $situacao): array
    {
        $status = match ($situacao) {
            SituacaoDaAssinatura::Aguardando => 'incomplete',
            SituacaoDaAssinatura::Ativa => 'active',
            SituacaoDaAssinatura::Inadimplente => 'past_due',
            SituacaoDaAssinatura::Cancelada => 'canceled',
        };

        return [RespostasDoStripe::gateway([RespostasDoStripe::json(RespostasDoStripe::assinatura($status, 'sub_contrato')), RespostasDoStripe::ultimaPaga()]), 'sub_contrato'];
    }

    protected function avisoAutentico(): array
    {
        $corpo = RespostasDoStripe::evento('invoice.paid', RespostasDoStripe::faturaPaga());

        return [
            'gateway' => RespostasDoStripe::gateway([]),
            'cabecalhos' => ['stripe-signature' => RespostasDoStripe::assinar($corpo), 'content-type' => 'application/json; charset=utf-8'],
            'corpo' => $corpo,
            'idDoEvento' => 'evt_1NG8Du2eZvKYlo2CUI79vXWy',
            'idDaAssinatura' => 'sub_1MowQVLkdIwHu7ixeRlqHVzs',
            'referencia' => 'BJ000007-essencial-42',
            'idDoCliente' => 'cus_NffrFeUfNV2Hib',
        ];
    }

    protected function avisoSemCredencial(): array
    {
        $corpo = RespostasDoStripe::evento('invoice.paid', RespostasDoStripe::faturaPaga());

        return ['gateway' => RespostasDoStripe::gateway([]), 'cabecalhos' => ['stripe-signature' => RespostasDoStripe::assinar($corpo, segredo: 'whsec_de_outro_endpoint')], 'corpo' => $corpo];
    }

    protected function ehHospedado(): bool
    {
        return false;
    }
}
