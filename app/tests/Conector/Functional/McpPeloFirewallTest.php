<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\McpController;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Ferramenta\QuemSouEuFerramenta;
use App\Conector\Mcp\LimiteDeChamadasMiddleware;
use App\Conector\Mcp\PortaoNoMcpMiddleware;
use App\Conector\Mcp\RegistroDaChamadaMiddleware;
use App\Conector\Mcp\ServidorMcpDoConector;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * O `/mcp` de ponta a ponta pelo firewall `conector_mcp` de verdade, com o validador, o portão e o limite do container
 * (spec mcp-remoto-oauth 12.19 e decisão 2 do PR 5, um juiz só). Sem dublê: o token é gravado no banco sobre o cenário
 * do servidor de autorização.
 *
 */
#[CoversClass(McpController::class)]
#[CoversClass(ServidorMcpDoConector::class)]
#[CoversClass(RegistroDaChamadaMiddleware::class)]
final class McpPeloFirewallTest extends WebTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('12.19: no mesmo kernel, o token aceito responde, o mesmo token revogado é recusado com 401 invalid_token e um token novo volta a responder')]
    public function testAceitaDepoisRecusaNoMesmoKernel(): void
    {
        $conexao = $this->conexaoAutorizada();
        [$valor, $token] = $this->tokenDeAcesso($conexao['concessao']);

        $aceita = $this->postar(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => QuemSouEuFerramenta::NOME, 'arguments' => new \stdClass()]], $valor);
        self::assertSame(200, $aceita->getStatusCode(), (string) $aceita->getContent());
        $resultado = json_decode((string) $aceita->getContent(), true)['result'];
        self::assertFalse($resultado['isError']);
        self::assertSame($conexao['escritorio']->getId(), $resultado['structuredContent']['escritorio']['id']);

        $token->revogar($this->agora());
        $this->em()->flush();

        $recusada = $this->postar(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $valor);
        self::assertSame(401, $recusada->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', (string) $recusada->headers->get('WWW-Authenticate'));
        self::assertSame([], $recusada->headers->getCookies());

        // A segunda requisição zerou os serviços (o `services_resetter` roda mesmo sem reboot) e o EntityManager soltou
        // a concessão: ela volta pelo id antes do token novo.
        $concessao = $this->em()->find(ConcessaoDoConector::class, $conexao['concessao']->getId());
        self::assertNotNull($concessao);
        [$novo] = $this->tokenDeAcesso($concessao);
        $deNovo = $this->postar(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], $novo);
        self::assertSame(200, $deNovo->getStatusCode(), 'a recusa anterior não fica guardada');
    }

    #[TestDox('um juiz só: o firewall, o AuthorizationMiddleware e o log usam a mesma instância do validador, que guardou o token aceito da requisição; o servidor recebe o portão e o limite do container')]
    public function testUmJuizSo(): void
    {
        $conexao = $this->conexaoAutorizada();
        [$valor] = $this->tokenDeAcesso($conexao['concessao']);

        self::assertSame(200, $this->postar(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $valor)->getStatusCode());

        $c = static::getContainer();
        $validador = $c->get(ValidadorDoTokenDoConector::class);
        self::assertSame($validador, self::propriedade($c->get(ServidorMcpDoConector::class), 'validador'));
        self::assertSame($validador, self::propriedade($c->get(RegistroDaChamadaMiddleware::class), 'validador'));
        self::assertSame($c->get(PortaoNoMcpMiddleware::class), self::propriedade($c->get(ServidorMcpDoConector::class), 'portao'));
        self::assertSame($c->get(LimiteDeChamadasMiddleware::class), self::propriedade($c->get(ServidorMcpDoConector::class), 'limite'));
        self::assertSame($conexao['concessao']->getId(), $validador->tokenValidado()?->getConcessao()->getId());
    }

    #[TestDox('o log mcp leva o motivo da recusa do validador quando o token é recusado')]
    public function testMotivoDaRecusaNoLog(): void
    {
        $arquivo = static::getContainer()->getParameter('kernel.logs_dir') . '/mcp_test' . (string) getenv('TESTE_PROCESSO') . '.log';
        @unlink($arquivo);
        $conexao = $this->conexaoAutorizada();
        [$valor, $token] = $this->tokenDeAcesso($conexao['concessao']);
        $token->revogar($this->agora());
        $this->em()->flush();

        self::assertSame(401, $this->postar(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $valor)->getStatusCode());

        $linhas = array_values(array_filter(explode("\n", (string) @file_get_contents($arquivo))));
        self::assertCount(1, $linhas);
        self::assertStringContainsString('"status":401', $linhas[0]);
        self::assertMatchesRegularExpression('/"motivo":"[a-z_]+"/', $linhas[0]);
    }

    /** @return array{string, TokenDoConector} o valor em texto (o bearer) e o token gravado */
    private function tokenDeAcesso(ConcessaoDoConector $concessao): array
    {
        $segredo = (new SegredoOpaco())->gerar();
        $token = TokenDoConector::deAcesso($segredo['hash'], $concessao, $this->agora());
        $this->em()->persist($token);
        $this->em()->flush();

        return [$segredo['valor'], $token];
    }

    /** @param array<string, mixed> $mensagem */
    private function postar(array $mensagem, string $token): Response
    {
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], content: json_encode($mensagem, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private static function propriedade(object $objeto, string $nome): mixed
    {
        return (new \ReflectionProperty($objeto, $nome))->getValue($objeto);
    }
}
