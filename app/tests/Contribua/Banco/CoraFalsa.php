<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Banco;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Cora falsa para o MockHttpClient, com as respostas dos exemplos da documentação oficial:
 * - token: developers.cora.com.br/docs/client-credentials-int-direta
 * - QR Code Pix v2: developers.cora.com.br/reference/qr-code-pix-v2
 * - consulta: developers.cora.com.br/reference/consultar-detalhes-de-um-boleto-v2
 * Registra cada pedido (método, URL, opções) para conferir mTLS, token e idempotência.
 */
final class CoraFalsa
{
    /** @var list<array{metodo: string, url: string, opcoes: array<string, mixed>}> */
    public array $pedidos = [];
    /** @var array<string, string> id da cobrança => status */
    public array $status = [];
    /** @var array<string, string> id da cobrança => nosso código */
    private array $codigos = [];
    public bool $recusarToken = false;
    /** Força a resposta da criação de cobrança (500 = banco fora; 400 = recusa do pedido). */
    public ?int $statusDaCriacao = null;
    public int $tokensExpirados = 0;
    public int $tokensEmitidos = 0;
    /** `occurrence_date` da cobrança paga: só a data, ou data e hora com fuso. */
    public string $dataDoPagamento = '2026-10-19';
    /** Quanto o banco diz ter recebido nas cobranças pagas (padrão R$ 20,00). */
    public int $valorPago = 2000;

    /** Uma cobrança que já existe no banco (criada por fora do fluxo do Contribua): id, status e o código que a Cora devolve. */
    public function cobrancaExistente(string $id, string $status, ?string $codigo): void
    {
        $this->status[$id] = $status;
        if (null !== $codigo) {
            $this->codigos[$id] = $codigo;
        } else {
            unset($this->codigos[$id]);
        }
    }

    public function cliente(): MockHttpClient
    {
        return new MockHttpClient($this->responder(...));
    }

    /** @param array<string, mixed> $opcoes */
    public function responder(string $metodo, string $url, array $opcoes): MockResponse
    {
        $this->pedidos[] = ['metodo' => $metodo, 'url' => $url, 'opcoes' => $opcoes];
        $caminho = (string) parse_url($url, \PHP_URL_PATH);
        if ('/token' === $caminho) {
            return $this->token();
        }
        if ($this->tokensExpirados > 0 && 'Bearer token-1' === ($this->cabecalho($opcoes, 'authorization'))) {
            --$this->tokensExpirados;

            return $this->json(['error' => 'access_denied'], 401);
        }
        if ('POST' === $metodo && '/v2/invoices/' === $caminho) {
            return $this->criar($opcoes);
        }

        return $this->consultar(basename($caminho));
    }

    /** @param array<string, mixed> $opcoes */
    public function cabecalho(array $opcoes, string $nome): ?string
    {
        foreach ((array) ($opcoes['headers'] ?? []) as $h) {
            if (is_string($h) && str_starts_with(strtolower($h), $nome . ':')) {
                return trim(substr($h, strlen($nome) + 1));
            }
        }

        return null;
    }

    private function token(): MockResponse
    {
        if ($this->recusarToken) {
            return $this->json(['error' => 'invalid_client', 'error_description' => 'Invalid client or Invalid client credentials'], 401);
        }
        ++$this->tokensEmitidos;

        return $this->json(['access_token' => 'token-' . $this->tokensEmitidos, 'expires_in' => 86400, 'refresh_expires_in' => 0, 'token_type' => 'Bearer', 'not-before-policy' => 1614056798, 'scope' => 'offline_access']);
    }

    /** @param array<string, mixed> $opcoes */
    private function criar(array $opcoes): MockResponse
    {
        $corpo = json_decode((string) ($opcoes['body'] ?? '{}'), true);
        if (null !== $this->statusDaCriacao) {
            return $this->json(['code' => 'erro', 'message' => 'Falha simulada', 'errors' => [['code' => 'x', 'message' => 'falha simulada ' . $this->statusDaCriacao]]], $this->statusDaCriacao);
        }
        if (($corpo['services'][0]['amount'] ?? 0) < 500) {
            return $this->json(['code' => 'invalid_request', 'message' => 'Request has invalid parameters', 'errors' => [['code' => 'services[0].amount', 'message' => 'must be greater than or equal to 500']]], 400);
        }
        $id = 'inv_' . substr(sha1((string) $corpo['code']), 0, 20);
        $this->status[$id] = 'OPEN';
        $this->codigos[$id] = (string) $corpo['code'];

        return $this->json([
            'id' => $id, 'status' => 'OPEN', 'created_at' => '2023-09-27T18:14:12Z', 'total_amount' => $corpo['services'][0]['amount'], 'total_paid' => 0,
            'occurrence_date' => null, 'code' => $corpo['code'], 'customer' => $corpo['customer'], 'services' => $corpo['services'],
            'payment_terms' => $corpo['payment_terms'], 'payments' => [],
            'pix' => ['emv' => '00020101021226830014br.gov.bcb.pix2561qrcode-h.cora.com.br/v1/cobv/d1d790a04fe34c37b41676246fdd9baa5204000053039865802BR5912MARI COMPANY6009SAO PAULO62070503***6304F445'],
            'notifications' => null,
        ]);
    }

    private function consultar(string $id): MockResponse
    {
        if (!isset($this->status[$id])) {
            return $this->json(['code' => 'not_found', 'message' => 'Invoice not found'], 404);
        }
        $pago = 'PAID' === $this->status[$id];

        return $this->json([
            'id' => $id, 'status' => $this->status[$id], 'total_amount' => 2000, 'total_paid' => $pago ? $this->valorPago : 0,
            'occurrence_date' => $pago ? $this->dataDoPagamento : null, 'code' => $this->codigos[$id] ?? null,
            'payments' => $pago ? [['id' => 'fd13', 'status' => 'SUCCESS', 'total_paid' => $this->valorPago, 'method' => 'PIX']] : [],
        ]);
    }

    /** @param array<string, mixed> $dados */
    private function json(array $dados, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($dados), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
