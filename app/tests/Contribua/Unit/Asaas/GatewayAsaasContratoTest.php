<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Asaas;

use App\Contribua\Gateway\Asaas\GatewayAsaas;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Tests\Contribua\Contrato\TesteDeContratoDoGatewayDeAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;

/** O adaptador Asaas cumpre o contrato comum dos gateways de cartão, com as respostas da doc oficial. */
#[CoversClass(GatewayAsaas::class)]
final class GatewayAsaasContratoTest extends TesteDeContratoDoGatewayDeAssinatura
{
    private const ID = ApiAsaasFalsa::ID_DA_ASSINATURA;

    protected function gatewayQueIniciaComSucesso(): GatewayDeAssinatura
    {
        return (new ApiAsaasFalsa([
            'GET /customers' => [200, ApiAsaasFalsa::listaDeClientes([ApiAsaasFalsa::cliente()])],
            'POST /checkouts' => [200, ApiAsaasFalsa::checkoutCriado()],
        ]))->gateway();
    }

    protected function gatewayForaDoAr(): GatewayDeAssinatura
    {
        return ApiAsaasFalsa::gatewayForaDoAr();
    }

    protected function gatewayComAssinaturaNaSituacao(SituacaoDaAssinatura $situacao): array
    {
        [$status, $cobrancas] = match ($situacao) {
            SituacaoDaAssinatura::Aguardando => ['ACTIVE', [ApiAsaasFalsa::cobranca('pay_1', 'PENDING', '2026-10-19')]],
            SituacaoDaAssinatura::Ativa => ['ACTIVE', [ApiAsaasFalsa::cobranca('pay_1', 'CONFIRMED', '2026-10-19')]],
            // O Asaas gera cada cobrança 40 dias antes do vencimento: a de novembro já existe quando a de
            // outubro vence (https://docs.asaas.com/docs/faq-assinaturas).
            SituacaoDaAssinatura::Inadimplente => ['ACTIVE', [
                ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', '2026-09-19'),
                ApiAsaasFalsa::cobranca('pay_2', 'OVERDUE', '2026-10-19'),
                ApiAsaasFalsa::cobranca('pay_3', 'PENDING', '2026-11-19'),
            ]],
            SituacaoDaAssinatura::Cancelada => ['INACTIVE', [ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', '2026-10-19')]],
        };

        $api = new ApiAsaasFalsa([
            'GET /subscriptions/' . self::ID => [200, ApiAsaasFalsa::assinatura($status)],
            'GET /subscriptions/' . self::ID . '/payments' => [200, ApiAsaasFalsa::cobrancasDaAssinatura($cobrancas)],
            'GET /customers/' . ApiAsaasFalsa::ID_DO_CLIENTE => [200, ApiAsaasFalsa::cliente()],
        ]);

        return [$api->gateway(), self::ID];
    }

    protected function avisoAutentico(): array
    {
        return [
            'gateway' => (new ApiAsaasFalsa())->gateway(),
            'cabecalhos' => ['asaas-access-token' => ApiAsaasFalsa::TOKEN_DO_WEBHOOK, 'content-type' => 'application/json'],
            'corpo' => ApiAsaasFalsa::avisoDeAssinatura(),
            'idDoEvento' => 'evt_6561b631fa5580caadd00bf4fc4ef4dc&9193',
            'idDaAssinatura' => self::ID,
            'referencia' => null,
            'idDoCliente' => ApiAsaasFalsa::ID_DO_CLIENTE,
        ];
    }

    protected function avisoSemCredencial(): array
    {
        return [
            'gateway' => (new ApiAsaasFalsa())->gateway(),
            'cabecalhos' => ['content-type' => 'application/json'],
            'corpo' => ApiAsaasFalsa::avisoDeAssinatura(),
        ];
    }

    protected function ehHospedado(): bool
    {
        return true;
    }
}
