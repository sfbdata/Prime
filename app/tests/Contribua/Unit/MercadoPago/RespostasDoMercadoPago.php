<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\MercadoPago;

use App\Contribua\Gateway\MercadoPago\AssinaturaDoAvisoMercadoPago;
use App\Contribua\Gateway\MercadoPago\ClienteMercadoPago;
use App\Contribua\Gateway\MercadoPago\GatewayMercadoPago;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Respostas do Mercado Pago para os testes, a partir dos exemplos da referência:
 *  - preapproval: https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/get-preapproval/get
 *    (com o nosso external_reference, BRL e o `summarized` de cada situação);
 *  - authorized_payments: https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/get-authorized-payment/get;
 *  - notificação: o exemplo de webhook da doc (o único literal é o de `payment`; o tipo trocado pelo de assinatura).
 */
final class RespostasDoMercadoPago
{
    public const SEGREDO_DO_AVISO = 'segredo-do-webhook-de-teste';
    public const ID = '2c938084726fca480172750000000000';
    public const PROXIMA_COBRANCA = '2026-11-19T11:12:25.892-04:00';
    public const ULTIMA_COBRANCA = '2026-10-19T11:12:25.892-04:00';

    /** @param list<MockResponse>|callable $respostas */
    public static function gateway(array|callable $respostas, string $chavePublica = 'APP_USR-chave-publica-teste'): GatewayMercadoPago
    {
        return new GatewayMercadoPago(
            new ClienteMercadoPago(new MockHttpClient($respostas), 'APP_USR-access-token-teste'),
            new AssinaturaDoAvisoMercadoPago(self::SEGREDO_DO_AVISO),
            $chavePublica,
        );
    }

    public static function json(mixed $dados, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($dados), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @param array<string, mixed>|null $resumo `summarized`; null para o POST (que não o devolve)
     *
     * @return array<string, mixed>
     */
    public static function assinatura(string $status, ?array $resumo, string $id = self::ID): array
    {
        $assinatura = [
            'id' => $id,
            'version' => 0,
            'application_id' => 1234567812345678,
            'collector_id' => 100200300,
            'reason' => 'BlueJus: espaço Essencial',
            'external_reference' => 'BJ000007-essencial-42',
            'back_url' => 'https://bluejus.com.br/contribua?assinatura=ok',
            'init_point' => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=' . $id,
            'auto_recurring' => ['frequency' => 1, 'frequency_type' => 'months', 'currency_id' => 'BRL', 'transaction_amount' => '19.90'],
            'payer_id' => 123123123,
            'card_id' => 123123123,
            'payment_method_id' => 'visa',
            'next_payment_date' => self::PROXIMA_COBRANCA,
            'date_created' => '2026-10-19T10:12:25.892-04:00',
            'last_modified' => '2026-10-19T11:12:25.892-04:00',
            'status' => $status,
        ];

        return $resumo === null ? $assinatura : $assinatura + ['summarized' => $resumo];
    }

    /** @return array<string, mixed> */
    public static function resumo(string $semaforo, int $cobradas): array
    {
        return [
            'quotas' => null,
            'charged_quantity' => $cobradas,
            'charged_amount' => $cobradas * 19.9,
            'pending_charge_quantity' => $semaforo === 'green' ? 0 : 1,
            'pending_charge_amount' => $semaforo === 'green' ? 0 : 19.9,
            'last_charged_date' => $cobradas > 0 ? self::ULTIMA_COBRANCA : null,
            'last_charged_amount' => $cobradas > 0 ? 19.9 : null,
            'semaphore' => $semaforo,
        ];
    }

    public static function aviso(string $tipo, string $idDoRecurso, int $idDoEvento = 12345): string
    {
        return (string) json_encode([
            'id' => $idDoEvento,
            'live_mode' => true,
            'type' => $tipo,
            'date_created' => '2026-10-19T10:04:58.396-04:00',
            'user_id' => 44444,
            'api_version' => 'v1',
            'action' => 'updated',
            'data' => ['id' => $idDoRecurso],
        ]);
    }

    /** Os cabeçalhos que o Mercado Pago manda: `x-signature: ts=...,v1=...` e `x-request-id`. @return array<string, string> */
    public static function cabecalhos(string $idDoRecurso, string $segredo = self::SEGREDO_DO_AVISO): array
    {
        $carimbo = '1760900000';
        $idDaRequisicao = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
        $manifesto = AssinaturaDoAvisoMercadoPago::manifesto($idDoRecurso, $idDaRequisicao, $carimbo);

        return [
            'x-signature' => 'ts=' . $carimbo . ',v1=' . hash_hmac('sha256', $manifesto, $segredo),
            'x-request-id' => $idDaRequisicao,
            'content-type' => 'application/json',
        ];
    }
}
