<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\MetadadoDoRecursoController;
use App\Conector\Service\ServidorDeAutorizacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * O metadado do recurso `/mcp` (RFC 9728; spec mcp-remoto-oauth 3.3 e 12.13): as duas URLs respondem pelo Symfony, sem
 * login e sem cookie, com o mesmo JSON, e o metadado do recurso não traz `offline_access`. A location do nginx que deixa
 * as duas chegarem ao Symfony está no `NginxDoConectorTest`.
 */
#[CoversClass(MetadadoDoRecursoController::class)]
final class MetadadoDoRecursoTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function urls(): iterable
    {
        yield 'derivada do recurso' => ['/.well-known/oauth-protected-resource/mcp'];
        yield 'raiz' => ['/.well-known/oauth-protected-resource'];
    }

    #[DataProvider('urls')]
    #[TestDox('GET $url: 200 sem login e sem cookie, com resource, authorization_servers, os dois escopos do recurso e bearer no cabeçalho')]
    public function testDocumento(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        $resposta = $client->getResponse();
        self::assertSame(200, $resposta->getStatusCode());
        self::assertSame([
            'resource' => 'https://bluejus.com.br/mcp',
            'authorization_servers' => ['https://bluejus.com.br'],
            'scopes_supported' => ['mcp:leitura', 'mcp:escrita'],
            'bearer_methods_supported' => ['header'],
        ], json_decode((string) $resposta->getContent(), true));
        self::assertStringNotContainsString('offline_access', (string) $resposta->getContent());
        self::assertSame([], $resposta->headers->getCookies());
        self::assertFalse($resposta->headers->has('Set-Cookie'));
    }

    #[TestDox('as duas rotas têm os caminhos das constantes, e o 401 do /mcp anuncia a derivada do recurso')]
    public function testCaminhos(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);

        self::assertSame(ServidorDeAutorizacao::CAMINHO_METADADO_DO_RECURSO, $router->generate(ServidorDeAutorizacao::ROTA_METADADO_DO_RECURSO));
        self::assertSame(ServidorDeAutorizacao::CAMINHO_METADADO_DO_RECURSO_RAIZ, $router->generate(ServidorDeAutorizacao::ROTA_METADADO_DO_RECURSO_RAIZ));
        self::assertSame(ServidorDeAutorizacao::ISSUER . ServidorDeAutorizacao::CAMINHO_METADADO_DO_RECURSO, \App\Conector\Mcp\ServidorMcpDoConector::metadadoDoRecurso()->getMetadataUrl());
    }
}
