<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\McpController;
use App\Conector\Ferramenta\QuemSouEuFerramenta;
use App\Conector\Mcp\ServidorMcpDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Tests\Conector\Support\DublesDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * O `/mcp` pelo Symfony inteiro (spec mcp-remoto-oauth 3.3, 5.1, 8, 12.15 e 12.18): firewall sem sessão, listeners de
 * `kernel.request`, a ponte PSR-7 e o SDK. O 401 sai com o servidor de verdade do container. Os casos com token trocam o
 * servidor do container por um montado com dublês do validador, do portão e do limite (bloco A), com o despacho, o
 * registro e o log `mcp` de verdade.
 */
#[CoversClass(McpController::class)]
final class McpControllerTest extends WebTestCase
{
    use DublesDoMcpTrait;

    private const CPF = '529.982.247-25';

    #[TestDox('sem token: 401 com o WWW-Authenticate do recurso e scope="mcp:leitura mcp:escrita", nunca 302, sem Set-Cookie, com X-Accel-Buffering: no')]
    public function testSemTokenDa401(): void
    {
        $client = self::cliente();
        $resposta = $this->postar($client, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertSame(401, $resposta->getStatusCode());
        self::assertSame(
            'Bearer resource_metadata="https://bluejus.com.br/.well-known/oauth-protected-resource/mcp", scope="mcp:leitura mcp:escrita"',
            $resposta->headers->get('WWW-Authenticate'),
        );
        self::assertSame('no', $resposta->headers->get('X-Accel-Buffering'));
        self::assertSemSessao($resposta);
    }

    #[TestDox('12.18: com bearer aceito e sem cookie, a resposta MCP chega (200), nunca 302 para tenant_selecionar ou termo_aceite, e sem Set-Cookie')]
    public function testComTokenSemSessao(): void
    {
        $client = self::cliente();
        $client->disableReboot();
        $this->trocarServidor(['mcp:leitura']);

        $resposta = $this->postar($client, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], self::TOKEN);

        self::assertSame(200, $resposta->getStatusCode(), (string) $resposta->getContent());
        self::assertSemSessao($resposta);
        self::assertSame('no', $resposta->headers->get('X-Accel-Buffering'));
        $nomes = array_column(json_decode((string) $resposta->getContent(), true)['result']['tools'], 'name');
        self::assertContains(QuemSouEuFerramenta::NOME, $nomes);
    }

    #[TestDox('12.5 pelo Symfony: tools/call de gravação com o token só de leitura dá 403 insufficient_scope com o _meta do step-up')]
    public function testStepUpPeloSymfony(): void
    {
        $client = self::cliente();
        $client->disableReboot();
        $this->trocarServidor(['mcp:leitura']);

        $resposta = $this->postar($client, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'atualizar_pasta', 'arguments' => new \stdClass()]], self::TOKEN);

        self::assertSame(403, $resposta->getStatusCode());
        $desafio = (string) $resposta->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="insufficient_scope"', $desafio);
        self::assertStringContainsString('scope="mcp:leitura mcp:escrita"', $desafio);
        self::assertStringContainsString('resource_metadata="https://bluejus.com.br/.well-known/oauth-protected-resource/mcp"', $desafio);
        $corpo = json_decode((string) $resposta->getContent(), true);
        self::assertSame(5, $corpo['id']);
        self::assertTrue($corpo['result']['isError']);
        self::assertSame([$desafio], $corpo['result']['_meta']['mcp/www_authenticate']);
        self::assertSemSessao($resposta);
    }

    #[TestDox('12.15: argumento com CPF não deixa nenhum dígito dele no mcp.log, e a chamada deixa uma linha lá')]
    public function testCpfNaoVaiAoLog(): void
    {
        $client = self::cliente();
        $client->disableReboot();
        $arquivo = static::getContainer()->getParameter('kernel.logs_dir') . '/mcp_test' . (string) getenv('TESTE_PROCESSO') . '.log';
        @unlink($arquivo);
        $this->trocarServidor(['mcp:leitura']);

        $resposta = $this->postar($client, ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => QuemSouEuFerramenta::NOME, 'arguments' => ['cpf' => self::CPF, 'busca' => '52998224725']]], self::TOKEN);

        self::assertSame(200, $resposta->getStatusCode());
        $log = (string) @file_get_contents($arquivo);
        self::assertStringContainsString('conector: chamada ao /mcp', $log);
        foreach (['52998224725', '529.982.247-25', '529982', '24725'] as $pedaco) {
            self::assertStringNotContainsString($pedaco, $log);
        }
    }

    /** @param list<string> $escopos */
    private function trocarServidor(array $escopos): void
    {
        $c = static::getContainer();
        $c->set(ServidorMcpDoConector::class, $this->servidor(
            $escopos,
            [$c->get(QuemSouEuFerramenta::class)],
            $c->get(RequisicaoDoConector::class),
            $c->get('monolog.logger.mcp'),
        ));
    }

    /** @param array<string, mixed> $mensagem */
    private function postar(KernelBrowser $client, array $mensagem, ?string $token = null): Response
    {
        $cabecalhos = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if ($token !== null) {
            $cabecalhos['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $client->request('POST', '/mcp', server: $cabecalhos, content: json_encode($mensagem, \JSON_THROW_ON_ERROR));

        return $client->getResponse();
    }

    /**
     * Cliente com `debug` desligado, como em produção. Com `debug` ligado o Symfony lança
     * `UnexpectedSessionUsageException` porque `PastaDaRota` (listeners do cadeado e da pasta só de leitura) lê o
     * escritório da sessão antes de ver que a rota não declara pasta. Sem `debug` o uso só é registrado e a resposta
     * segue sem cookie, que é o que estes testes conferem. Voltar ao cliente padrão quando `PastaDaRota` olhar a
     * declaração antes do escritório.
     */
    private static function cliente(): KernelBrowser
    {
        return static::createClient(['debug' => false]);
    }

    private static function assertSemSessao(Response $resposta): void
    {
        self::assertFalse($resposta->isRedirection(), 'o /mcp nunca redireciona');
        self::assertSame([], $resposta->headers->getCookies());
        self::assertFalse($resposta->headers->has('Set-Cookie'));
    }
}
