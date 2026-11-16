<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\MetadadoDoServidorDeAutorizacaoController;
use App\Conector\Service\ServidorDeAutorizacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * O metadado RFC 8414 (spec mcp-remoto-oauth 3.4 e 12.13, parte do servidor de autorização): responde pelo Symfony,
 * sem login, com o JSON completo e nada além.
 */
#[CoversClass(MetadadoDoServidorDeAutorizacaoController::class)]
final class MetadadoDoServidorDeAutorizacaoTest extends WebTestCase
{
    #[TestDox('GET /.well-known/oauth-authorization-server: 200 sem login, com o documento exato (issuer sem barra, refresh_token, offline_access, none, CIMD, iss) e sem registration_endpoint nem jwks_uri')]
    public function testDocumentoCompleto(): void
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/oauth-authorization-server');

        $resposta = $client->getResponse();
        self::assertSame(200, $resposta->getStatusCode());
        $documento = json_decode((string) $resposta->getContent(), true);
        self::assertSame([
            'issuer' => 'https://bluejus.com.br',
            'authorization_endpoint' => 'https://bluejus.com.br/oauth/authorize',
            'token_endpoint' => 'https://bluejus.com.br/oauth/token',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => ['mcp:leitura', 'mcp:escrita', 'offline_access'],
        ], $documento);
        self::assertArrayNotHasKey('registration_endpoint', $documento);
        self::assertArrayNotHasKey('jwks_uri', $documento);
        self::assertSame([], $resposta->headers->getCookies());
    }

    #[TestDox('O caminho do token_endpoint é o da rota de verdade')]
    public function testCaminhoDoTokenEDaRota(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);

        self::assertSame(MetadadoDoServidorDeAutorizacaoController::CAMINHO_TOKEN, $router->generate(ServidorDeAutorizacao::ROTA_TOKEN));
    }
}
