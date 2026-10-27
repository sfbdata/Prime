<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Infra\FacebookLoginApi;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * O Facebook do "Continuar com Facebook" nos testes: responde à troca do código e ao `/me` com o que o teste mandar
 * (id, nome, e-mail ou nenhum). Sem rede.
 */
final class FacebookLoginFalso
{
    public string $id = 'fb-1';
    public string $nome = 'Pessoa Facebook';
    /** Nulo = o Facebook não manda e-mail (sem a chave `email` no `/me`). */
    public ?string $email = 'pessoa@exemplo.com';
    public bool $recusarCodigo = false;
    /** @var list<string> */
    public array $pedidos = [];

    public function api(): FacebookLoginApi
    {
        return new FacebookLoginApi(new MockHttpClient($this->responder(...)), 'app-teste', 'segredo-teste');
    }

    /** @param array<string, mixed> $opcoes */
    private function responder(string $metodo, string $url, array $opcoes): MockResponse
    {
        $this->pedidos[] = $metodo . ' ' . (string) parse_url($url, \PHP_URL_PATH);
        $json = ['response_headers' => ['content-type' => 'application/json']];
        if ($this->recusarCodigo) {
            return new MockResponse((string) json_encode(['error' => ['message' => 'invalid code']]), ['http_code' => 400] + $json);
        }
        if (str_contains($url, '/oauth/access_token')) {
            return new MockResponse((string) json_encode(['access_token' => 'token-fb', 'token_type' => 'bearer']), $json);
        }

        return new MockResponse((string) json_encode(['id' => $this->id, 'name' => $this->nome] + (null !== $this->email ? ['email' => $this->email] : [])), $json);
    }
}
