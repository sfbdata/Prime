<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Pagarme;

use App\Contribua\Gateway\Pagarme\AssinaturaDoAvisoPagarme;
use App\Contribua\Gateway\Pagarme\ClientePagarme;
use App\Contribua\Gateway\Pagarme\GatewayPagarme;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Respostas do Pagar.me para os testes, a partir dos exemplos da documentação:
 *  - assinatura: https://docs.pagar.me/reference/assinaturas-1 (o exemplo de resposta, mensal, com o nosso code);
 *  - fatura: https://docs.pagar.me/reference/faturas-1 e a listagem https://docs.pagar.me/reference/listar-faturas-1;
 *  - webhook: id `hook_`, `type` e `data` como os plugins oficiais leem (a doc não traz o corpo literal).
 *
 * O par de chaves RSA do webhook é gerado em memória a cada execução; o JWKS é servido pelo MockHttpClient.
 */
final class RespostasDoPagarme
{
    public const KID = 'chave-de-teste-1';
    public const ID = 'sub_05jkdIfGYPfN26mI';
    public const FIM_DO_CICLO_PAGO = '2026-11-18T23:59:59Z';

    private static ?\OpenSSLAsymmetricKey $chavePrivada = null;

    /**
     * @param list<MockResponse>|callable $respostas da API
     * @param list<MockResponse>|null     $jwks      respostas do JWKS (padrão: a chave de teste)
     */
    public static function gateway(array|callable $respostas, ?array $jwks = null, string $chavePublica = 'pk_test_contribua'): GatewayPagarme
    {
        return new GatewayPagarme(
            new ClientePagarme(new MockHttpClient($respostas), 'sk_test_contribua'),
            new AssinaturaDoAvisoPagarme(new MockHttpClient($jwks ?? [self::jwks()]), new ArrayAdapter()),
            new MockClock(new \DateTimeImmutable('2026-10-19 12:00:00')),
            $chavePublica,
        );
    }

    public static function json(mixed $dados, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($dados), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /** @return array<string, mixed> */
    public static function assinatura(string $status = 'active', string $id = self::ID): array
    {
        return [
            'id' => $id,
            'code' => 'BJ000007-essencial-42',
            'payment_method' => 'credit_card',
            'currency' => 'BRL',
            'interval' => 'month',
            'interval_count' => 1,
            'billing_type' => 'prepaid',
            'current_cycle' => ['id' => 'cycle_j6WnJ7ei1hW68bXo', 'start_at' => '2026-10-19T00:00:00Z', 'end_at' => self::FIM_DO_CICLO_PAGO, 'billing_at' => '2026-10-19T00:00:00Z'],
            'next_billing_at' => '2026-11-19T00:00:00Z',
            'installments' => 1,
            'customer' => ['id' => 'cus_017228NmffGbA3d4', 'name' => 'Escritório de Teste', 'email' => 'financeiro@escritorio.test', 'delinquent' => false],
            'card' => ['id' => 'card_Mome2meGz4PDNQbX', 'masked_number' => '400000******0010', 'exp_month' => 1, 'exp_year' => 30, 'status' => 'active'],
            'items' => [['id' => 'si_B6555Riyq9lj6klS', 'description' => 'BlueJus: espaço Essencial', 'quantity' => 1, 'pricing_scheme' => ['price' => 1990], 'status' => 'active']],
            'status' => $status,
            'created_at' => '2026-10-19T10:25:40Z',
            'updated_at' => '2026-10-19T10:25:40Z',
            'metadata' => ['referencia' => 'BJ000007-essencial-42', 'escritorio' => '7', 'plano' => 'essencial'],
        ];
    }

    /** @return array<string, mixed> */
    public static function fatura(string $status, string $inicioDoCiclo = '2026-10-19T00:00:00Z', string $fimDoCiclo = self::FIM_DO_CICLO_PAGO): array
    {
        return [
            'id' => 'in_' . substr(sha1($status . $inicioDoCiclo), 0, 16),
            'amount' => 1990,
            'status' => $status,
            'billing_at' => $inicioDoCiclo,
            'due_at' => $inicioDoCiclo,
            'cycle' => ['id' => 'cycle_' . substr(sha1($inicioDoCiclo), 0, 16), 'start_at' => $inicioDoCiclo, 'end_at' => $fimDoCiclo, 'billing_at' => $inicioDoCiclo],
            'subscription' => ['id' => self::ID],
            'created_at' => $inicioDoCiclo,
        ];
    }

    /** @param list<array<string, mixed>> $faturas */
    public static function faturas(array $faturas): MockResponse
    {
        return self::json(['data' => $faturas, 'paging' => ['total' => \count($faturas)]]);
    }

    /** @param array<string, mixed> $dados */
    public static function aviso(string $tipo, array $dados, string $id = 'hook_AbCdEfGhIjKlMnOp'): string
    {
        return (string) json_encode(['id' => $id, 'type' => $tipo, 'created_at' => '2026-10-19T12:00:00Z', 'data' => $dados, 'account' => ['id' => 'acc_xxx', 'name' => 'BlueJus']]);
    }

    /** O cabeçalho `X-Webhook-Asymmetric-Signature: alg=RS256; kid=...; signature=<base64url>`. */
    public static function assinar(string $corpo, string $kid = self::KID, ?\OpenSSLAsymmetricKey $chave = null): string
    {
        openssl_sign($corpo, $assinatura, $chave ?? self::chavePrivada(), \OPENSSL_ALGO_SHA256);

        return 'alg=RS256; kid=' . $kid . '; signature=' . rtrim(strtr(base64_encode($assinatura), '+/', '-_'), '=');
    }

    public static function jwks(?\OpenSSLAsymmetricKey $chave = null, string $kid = self::KID): MockResponse
    {
        $rsa = openssl_pkey_get_details($chave ?? self::chavePrivada())['rsa'];
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return self::json(['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]]);
    }

    public static function novaChave(): \OpenSSLAsymmetricKey
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert($chave instanceof \OpenSSLAsymmetricKey);

        return $chave;
    }

    private static function chavePrivada(): \OpenSSLAsymmetricKey
    {
        return self::$chavePrivada ??= self::novaChave();
    }
}
