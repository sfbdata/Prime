<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\MercadoPago;

use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\MercadoPago\GatewayMercadoPago;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Tests\Contribua\Contrato\TesteDeContratoDoGatewayDeAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpClient\Response\MockResponse;

/** O adaptador do Mercado Pago passa o contrato comum dos gateways de cartão. */
#[CoversClass(GatewayMercadoPago::class)]
final class MercadoPagoContratoTest extends TesteDeContratoDoGatewayDeAssinatura
{
    protected function gatewayQueIniciaComSucesso(): GatewayDeAssinatura
    {
        return RespostasDoMercadoPago::gateway([RespostasDoMercadoPago::json(['id' => 100200300, 'nickname' => 'BLUEJUS', 'site_id' => 'MLB'])]);
    }

    protected function gatewayForaDoAr(): GatewayDeAssinatura
    {
        return RespostasDoMercadoPago::gateway(static fn (): MockResponse => new MockResponse('', ['error' => 'Mercado Pago fora do ar']));
    }

    protected function gatewayComAssinaturaNaSituacao(SituacaoDaAssinatura $situacao): array
    {
        $assinatura = match ($situacao) {
            SituacaoDaAssinatura::Aguardando => RespostasDoMercadoPago::assinatura('authorized', RespostasDoMercadoPago::resumo('green', 0)),
            SituacaoDaAssinatura::Ativa => RespostasDoMercadoPago::assinatura('authorized', RespostasDoMercadoPago::resumo('green', 3)),
            SituacaoDaAssinatura::Inadimplente => RespostasDoMercadoPago::assinatura('authorized', RespostasDoMercadoPago::resumo('red', 3)),
            SituacaoDaAssinatura::Cancelada => RespostasDoMercadoPago::assinatura('canceled', RespostasDoMercadoPago::resumo('green', 3)),
        };

        return [RespostasDoMercadoPago::gateway([RespostasDoMercadoPago::json($assinatura)]), RespostasDoMercadoPago::ID];
    }

    protected function avisoAutentico(): array
    {
        return [
            'gateway' => RespostasDoMercadoPago::gateway([]),
            'cabecalhos' => RespostasDoMercadoPago::cabecalhos(RespostasDoMercadoPago::ID),
            'corpo' => RespostasDoMercadoPago::aviso('subscription_preapproval', RespostasDoMercadoPago::ID),
            'idDoEvento' => '12345',
            'idDaAssinatura' => RespostasDoMercadoPago::ID,
            'referencia' => null,
            'idDoCliente' => null,
        ];
    }

    protected function avisoSemCredencial(): array
    {
        return [
            'gateway' => RespostasDoMercadoPago::gateway([]),
            'cabecalhos' => RespostasDoMercadoPago::cabecalhos(RespostasDoMercadoPago::ID, 'segredo-de-outra-conta'),
            'corpo' => RespostasDoMercadoPago::aviso('subscription_preapproval', RespostasDoMercadoPago::ID),
        ];
    }

    protected function ehHospedado(): bool
    {
        return false;
    }
}
