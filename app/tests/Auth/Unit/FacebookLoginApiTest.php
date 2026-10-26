<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Exception\EntradaPeloFacebookRecusadaException;
use App\Auth\Infra\FacebookLoginApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FacebookLoginApi::class)]
final class FacebookLoginApiTest extends TestCase
{
    #[TestDox('appsecret_proof: HMAC-SHA256 do token com o secret (vetor conhecido da RFC 4231/Wikipedia)')]
    public function testAppsecretProof(): void
    {
        self::assertSame(
            'f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8',
            FacebookLoginApi::appsecretProof('The quick brown fox jumps over the lazy dog', 'key'),
        );
    }

    #[TestDox('Só liga com app id e secret; a versão do Graph vazia ou inválida cai em v21.0')]
    public function testLigadoEVersao(): void
    {
        self::assertFalse((new FacebookLoginApi(new MockHttpClient()))->ligado());
        self::assertFalse((new FacebookLoginApi(new MockHttpClient(), 'app', ' '))->ligado());
        $api = new FacebookLoginApi(new MockHttpClient(), 'app', 'segredo', '../x');
        self::assertTrue($api->ligado());
        self::assertStringStartsWith('https://www.facebook.com/v21.0/dialog/oauth?', $api->urlDeAutorizacao('st', 'https://x/volta'));
        self::assertStringStartsWith('https://www.facebook.com/v19.0/dialog/oauth?', (new FacebookLoginApi(new MockHttpClient(), 'app', 's', 'v19.0'))->urlDeAutorizacao('st', 'https://x/volta'));
    }

    #[TestDox('A ida leva state e o escopo public_profile,email, sem token guardado')]
    public function testUrl(): void
    {
        $url = (new FacebookLoginApi(new MockHttpClient(), 'app-1', 'segredo'))->urlDeAutorizacao('abc', 'https://bluejus.test/login/facebook/callback');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);

        self::assertSame('app-1', $q['client_id']);
        self::assertSame('abc', $q['state']);
        self::assertSame('public_profile,email', $q['scope']);
        self::assertSame('code', $q['response_type']);
        self::assertSame('https://bluejus.test/login/facebook/callback', $q['redirect_uri']);
    }

    #[TestDox('Troca o código e lê o /me com appsecret_proof; e-mail ausente vira null')]
    public function testIdentidade(): void
    {
        $pedidos = [];
        $http = new MockHttpClient(static function (string $metodo, string $url, array $opcoes) use (&$pedidos): MockResponse {
            $pedidos[] = [$metodo, $url];
            if (str_contains($url, '/oauth/access_token')) {
                return new MockResponse((string) json_encode(['access_token' => 'tok']), ['response_headers' => ['content-type: application/json']]);
            }

            return new MockResponse((string) json_encode(['id' => '123', 'name' => 'Ana Souza']), ['response_headers' => ['content-type: application/json']]);
        });

        $identidade = (new FacebookLoginApi($http, 'app', 'segredo'))->identidade('codigo', 'https://x/volta');

        self::assertSame('123', $identidade->id);
        self::assertSame('Ana Souza', $identidade->nome);
        self::assertNull($identidade->email);
        self::assertStringContainsString('graph.facebook.com/v21.0/oauth/access_token', $pedidos[0][1]);
        self::assertStringContainsString('appsecret_proof=' . FacebookLoginApi::appsecretProof('tok', 'segredo'), $pedidos[1][1]);
    }

    #[TestDox('Recusa do Graph, resposta sem token ou sem id: a mensagem neutra')]
    public function testFalhas(): void
    {
        foreach ([
            [new MockResponse('{"error":{}}', ['http_code' => 400])],
            [new MockResponse('{}', ['response_headers' => ['content-type: application/json']])],
            [new MockResponse('{"access_token":"t"}', ['response_headers' => ['content-type: application/json']]), new MockResponse('{}', ['response_headers' => ['content-type: application/json']])],
        ] as $respostas) {
            try {
                (new FacebookLoginApi(new MockHttpClient($respostas), 'app', 'segredo'))->identidade('c', 'https://x/volta');
                self::fail('devia recusar');
            } catch (EntradaPeloFacebookRecusadaException $e) {
                self::assertSame(EntradaPeloFacebookRecusadaException::FALHOU, $e->getMessage());
            }
        }
    }
}
