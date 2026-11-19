<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Cliente\Entity\ClientePF;
use App\Conector\Controller\McpController;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Ferramenta\AtualizarClienteFerramenta;
use App\Conector\Ferramenta\QuemSouEuFerramenta;
use App\Conector\Mcp\ServidorMcpDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Conector\Support\DublesDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
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
    use CenarioDoConectorTrait;
    use DublesDoMcpTrait;

    private const CPF = '529.982.247-25';

    #[TestDox('sem token: 401 com o WWW-Authenticate do recurso e scope="mcp:leitura mcp:escrita", nunca 302, sem Set-Cookie, com X-Accel-Buffering: no')]
    public function testSemTokenDa401(): void
    {
        $client = self::cliente();
        $resposta = $this->postar($client, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertSame(401, $resposta->getStatusCode(), mb_substr((string) $resposta->getContent(), 0, 600));
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

    #[TestDox('lote JSON-RPC com tools/call de gravação e token só de leitura: recusado antes do despacho (num lote a ScopePolicy só vê o escopo padrão; quem barra é o SDK, e este teste prende isso)')]
    public function testLoteNaoChegaAoDespacho(): void
    {
        $client = self::cliente();
        $client->disableReboot();
        $this->trocarServidor(['mcp:leitura']);

        $resposta = $this->postar($client, [
            ['jsonrpc' => '2.0', 'id' => 21, 'method' => 'tools/call', 'params' => ['name' => 'atualizar_pasta', 'arguments' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 22, 'method' => 'tools/call', 'params' => ['name' => QuemSouEuFerramenta::NOME, 'arguments' => new \stdClass()]],
        ], self::TOKEN);

        self::assertContains($resposta->getStatusCode(), [400, 403], 'um lote nunca é aceito: ' . mb_substr((string) $resposta->getContent(), 0, 300));
        self::assertStringNotContainsString('"result"', (string) $resposta->getContent(), 'nenhuma ferramenta do lote foi despachada');
        self::assertSemSessao($resposta);
    }

    #[TestDox('3.5, versão 2026-07-28 (sem initialize): tools/call de quem_sou_eu responde 200 com o escritório da chamada, sem Set-Cookie')]
    public function testQuemSouEuNaVersaoModerna(): void
    {
        $client = self::cliente();
        $client->disableReboot();
        $this->trocarServidor(['mcp:leitura']);

        $resposta = $this->postarModerno($client, 11, 'tools/call', ['name' => QuemSouEuFerramenta::NOME, 'arguments' => new \stdClass()], QuemSouEuFerramenta::NOME);

        self::assertSame(200, $resposta->getStatusCode(), mb_substr((string) $resposta->getContent(), 0, 600));
        $corpo = json_decode((string) $resposta->getContent(), true);
        self::assertSame(11, $corpo['id']);
        self::assertFalse($corpo['result']['isError']);
        self::assertSame('Escritório Dublê', $corpo['result']['structuredContent']['escritorio']['nome']);
        self::assertSame(['mcp:leitura'], $corpo['result']['structuredContent']['escopos']);
        self::assertSemSessao($resposta);
    }

    #[TestDox('3.5 e 12.5, versão 2026-07-28: gravação com o token só de leitura dá 403 insufficient_scope com o _meta do step-up')]
    public function testStepUpNaVersaoModerna(): void
    {
        $client = self::cliente();
        $client->disableReboot();
        $this->trocarServidor(['mcp:leitura']);

        $resposta = $this->postarModerno($client, 12, 'tools/call', ['name' => 'atualizar_pasta', 'arguments' => new \stdClass()], 'atualizar_pasta');

        self::assertSame(403, $resposta->getStatusCode());
        $desafio = (string) $resposta->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="insufficient_scope"', $desafio);
        self::assertStringContainsString('scope="mcp:leitura mcp:escrita"', $desafio);
        $corpo = json_decode((string) $resposta->getContent(), true);
        self::assertSame(12, $corpo['id']);
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

    #[TestDox('12.15, caso próprio: atualizar_cliente com CPF nos argumentos grava, e nenhum dígito dele fica no mcp.log (nem na linha do registro da chamada) nem na resposta')]
    public function testCpfDaAtualizarClienteNaoVaiAoLog(): void
    {
        $client = self::cliente();
        $client->disableReboot();
        $arquivo = static::getContainer()->getParameter('kernel.logs_dir') . '/mcp_test' . (string) getenv('TESTE_PROCESSO') . '.log';
        @unlink($arquivo);
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $escritorio, permissoes: ['modules.clientes.view', 'resources.cliente.view', 'resources.cliente.edit']);
        $cliente = (new ClientePF())->setNomeCompleto('Cliente Do Log')->setCpf('')->setRg('')->setRgOrgaoExpedidor('')->setNacionalidade('brasileira');
        $cliente->setEmail('')->setCep('')->setEndereco('')->setCidade('Brasília')->setEstado('DF')->setTenant($escritorio);
        $this->em()->persist($cliente);
        $this->em()->flush();
        $escopos = ['mcp:leitura', 'mcp:escrita'];
        $c = static::getContainer();
        $chamada = new ChamadaDoConector($this->portao()->abrir((int) $pessoa->getId(), (int) $escritorio->getId()), 41, 42, 'claude.ai', $escopos, new \DateTimeImmutable('+7 days'));
        $c->set(ServidorMcpDoConector::class, $this->servidor($escopos, [$c->get(AtualizarClienteFerramenta::class)], $c->get(RequisicaoDoConector::class), $c->get('monolog.logger.mcp'), $chamada));

        $resposta = $this->postar($client, ['jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call', 'params' => ['name' => AtualizarClienteFerramenta::NOME, 'arguments' => [
            'acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['cpf' => self::CPF, 'bairro' => 'Bairro 52998224725'],
        ]]], self::TOKEN);

        self::assertSame(200, $resposta->getStatusCode(), mb_substr((string) $resposta->getContent(), 0, 600));
        $corpo = json_decode((string) $resposta->getContent(), true);
        self::assertFalse($corpo['result']['isError'], mb_substr((string) $resposta->getContent(), 0, 600));
        self::assertEqualsCanonicalizing(['cpf', 'bairro'], $corpo['result']['structuredContent']['campos_alterados']);
        self::assertSame('52998224725', preg_replace('/\D/', '', (string) $this->em()->getConnection()->fetchOne('SELECT cpf FROM cliente_pf WHERE id = ?', [$cliente->getId()])), 'a chamada gravou o CPF');
        $log = (string) @file_get_contents($arquivo);
        self::assertStringContainsString('conector: chamada ao /mcp', $log);
        self::assertStringContainsString(AtualizarClienteFerramenta::NOME, $log, 'a linha é a da chamada da atualizar_cliente');
        foreach ([$log, (string) $resposta->getContent()] as $texto) {
            foreach (['52998224725', '529.982.247-25', '529982', '24725'] as $pedaco) {
                self::assertStringNotContainsString($pedaco, $texto);
            }
        }
    }

    #[TestDox('com bearer aceito e um cookie de sessão de outro escritório: nada liga o filtro da sessão nem toca na sessão (debug ligado: o Symfony lança se a sessão for usada)')]
    public function testNadaTocaNaSessaoNemLigaOFiltro(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $daSessao = $this->escritorio('Escritório da sessão');
        $sessao = static::getContainer()->get('session.factory')->createSession();
        $sessao->set('current_tenant_id', (int) $daSessao->getId());
        $sessao->save();
        $client->getCookieJar()->set(new Cookie($sessao->getName(), $sessao->getId()));
        $this->trocarServidor(['mcp:leitura']);

        $resposta = $this->postar($client, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list'], self::TOKEN);

        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'), 'o filtro do escritório da sessão não liga no /mcp');
        self::assertSame(
            200,
            $resposta->getStatusCode(),
            'a sessão foi usada no /mcp (algum listener de kernel.request não respeita o _stateless): '
            . mb_substr((string) $resposta->getContent(), 0, 300),
        );
        self::assertSemSessao($resposta);
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

    /** @param array<mixed> $mensagem uma mensagem JSON-RPC, ou a lista de um lote */
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
     * Um POST na era sem estado (2026-07-28): versão e capacidades no `_meta`, método e nome nos cabeçalhos.
     *
     * @param array<string, mixed> $params
     */
    private function postarModerno(KernelBrowser $client, int $id, string $metodo, array $params, ?string $nome = null): Response
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::MODERNA,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $cabecalhos = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::MODERNA,
            'HTTP_MCP_METHOD' => $metodo,
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
        ];
        if ($nome !== null) {
            $cabecalhos['HTTP_MCP_NAME'] = $nome;
        }
        $client->request('POST', '/mcp', server: $cabecalhos, content: json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $metodo, 'params' => $params], \JSON_THROW_ON_ERROR));

        return $client->getResponse();
    }

    private static function cliente(): KernelBrowser
    {
        return static::createClient();
    }

    private static function assertSemSessao(Response $resposta): void
    {
        self::assertFalse($resposta->isRedirection(), 'o /mcp nunca redireciona');
        self::assertSame([], $resposta->headers->getCookies());
        self::assertFalse($resposta->headers->has('Set-Cookie'));
    }
}
