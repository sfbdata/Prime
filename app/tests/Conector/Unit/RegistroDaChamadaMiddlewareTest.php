<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Mcp\RegistroDaChamadaMiddleware;
use App\Conector\Service\RequisicaoDoConector;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;

/**
 * A linha do log `mcp` leva o método JSON-RPC e a versão do protocolo só quando têm o formato esperado (spec
 * mcp-remoto-oauth, regra 9): nada mais do corpo vai ao log, e ler o corpo nunca muda o que o SDK recebe.
 */
#[CoversClass(RegistroDaChamadaMiddleware::class)]
final class RegistroDaChamadaMiddlewareTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $linhas = [];

    private ?string $corpoQueOSdkLeu = null;

    #[TestDox('método e versão no formato vão à linha; o SDK lê o corpo inteiro depois')]
    public function testMetodoEVersao(): void
    {
        $corpo = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"quem_sou_eu","arguments":{"texto":"segredo"}}}';

        $this->processar($corpo, '2025-06-18');

        self::assertSame('tools/call', $this->linhas[0]['metodo'] ?? null);
        self::assertSame('2025-06-18', $this->linhas[0]['versao'] ?? null);
        self::assertSame($corpo, $this->corpoQueOSdkLeu, 'o corpo volta ao começo para o SDK');
        self::assertStringNotContainsString('segredo', (string) json_encode($this->linhas), 'argumento nunca vai ao log');
        self::assertStringNotContainsString('quem_sou_eu', (string) json_encode($this->linhas));
    }

    /** @return iterable<string, array{string}> */
    public static function corposSemMetodo(): iterable
    {
        yield 'lote (lista de mensagens)' => ['[{"jsonrpc":"2.0","id":1,"method":"tools/list"}]'];
        yield 'JSON inválido' => ['{"method":'];
        yield 'corpo vazio' => [''];
        yield 'método com espaço e nome' => ['{"method":"tools/call Maria da Silva"}'];
        yield 'método com quebra de linha no fim' => ["{\"method\":\"tools/list\\n\"}"];
        yield 'método longo demais' => ['{"method":"' . str_repeat('a', 61) . '"}'];
        yield 'método que não é texto' => ['{"method":["tools/list"]}'];
        yield 'corpo maior que o limite' => ['{"method":"tools/list","x":"' . str_repeat('a', 70_000) . '"}'];
    }

    #[DataProvider('corposSemMetodo')]
    #[TestDox('$_dataName: sem método na linha, sem lançar, e o SDK recebe o corpo intacto')]
    public function testCorpoForaDoFormato(string $corpo): void
    {
        $this->processar($corpo, 'versão 1; DROP');

        self::assertCount(1, $this->linhas);
        self::assertArrayNotHasKey('metodo', $this->linhas[0]);
        self::assertArrayNotHasKey('versao', $this->linhas[0]);
        self::assertSame(200, $this->linhas[0]['status']);
        self::assertSame($corpo, $this->corpoQueOSdkLeu);
    }

    #[TestDox('corpo que não volta ao começo não é lido: a linha sai sem método e a resposta não muda')]
    public function testCorpoNaoSeekable(): void
    {
        $corpo = $this->createStub(StreamInterface::class);
        $corpo->method('isSeekable')->willReturn(false);
        $corpo->method('getSize')->willReturn(10);
        $corpo->method('getContents')->willThrowException(new \LogicException('não pode ser lido'));
        $pedido = (new Psr17Factory())->createServerRequest('POST', 'http://localhost/mcp')->withBody($corpo);

        $resposta = $this->sut()->process($pedido, $this->despacho());

        self::assertSame(200, $resposta->getStatusCode());
        self::assertArrayNotHasKey('metodo', $this->linhas[0]);
    }

    private function processar(string $corpo, string $versao): void
    {
        $fabrica = new Psr17Factory();
        $pedido = $fabrica->createServerRequest('POST', 'http://localhost/mcp')
            ->withHeader('MCP-Protocol-Version', $versao)
            ->withBody($fabrica->createStream($corpo));

        $this->sut()->process($pedido, $this->despacho());
    }

    private function sut(): RegistroDaChamadaMiddleware
    {
        $teste = $this;
        $logger = new class ($teste) extends AbstractLogger {
            public function __construct(private readonly RegistroDaChamadaMiddlewareTest $teste)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->teste->guardarLinha($context);
            }
        };

        return new RegistroDaChamadaMiddleware($logger, new RequisicaoDoConector(), $this->createStub(AuthorizationTokenValidatorInterface::class));
    }

    /** @param array<string, mixed> $linha */
    public function guardarLinha(array $linha): void
    {
        $this->linhas[] = $linha;
    }

    private function despacho(): RequestHandlerInterface
    {
        $teste = $this;

        return new class ($teste) implements RequestHandlerInterface {
            public function __construct(private readonly RegistroDaChamadaMiddlewareTest $teste)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->teste->sdkLeu($request);

                return (new Psr17Factory())->createResponse(200);
            }
        };
    }

    public function sdkLeu(ServerRequestInterface $request): void
    {
        $corpo = $request->getBody();
        if ($corpo->isSeekable()) {
            $this->corpoQueOSdkLeu = $corpo->getContents();
        }
    }
}
