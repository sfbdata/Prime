<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * O cliente HTTP das chamadas aos bancos (Cora e API Pix do BACEN): criado por `HttpClient::create()`, SEM
 * o logger do `http_client` do framework (que registra a URL inteira, e a app key do BB vai na query
 * string) e SEM seguir redirecionamento (um 307/308 reenviaria o corpo com o client_secret para outro
 * endereço). Os clientes dos bancos recebem este serviço no lugar do `http_client`; nos testes, recebem o
 * MockHttpClient direto.
 */
final class ClienteHttpDosBancos implements HttpClientInterface
{
    public const OPCOES = ['max_redirects' => 0];

    private HttpClientInterface $cliente;

    /** Sem argumento de propósito: o autowiring não pode trocar o cliente pelo `http_client` do framework. */
    public function __construct()
    {
        $this->cliente = HttpClient::create(self::OPCOES);
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->cliente->request($method, $url, self::OPCOES + $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->cliente->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        $outro = clone $this;
        $outro->cliente = $this->cliente->withOptions($options + self::OPCOES);

        return $outro;
    }
}
