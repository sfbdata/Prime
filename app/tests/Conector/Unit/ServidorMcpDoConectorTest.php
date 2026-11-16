<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Mcp\EscopoQueFaltaMiddleware;
use App\Conector\Mcp\PreflightSemPortaoMiddleware;
use App\Conector\Mcp\RegistroDaChamadaMiddleware;
use App\Conector\Mcp\ServidorMcpDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Tests\Conector\Support\DublesDoMcpTrait;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O servidor do `/mcp` montado com o SDK (spec mcp-remoto-oauth 3.1, 5.1, 6.2 regra 9 e 8), com dublês no lugar do
 * validador, do portão e do limite (bloco A): a ordem da cadeia, o 401 e o 403 do SDK com o `WWW-Authenticate` exato,
 * o corpo do step-up do ChatGPT (12.5), sem sessão, `subscriptions/listen` que não prende o processo e o log `mcp` sem
 * os argumentos (12.15).
 */
#[CoversClass(ServidorMcpDoConector::class)]
#[CoversClass(EscopoQueFaltaMiddleware::class)]
#[CoversClass(RegistroDaChamadaMiddleware::class)]
#[CoversClass(PreflightSemPortaoMiddleware::class)]
final class ServidorMcpDoConectorTest extends TestCase
{
    use DublesDoMcpTrait;

    private const METADADO = 'https://bluejus.com.br/.well-known/oauth-protected-resource/mcp';
    private const CPF = '529.982.247-25';

    #[TestDox('a cadeia: registro, CORS, rebinding, step-up, autorização, preflight, portão e limite; nunca o ProtocolVersionMiddleware')]
    public function testOrdemDosMiddlewares(): void
    {
        $middlewares = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector())->middlewares();

