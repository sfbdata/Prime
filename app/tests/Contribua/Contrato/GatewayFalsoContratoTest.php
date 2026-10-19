<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Contrato;

use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Tests\Contribua\Doubles\GatewayFalso;
use PHPUnit\Framework\Attributes\CoversNothing;

/** O gateway falso passa o contrato: prova que o contrato é cumprível e serve de exemplo aos adaptadores. */
#[CoversNothing]
final class GatewayFalsoContratoTest extends TesteDeContratoDoGatewayDeAssinatura
{
    protected function gatewayQueIniciaComSucesso(): GatewayDeAssinatura
    {
        return new GatewayFalso();
    }

    protected function gatewayForaDoAr(): GatewayDeAssinatura
    {
        $gateway = new GatewayFalso();
        $gateway->foraDoAr = true;

        return $gateway;
    }

    protected function gatewayComAssinaturaNaSituacao(SituacaoDaAssinatura $situacao): array
    {
        $gateway = new GatewayFalso();
        $pagaAte = $situacao === SituacaoDaAssinatura::Ativa ? new \DateTimeImmutable('+1 month') : null;
        $gateway->assinaturas['sub_1'] = new SituacaoNoGateway('sub_1', $situacao, $pagaAte);

        return [$gateway, 'sub_1'];
    }

    protected function avisoAutentico(): array
    {
        return [
            'gateway' => new GatewayFalso(),
            'cabecalhos' => ['x-token' => GatewayFalso::TOKEN_DO_AVISO],
            'corpo' => '{"id":"evt_1","evento":"pago","assinatura":"sub_1"}',
            'idDoEvento' => 'evt_1',
            'idDaAssinatura' => 'sub_1',
            'referencia' => null,
            'idDoCliente' => null,
        ];
    }

    protected function avisoSemCredencial(): array
    {
        return ['gateway' => new GatewayFalso(), 'cabecalhos' => [], 'corpo' => '{"id":"evt_1","evento":"pago","assinatura":"sub_1"}'];
    }

    protected function ehHospedado(): bool
    {
        return true;
    }
}
