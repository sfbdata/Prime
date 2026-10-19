<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Stripe;

use App\Contribua\Gateway\FalhaNoGateway;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * O HTTP da API do Stripe (https://docs.stripe.com/api): corpo form-encoded, resposta JSON, chave secreta
 * como Bearer e a versão da API fixa no cabeçalho, para a resposta não mudar quando a conta trocar a
 * versão padrão. Toda falha (rede, JSON inválido, HTTP 4xx/5xx) vira `FalhaNoGateway`, com o status HTTP no
 * código da exceção (0 quando nem houve resposta).
 */
final class ClienteStripe
{
    /** https://docs.stripe.com/api/versioning (a mesma versão vai no endpoint de webhook do painel). */
    public const VERSAO_DA_API = '2026-09-30.endive';
    private const ENDERECO = 'https://api.stripe.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(string:default::CONTRIBUA_STRIPE_SECRET_KEY)%')]
        private readonly string $chaveSecreta,
    ) {
    }

    /**
     * POST com chave de idempotência: repetir com a mesma chave devolve a resposta guardada (24 h), sem
     * cobrar de novo (https://docs.stripe.com/api/idempotent_requests).
     *
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    public function enviar(string $caminho, array $campos, string $chaveDeIdempotencia): array
    {
        return $this->pedir('POST', $caminho, [
            'body' => http_build_query($campos, '', '&'),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Idempotency-Key' => $chaveDeIdempotencia],
        ]);
    }

    /**
     * @param array<string, mixed> $consulta
     *
     * @return array<string, mixed>
     */
    public function ler(string $caminho, array $consulta = []): array
    {
        return $this->pedir('GET', $caminho, ['query' => $consulta]);
    }

    /** @return array<string, mixed> */
    public function apagar(string $caminho): array
    {
        return $this->pedir('DELETE', $caminho, []);
    }

    /**
     * @param array<string, mixed> $opcoes
     *
     * @return array<string, mixed>
     */
    private function pedir(string $metodo, string $caminho, array $opcoes): array
    {
        if ($this->chaveSecreta === '') {
            throw new FalhaNoGateway('CONTRIBUA_STRIPE_SECRET_KEY não configurada.');
        }
        $opcoes['auth_bearer'] = $this->chaveSecreta;
        $opcoes['headers'] = ($opcoes['headers'] ?? []) + ['Stripe-Version' => self::VERSAO_DA_API];

        try {
            $resposta = $this->http->request($metodo, self::ENDERECO . $caminho, $opcoes);
            $status = $resposta->getStatusCode();
            $dados = $resposta->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new FalhaNoGateway(sprintf('Stripe sem resposta em %s %s: %s', $metodo, $caminho, $e->getMessage()), 0, $e);
        }

        return $status < 400 ? $dados : throw self::falha($metodo, $caminho, $status, $dados);
    }

    /**
     * O erro do Stripe vem em `{"error": {"type", "code", "message"}}` (https://docs.stripe.com/api/errors).
     *
     * @param array<string, mixed> $dados
     */
    private static function falha(string $metodo, string $caminho, int $status, array $dados): FalhaNoGateway
    {
        $erro = \is_array($dados['error'] ?? null) ? $dados['error'] : [];

        return new FalhaNoGateway(sprintf(
            'Stripe respondeu %d em %s %s (%s): %s',
            $status,
            $metodo,
            $caminho,
            (string) ($erro['code'] ?? $erro['type'] ?? 'sem código'),
            (string) ($erro['message'] ?? 'sem mensagem'),
        ), $status);
    }
}