        self::assertCount(8, $middlewares);
        self::assertInstanceOf(RegistroDaChamadaMiddleware::class, $middlewares[0]);
        self::assertInstanceOf(CorsMiddleware::class, $middlewares[1]);
        self::assertInstanceOf(DnsRebindingProtectionMiddleware::class, $middlewares[2]);
        self::assertInstanceOf(EscopoQueFaltaMiddleware::class, $middlewares[3]);
        self::assertInstanceOf(AuthorizationMiddleware::class, $middlewares[4]);
        self::assertInstanceOf(PreflightSemPortaoMiddleware::class, $middlewares[5]);
        foreach ($middlewares as $middleware) {
            self::assertNotInstanceOf(ProtocolVersionMiddleware::class, $middleware);
        }
    }

    #[TestDox('sem o portão, o pedido com token que passa pela autorização lança: nada é servido além do 401')]
    public function testSemPortaoFalhaFechado(): void
    {
        $fabrica = new Psr17Factory();
        $requisicao = new RequisicaoDoConector();
        $validador = self::validador(['mcp:leitura']);
        $servidor = new ServidorMcpDoConector(
            new \App\Conector\Ferramenta\DespachoDasFerramentas([], $requisicao),
            $validador,
            new RegistroDaChamadaMiddleware(new Logger('mcp'), $requisicao, $validador),
            new EscopoQueFaltaMiddleware($fabrica),
            null,
            $this->limiteDuble(),
            ['localhost'],
            $fabrica,
            $fabrica,
        );

        self::assertSame(401, $servidor->responder(self::post('tools/list', token: null))->getStatusCode());

        $this->expectException(\LogicException::class);
        $servidor->responder(self::post('tools/list'));
    }

    #[TestDox('sem token: 401 com resource_metadata e scope="mcp:leitura mcp:escrita", sem passar pelo portão')]
    public function testSemTokenDa401ComOsDoisEscopos(): void
    {
        $resposta = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector())->responder(self::post('tools/list', token: null));

        self::assertSame(401, $resposta->getStatusCode());
        self::assertSame('Bearer resource_metadata="' . self::METADADO . '", scope="mcp:leitura mcp:escrita"', $resposta->getHeaderLine('WWW-Authenticate'));
        self::assertStringNotContainsString('offline_access', $resposta->getHeaderLine('WWW-Authenticate'));
        self::assertSame(0, $this->passouNoPortao);
    }

    #[TestDox('12.5: gravar com o token só de leitura dá 403 insufficient_scope com o WWW-Authenticate exato e o _meta do ChatGPT no corpo JSON-RPC')]
    public function testGravarSemEscritaDa403ComStepUp(): void
    {
        $gravar = self::ferramenta('atualizar_cliente', new RespostaDaFerramenta(['ok' => true]));
        $resposta = $this->servidor(['mcp:leitura'], [$gravar], new RequisicaoDoConector())
            ->responder(self::post('tools/call', ['name' => 'atualizar_cliente', 'arguments' => new \stdClass()], id: 'pedido-7'));

        $desafio = 'Bearer resource_metadata="' . self::METADADO . '", scope="mcp:leitura mcp:escrita", error="insufficient_scope", error_description="The access token lacks a required scope."';
        self::assertSame(403, $resposta->getStatusCode());
        self::assertSame($desafio, $resposta->getHeaderLine('WWW-Authenticate'));
        self::assertSame('application/json', $resposta->getHeaderLine('Content-Type'));
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 'pedido-7',
            'result' => [
                'content' => [['type' => 'text', 'text' => EscopoQueFaltaMiddleware::MENSAGEM]],
                'isError' => true,
                '_meta' => ['mcp/www_authenticate' => [$desafio]],
            ],
        ], self::corpo($resposta));
        self::assertSame(0, $this->passouNoPortao, 'o escopo é barrado antes do portão');
        self::assertSame([], $gravar->recebidos);
    }

    #[TestDox('com mcp:escrita a mesma gravação passa pelo portão e pelo limite e responde 200')]
    public function testGravarComEscritaPassa(): void
    {
        $gravar = self::ferramenta('atualizar_cliente', new RespostaDaFerramenta(['ok' => true]));
        $resposta = $this->servidor(['mcp:leitura', 'mcp:escrita'], [$gravar], new RequisicaoDoConector())
            ->responder(self::post('tools/call', ['name' => 'atualizar_cliente', 'arguments' => new \stdClass()]));

        self::assertSame(200, $resposta->getStatusCode());
        self::assertSame(1, $this->passouNoPortao);
        self::assertSame(1, $this->passouNoLimite);
        self::assertFalse(self::corpo($resposta)['result']['isError']);
    }

    #[TestDox('token recusado pelo validador: 401 invalid_token, sem corpo de step-up')]
    public function testTokenRecusadoDa401(): void
    {
        $resposta = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector())->responder(self::post('tools/list', token: 'recusado'));

        self::assertSame(401, $resposta->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $resposta->getHeaderLine('WWW-Authenticate'));
        self::assertSame('', (string) $resposta->getBody());
    }

    #[TestDox('host fora de conector.mcp_hosts: 403 da proteção de rebinding')]
    public function testHostForaDaListaDa403(): void
    {
        $resposta = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector(), hosts: ['bluejus.com.br'])
            ->responder(self::post('tools/list', host: 'atacante.example'));

        self::assertSame(403, $resposta->getStatusCode());
        self::assertSame('', $resposta->getHeaderLine('WWW-Authenticate'));
    }

    #[TestDox('initialize 2025-06-18 sem sessão: 200 sem Mcp-Session-Id; GET e DELETE dão 405')]
    public function testSemSessao(): void
    {
        $servidor = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector());
        $resposta = $servidor->responder(self::post('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'teste', 'version' => '1'],
        ]));

        self::assertSame(200, $resposta->getStatusCode());
        self::assertFalse($resposta->hasHeader('Mcp-Session-Id'));
        self::assertSame('2025-06-18', self::corpo($resposta)['result']['protocolVersion']);

        foreach (['GET', 'DELETE'] as $metodo) {
            $outro = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector())
                ->responder(self::post('ping')->withMethod($metodo));
            self::assertSame(405, $outro->getStatusCode(), $metodo);
        }
    }

    #[TestDox('subscriptions/listen (2026-07-28) responde o reconhecimento e encerra na hora, sem prender o processo')]
    public function testListenNaoPrende(): void
    {
        $inicio = microtime(true);
        $resposta = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector())
            ->responder(self::postModerno('subscriptions/listen', ['notifications' => ['toolsListChanged' => true]]));

        // O corpo em fluxo escreve na saída e dá ob_flush: o buffer guarda cada pedaço pelo callback.
        $fluxo = '';
        ob_start(static function (string $pedaco) use (&$fluxo): string {
            $fluxo .= $pedaco;

            return '';
        });
        try {
            $resposta->getBody()->getContents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(200, $resposta->getStatusCode());
        self::assertSame('text/event-stream', $resposta->getHeaderLine('Content-Type'));
        self::assertStringContainsString('notifications/subscriptions/acknowledged', $fluxo);
        self::assertStringContainsString('"resultType":"complete"', $fluxo);
        self::assertLessThan(2.0, microtime(true) - $inicio, 'o fluxo não pode esperar notificações');
    }

    #[TestDox('12.15: o log mcp tem uma linha por requisição, com ids, duração e anotações, e nenhum dígito do CPF dos argumentos')]
    public function testLogSemCpf(): void
    {
        $registros = new TestHandler();
        $requisicao = new RequisicaoDoConector();
        $gravar = self::ferramenta('atualizar_cliente', new RespostaDaFerramenta(['cliente' => 'gravado'], ids: ['cliente' => [5]]));

        $resposta = $this->servidor(['mcp:leitura', 'mcp:escrita'], [$gravar], $requisicao, new Logger('mcp', [$registros]))
            ->responder(self::post('tools/call', ['name' => 'atualizar_cliente', 'arguments' => ['cpf' => self::CPF]]));

        self::assertSame(200, $resposta->getStatusCode());
        self::assertSame([['cpf' => self::CPF]], $gravar->recebidos, 'a ferramenta recebeu o CPF');
        self::assertCount(1, $registros->getRecords());
        $linha = $registros->getRecords()[0];
        self::assertSame(RegistroDaChamadaMiddleware::MENSAGEM, $linha->message);
        self::assertSame(200, $linha->context['status']);
        self::assertSame(41, $linha->context['concessao']);
        self::assertSame(42, $linha->context['consentimento']);
        self::assertSame('claude.ai', $linha->context['cliente']);
        self::assertSame('atualizar_cliente', $linha->context['ferramenta']);
        self::assertSame('ok', $linha->context['resultado']);
        self::assertSame(['cliente' => [5]], $linha->context['ids']);
        self::assertIsInt($linha->context['duracao_ms']);

        $texto = json_encode([$linha->message, $linha->context], \JSON_THROW_ON_ERROR);
        foreach (['52998224725', '529.982.247-25', '529982', '24725'] as $pedaco) {
            self::assertStringNotContainsString($pedaco, $texto);
        }
    }

    #[TestDox('o log escreve também o 401, sem ids de chamada')]
    public function testLogDoQuatroZeroUm(): void
    {
        $registros = new TestHandler();
        $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector(), new Logger('mcp', [$registros]))
            ->responder(self::post('tools/list', token: null));

        self::assertCount(1, $registros->getRecords());
        $contexto = $registros->getRecords()[0]->context;
        self::assertSame(401, $contexto['status']);
        self::assertArrayNotHasKey('pessoa', $contexto);
    }

    #[TestDox('o OPTIONS recebe 204 com os cabeçalhos do CORS e não passa pelo portão (que daria 503) nem pelo limite; de origem de fora, 403 do rebinding')]
    public function testPreflightNaoPassaPeloPortao(): void
    {
        $fabrica = new Psr17Factory();
        $pedido = $fabrica->createServerRequest('OPTIONS', 'http://localhost/mcp')
            ->withHeader('Host', 'localhost')
            ->withHeader('Origin', 'http://localhost')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'authorization, content-type');

        $resposta = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector())->responder($pedido);

        self::assertSame(204, $resposta->getStatusCode());
        self::assertTrue($resposta->hasHeader('Access-Control-Allow-Methods'));
        self::assertSame(0, $this->passouNoPortao);
        self::assertSame(0, $this->passouNoLimite);

        $deFora = $this->servidor(['mcp:leitura'], [], new RequisicaoDoConector())
            ->responder($pedido->withHeader('Origin', 'https://claude.ai'));
        self::assertSame(403, $deFora->getStatusCode(), 'CORS fechado: os apps de I.A. chamam de servidor a servidor');
        self::assertSame(0, $this->passouNoPortao);
    }

    #[TestDox('o motivo que o portão anota em recusa vai ao log mcp com o status da resposta')]
    public function testRecusaDoPortaoVaiAoLog(): void
    {
        $registros = new TestHandler();
        $requisicao = new RequisicaoDoConector();

        $resposta = $this->servidor(['mcp:leitura'], [], $requisicao, new Logger('mcp', [$registros]), portao: $this->portaoQueRecusa($requisicao, 'consentimento_revogado'))
            ->responder(self::post('tools/list'));

        self::assertSame(503, $resposta->getStatusCode());
        self::assertSame(0, $this->passouNoLimite);
        self::assertCount(1, $registros->getRecords());
        $contexto = $registros->getRecords()[0]->context;
        self::assertSame(503, $contexto['status']);
        self::assertSame('consentimento_revogado', $contexto['recusa']);
    }

    #[TestDox('requisição aberta e depois recusada (o último uso não gravou): a linha tem 503, os ids e recusa=erro, nunca resultado=ok')]
    public function testAbertaERecusadaNaoEhSucesso(): void
    {
        $registros = new TestHandler();
        $requisicao = new RequisicaoDoConector();
        $portao = $this->portaoQueRecusa($requisicao, 'erro', self::chamada());

        $resposta = $this->servidor(['mcp:leitura'], [], $requisicao, new Logger('mcp', [$registros]), portao: $portao)
            ->responder(self::post('tools/list'));

        self::assertSame(503, $resposta->getStatusCode());
        self::assertSame(0, $this->passouNoLimite);
        $contexto = $registros->getRecords()[0]->context;
        self::assertSame(503, $contexto['status']);
        self::assertSame('erro', $contexto['recusa']);
        self::assertSame(41, $contexto['concessao']);
        self::assertArrayNotHasKey('resultado', $contexto);
        self::assertArrayNotHasKey('ferramenta', $contexto);
    }

    #[TestDox('o mesmo servidor atende chamadas seguidas sem guardar nada entre elas: recusado, aceito, sem token e step-up, cada um com a sua resposta e a sua linha')]
    public function testSemEstadoEntreChamadas(): void
    {
        $registros = new TestHandler();
        $requisicao = new RequisicaoDoConector();
        $ler = self::ferramenta('ler_clientes', new RespostaDaFerramenta(['clientes' => []], contagens: ['clientes' => 0]));
        $servidor = $this->servidor(['mcp:leitura'], [$ler], $requisicao, new Logger('mcp', [$registros]));

        $status = [];
        foreach ([
            self::post('tools/list', token: 'recusado'),
            self::post('tools/call', ['name' => 'ler_clientes', 'arguments' => new \stdClass()]),
            self::post('tools/list', token: null),
            self::post('tools/call', ['name' => 'atualizar_cliente', 'arguments' => new \stdClass()]),
        ] as $pedido) {
            $status[] = $servidor->responder($pedido)->getStatusCode();
            $requisicao->reset();
        }

        self::assertSame([401, 200, 401, 403], $status);
        $linhas = array_map(static fn ($r) => $r->context, $registros->getRecords());
        self::assertCount(4, $linhas);
        self::assertSame('ok', $linhas[1]['resultado']);
        foreach ([0, 2, 3] as $i) {
            self::assertArrayNotHasKey('resultado', $linhas[$i], 'a anotação da chamada aceita não passa para a seguinte');
            self::assertArrayNotHasKey('pessoa', $linhas[$i]);
        }
        self::assertSame(1, $this->passouNoPortao);
    }
}
