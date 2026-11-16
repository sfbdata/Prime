<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Mcp\EscopoQueFaltaMiddleware;
use Http\Discovery\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * O corpo do 403 do step-up (spec mcp-remoto-oauth 5.1 e decisão 9 do PR 5) sempre em JSON-RPC 2.0 válido: com `id`,
 * o `result` com `isError` e o `_meta`; sem `id` (notificação ou lote), um objeto de erro com `id: null` e o mesmo
 * desafio em `error.data._meta`. O status e o `WWW-Authenticate` ficam como o SDK os fez.
 */
#[CoversClass(EscopoQueFaltaMiddleware::class)]
final class EscopoQueFaltaMiddlewareTest extends TestCase
{
    public const DESAFIO = 'Bearer resource_metadata="https://bluejus.com.br/.well-known/oauth-protected-resource/mcp", scope="mcp:leitura mcp:escrita", error="insufficient_scope"';

    /** @return iterable<string, array{string}> */
    public static function pedidosSemId(): iterable
    {
        yield 'notificação' => ['{"jsonrpc":"2.0","method":"tools/call","params":{"name":"atualizar_cliente"}}'];
        yield 'lote' => ['[{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"atualizar_cliente"}}]'];
        yield 'id nulo' => ['{"jsonrpc":"2.0","id":null,"method":"tools/call"}'];
        yield 'corpo ilegível' => ['{nada'];
    }

    #[DataProvider('pedidosSemId')]
    #[TestDox('sem id válido ($_dataName): erro JSON-RPC com id null, código e mensagem fixos e o desafio em error.data._meta, sem result')]
    public function testSemIdViraErro(string $corpo): void
    {
        $resposta = $this->processar($corpo);

        self::assertSame(403, $resposta->getStatusCode());
        self::assertSame(self::DESAFIO, $resposta->getHeaderLine('WWW-Authenticate'));
        self::assertSame('application/json', $resposta->getHeaderLine('Content-Type'));
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => EscopoQueFaltaMiddleware::CODIGO_SEM_ID,
                'message' => EscopoQueFaltaMiddleware::MENSAGEM,
                'data' => ['_meta' => [EscopoQueFaltaMiddleware::META => [self::DESAFIO]]],
            ],
        ], json_decode((string) $resposta->getBody(), true));
    }

    #[TestDox('com id: o result com isError e o _meta do ChatGPT, com o mesmo id')]
    public function testComIdViraResult(): void
    {
        $corpo = json_decode((string) $this->processar('{"jsonrpc":"2.0","id":7,"method":"tools/call"}')->getBody(), true);

        self::assertSame(7, $corpo['id']);
        self::assertArrayNotHasKey('error', $corpo);
        self::assertTrue($corpo['result']['isError']);
        self::assertSame([self::DESAFIO], $corpo['result']['_meta'][EscopoQueFaltaMiddleware::META]);
    }

    private function processar(string $corpo): ResponseInterface
    {
        $fabrica = new Psr17Factory();
        $pedido = $fabrica->createServerRequest('POST', 'http://localhost/mcp')->withBody($fabrica->createStream($corpo));
        $handler = new class ($fabrica) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $fabrica)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->fabrica->createResponse(403)->withHeader('WWW-Authenticate', EscopoQueFaltaMiddlewareTest::DESAFIO);
            }
        };

        return (new EscopoQueFaltaMiddleware($fabrica))->process($pedido, $handler);
    }
}
