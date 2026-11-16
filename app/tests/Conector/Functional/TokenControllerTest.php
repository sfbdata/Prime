<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\TokenController;
use App\Conector\DTO\PedidoDeTokenInput;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * O `POST /oauth/token` por HTTP, como o app de I.A. o chama (spec mcp-remoto-oauth 3.2, 3.3 e 5.1; RFC 6749 5.1 e
 * 5.2): sem login e sem cookie, corpo em formulário, JSON com `no-store` no sucesso e no erro, `scope` sempre, limite
 * por app e IP.
 */
#[CoversClass(TokenController::class)]
#[CoversClass(PedidoDeTokenInput::class)]
final class TokenControllerTest extends WebTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @param array<string, mixed> $campos */
    private function postar(array $campos, string $tipo = 'application/x-www-form-urlencoded'): Response
    {
        $this->client->request('POST', '/oauth/token', $campos, [], ['CONTENT_TYPE' => $tipo]);

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    private function json(Response $resposta): array
    {
        $corpo = json_decode((string) $resposta->getContent(), true);
        self::assertIsArray($corpo);

        return $corpo;
    }

    private function assertSemCache(Response $resposta): void
    {
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        self::assertSame('no-cache', $resposta->headers->get('Pragma'));
        self::assertStringStartsWith('application/json', (string) $resposta->headers->get('Content-Type'));
        self::assertSame([], $resposta->headers->getCookies(), 'servidor para servidor: nenhum cookie');
    }

    /**
     * @param array<string, mixed> $conexao
     *
     * @return array<string, string>
     */
    private function camposDoCodigo(array $conexao): array
    {
        return [
            'grant_type' => 'authorization_code',
            'code' => $conexao['codigo'],
            'redirect_uri' => self::REDIRECT,
            'client_id' => $conexao['cliente']->getIdentificador(),
            'code_verifier' => self::VERIFIER,
            'resource' => ServidorDeAutorizacao::RESOURCE,
        ];
    }

    #[TestDox('Código trocado por HTTP: 200, par Bearer com expires_in e scope, sem cache e sem cookie; a renovação depois também')]
    public function testTrocaDoCodigoEDaRenovacao(): void
    {
        $conexao = $this->conexaoAutorizada();

        $resposta = $this->postar($this->camposDoCodigo($conexao));

        self::assertSame(200, $resposta->getStatusCode(), (string) $resposta->getContent());
        $this->assertSemCache($resposta);
        $par = $this->json($resposta);
        self::assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'], array_keys($par));
        self::assertSame('Bearer', $par['token_type']);
        self::assertSame('mcp:leitura offline_access', $par['scope']);

        $renovada = $this->postar(['grant_type' => 'refresh_token', 'refresh_token' => $par['refresh_token'], 'client_id' => $conexao['cliente']->getIdentificador()]);
        self::assertSame(200, $renovada->getStatusCode(), (string) $renovada->getContent());
        $this->assertSemCache($renovada);
        self::assertSame('mcp:leitura offline_access', $this->json($renovada)['scope']);
    }

    #[TestDox('Erro do protocolo: 400 com {error, error_description} fixos e sem cache; nenhum dado do pedido volta')]
    public function testErroSaiEmJsonSemCache(): void
    {
        $conexao = $this->conexaoAutorizada();
        $campos = ['code_verifier' => str_repeat('b', 43)] + $this->camposDoCodigo($conexao);

        $resposta = $this->postar($campos);

        self::assertSame(400, $resposta->getStatusCode());
        $this->assertSemCache($resposta);
        $corpo = $this->json($resposta);
        self::assertSame(['error', 'error_description'], array_keys($corpo));
        self::assertSame('invalid_grant', $corpo['error']);
        self::assertStringNotContainsString($conexao['codigo'], (string) $resposta->getContent());
        self::assertStringNotContainsString('claude.ai', (string) $resposta->getContent());
    }

    #[TestDox('resource diferente na troca do código: 400 invalid_target')]
    public function testResourceDiferente(): void
    {
        $conexao = $this->conexaoAutorizada();

        $resposta = $this->postar(['resource' => 'https://outro.example/mcp'] + $this->camposDoCodigo($conexao));

        self::assertSame(400, $resposta->getStatusCode());
        self::assertSame('invalid_target', $this->json($resposta)['error']);
    }

    #[TestDox('Corpo em JSON (não formulário), sem grant_type ou com campo em lista: invalid_request; grant desconhecido: unsupported_grant_type')]
    public function testPedidoMalformado(): void
    {
        $this->client->request('POST', '/oauth/token', [], [], ['CONTENT_TYPE' => 'application/json'], '{"grant_type":"refresh_token"}');
        self::assertSame('invalid_request', $this->json($this->client->getResponse())['error']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        self::assertSame('invalid_request', $this->json($this->postar(['client_id' => 'x']))['error']);
        self::assertSame('invalid_request', $this->json($this->postar(['grant_type' => 'refresh_token', 'refresh_token' => ['a', 'b'], 'client_id' => 'x']))['error']);
        self::assertSame('invalid_request', $this->json($this->postar(['grant_type' => 'refresh_token', 'refresh_token' => 'a', 'client_id' => ['x']]))['error']);
        self::assertSame('unsupported_grant_type', $this->json($this->postar(['grant_type' => 'password', 'client_id' => 'x']))['error']);
    }

    #[TestDox('GET no /oauth/token: 405')]
    public function testSoPost(): void
    {
        $this->client->request('GET', '/oauth/token');

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    /**
     * O 429 não é observável por limite real no teste (o pool `cache.rate_limiter` é zerado a cada requisição, ver
     * {@see RateLimiterFactoryEspiao}); o que se prova é a chave (app e IP) e a resposta com a cota estourada.
     */
    #[TestDox('Limite conector_token: a chave é o app (hash do client_id) e o IP; com a cota estourada, 429 com Retry-After, sem cache, antes de tocar no pedido')]
    public function testLimitePorAppEIp(): void
    {
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.conector_token', $espiao);
        $this->postar(['grant_type' => 'password', 'client_id' => 'https://claude.ai/app']);
        self::assertSame([hash('sha256', 'https://claude.ai/app') . '|127.0.0.1'], $espiao->chavesConsumidas);

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        static::getContainer()->set('limiter.conector_token', new RateLimiterFactoryEspiao(false));
        $resposta = $this->postar(['grant_type' => 'password', 'client_id' => 'https://claude.ai/app']);

        self::assertSame(429, $resposta->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $resposta->headers->get('Retry-After'));
        $this->assertSemCache($resposta);
        self::assertSame('invalid_request', $this->json($resposta)['error']);
    }
}
