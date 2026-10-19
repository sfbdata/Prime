<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\MercadoPago;

use App\Contribua\Gateway\FalhaNoGateway;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * O HTTP da API do Mercado Pago (https://www.mercadopago.com.br/developers/pt/reference): JSON nos dois
 * sentidos e o Access Token como Bearer. Toda falha (rede, JSON inválido, HTTP 4xx/5xx) vira
 * `FalhaNoGateway`, com o status HTTP no código da exceção (0 quando nem houve resposta).
 */
final class ClienteMercadoPago
{
    private const ENDERECO = 'https://api.mercadopago.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(string:default::CONTRIBUA_MERCADO_PAGO_ACCESS_TOKEN)%')]
        private readonly string $accessToken,
    ) {
    }

    /**
     * `X-Idempotency-Key`: obrigatória em /v1/payments; no /preapproval a doc não fala dela, e mandá-la não
     * custa nada (a repetição do mesmo pedido não cria a segunda assinatura, se o Mercado Pago a respeitar).
     *
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    public function enviar(string $metodo, string $caminho, array $corpo, ?string $chaveDeIdempotencia = null): array
    {
        $cabecalhos = $chaveDeIdempotencia === null ? [] : ['X-Idempotency-Key' => $chaveDeIdempotencia];

        return $this->pedir($metodo, $caminho, ['json' => $corpo, 'headers' => $cabecalhos]);
    }

    /** @return array<string, mixed> */
    public function ler(string $caminho): array
    {
        return $this->pedir('GET', $caminho, []);
    }

    /**
     * @param array<string, mixed> $opcoes
     *
     * @return array<string, mixed>
     */
    private function pedir(string $metodo, string $caminho, array $opcoes): array
    {
        if ($this->accessToken === '') {
            throw new FalhaNoGateway('CONTRIBUA_MERCADO_PAGO_ACCESS_TOKEN não configurado.');
        }
        $opcoes['auth_bearer'] = $this->accessToken;

        try {
            $resposta = $this->http->request($metodo, self::ENDERECO . $caminho, $opcoes);
            $status = $resposta->getStatusCode();
            $dados = $resposta->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new FalhaNoGateway(sprintf('Mercado Pago sem resposta em %s %s: %s', $metodo, $caminho, $e->getMessage()), 0, $e);
        }

        return $status < 400 ? $dados : throw self::falha($metodo, $caminho, $status, $dados);
    }

    /**
     * O corpo de erro não é documentado para assinaturas; o usual da API é
     * `{"message", "error", "status", "cause": [{"code", "description"}]}`. Lido com tolerância.
     *
     * @param array<string, mixed> $dados
     */
    private static function falha(string $metodo, string $caminho, int $status, array $dados): FalhaNoGateway
    {
        $causa = \is_array($dados['cause'][0] ?? null) ? $dados['cause'][0] : [];

        return new FalhaNoGateway(sprintf(
            'Mercado Pago respondeu %d em %s %s (%s): %s',
            $status,
            $metodo,
            $caminho,
            (string) ($causa['code'] ?? $dados['error'] ?? 'sem código'),
            (string) ($causa['description'] ?? $dados['message'] ?? 'sem mensagem'),
        ), $status);
    }
}
