<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Stripe;

use App\Contribua\Gateway\Stripe\AssinaturaDoAvisoStripe;
use App\Contribua\Gateway\Stripe\ClienteStripe;
use App\Contribua\Gateway\Stripe\GatewayStripe;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Respostas do Stripe para os testes, a partir dos exemplos da documentação:
 *  - Subscription: https://docs.stripe.com/api/subscriptions/object (o exemplo de resposta do create),
 *    com BRL, o nosso metadata e o período no item (versão basil em diante);
 *  - Event: https://docs.stripe.com/api/events/object;
 *  - Invoice.parent: https://docs.stripe.com/api/invoices/object.
 */
final class RespostasDoStripe
{
    public const SEGREDO_DO_AVISO = 'whsec_teste_do_contribua';
    public const AGORA = 1_760_000_000;
    public const INICIO_DO_PERIODO = 1_759_000_000;
    public const FIM_DO_PERIODO = 1_761_600_000;

    /** @param list<MockResponse>|callable $respostas */
    public static function gateway(array|callable $respostas, string $chavePublica = 'pk_test_contribua'): GatewayStripe
    {
        return new GatewayStripe(
            new ClienteStripe(new MockHttpClient($respostas), 'sk_test_contribua'),
            new AssinaturaDoAvisoStripe(self::SEGREDO_DO_AVISO, new MockClock(new \DateTimeImmutable('@' . self::AGORA))),
            $chavePublica,
        );
    }

    public static function json(mixed $dados, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($dados), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /** https://docs.stripe.com/error-codes (resource_missing) */
    public static function naoExiste(): MockResponse
    {
        return self::json(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such product']], 404);
    }

    /** @return array<string, mixed> */
    public static function produto(string $id = 'bluejus_espaco_essencial'): array
    {
        return ['id' => $id, 'object' => 'product', 'active' => true, 'name' => 'BlueJus: espaço Essencial'];
    }

    /** @return array<string, mixed> */
    public static function cliente(): array
    {
        return ['id' => 'cus_NffrFeUfNV2Hib', 'object' => 'customer', 'email' => 'financeiro@escritorio.test', 'invoice_settings' => ['default_payment_method' => 'pm_1Teste']];
    }

    /** @return array<string, mixed> */
    public static function assinatura(string $status, string $id = 'sub_1MowQVLkdIwHu7ixeRlqHVzs', ?string $faturaPaga = 'paid'): array
    {
        return [
            'id' => $id,
            'object' => 'subscription',
            'cancel_at_period_end' => false,
            'currency' => 'brl',
            'customer' => 'cus_NffrFeUfNV2Hib',
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_Na6dzxczY5fwHx',
                'object' => 'subscription_item',
                'current_period_start' => self::INICIO_DO_PERIODO,
                'current_period_end' => self::FIM_DO_PERIODO,
                'price' => ['id' => 'price_1MowQULkdIwHu7ixraBm864M', 'currency' => 'brl', 'product' => 'bluejus_espaco_essencial', 'recurring' => ['interval' => 'month', 'interval_count' => 1], 'unit_amount' => 1990],
                'quantity' => 1,
            ]]],
            'latest_invoice' => $faturaPaga === null ? 'in_1MowQWLkdIwHu7ixuzkSPfKd' : ['id' => 'in_1MowQWLkdIwHu7ixuzkSPfKd', 'object' => 'invoice', 'status' => $faturaPaga],
            'livemode' => false,
            'metadata' => ['referencia' => 'BJ000007-essencial-42', 'escritorio' => '7', 'plano' => 'essencial'],
            'status' => $status,
        ];
    }

    /** @param array<string, mixed> $objeto */
    public static function evento(string $tipo, array $objeto, string $id = 'evt_1NG8Du2eZvKYlo2CUI79vXWy'): string
    {
        return (string) json_encode([
            'id' => $id,
            'object' => 'event',
            'api_version' => ClienteStripe::VERSAO_DA_API,
            'created' => self::AGORA,
            'data' => ['object' => $objeto],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => $tipo,
        ]);
    }

    /** @return array<string, mixed> */
    public static function faturaPaga(): array
    {
        return [
            'id' => 'in_1MowQWLkdIwHu7ixuzkSPfKd',
            'object' => 'invoice',
            'customer' => 'cus_NffrFeUfNV2Hib',
            'status' => 'paid',
            'billing_reason' => 'subscription_cycle',
            'amount_paid' => 1990,
            'currency' => 'brl',
            'parent' => ['type' => 'subscription_details', 'quote_details' => null, 'subscription_details' => [
                'subscription' => 'sub_1MowQVLkdIwHu7ixeRlqHVzs',
                'metadata' => ['referencia' => 'BJ000007-essencial-42'],
            ]],
        ];
    }

    /** O cabeçalho que o Stripe manda: `t=<carimbo>,v1=<hmac>` (https://docs.stripe.com/webhooks). */
    public static function assinar(string $corpo, int $carimbo = self::AGORA, string $segredo = self::SEGREDO_DO_AVISO): string
    {
        return 't=' . $carimbo . ',v1=' . hash_hmac('sha256', $carimbo . '.' . $corpo, $segredo);
    }
}
