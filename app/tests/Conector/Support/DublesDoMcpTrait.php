<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ContextoDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Ferramenta\DespachoDasFerramentas;
use App\Conector\Ferramenta\FerramentaDoConector;
use App\Conector\Mcp\EscopoQueFaltaMiddleware;
use App\Conector\Mcp\RegistroDaChamadaMiddleware;
use App\Conector\Mcp\ServidorMcpDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * O `/mcp` montado com dublês no lugar do validador do token, do portão e do limite (o bloco A do PR 5), para provar a
 * cadeia do SDK, o despacho e o log sem depender deles: o validador aceita qualquer bearer com os escopos dados (ou
 * recusa `recusado`, e julga `desligado` como conector suspenso), o portão abre a requisição com a chamada dada e conta quantas vezes passou, o limite só conta.
 */
trait DublesDoMcpTrait
{
    private const TOKEN = 'token-de-teste';
    private const MODERNA = '2026-07-28';

    public int $passouNoPortao = 0;
    public int $passouNoLimite = 0;

    /**
     * @param list<string>               $escopos     os escopos que o validador dublê concede
     * @param iterable<FerramentaDoConector> $ferramentas
     * @param list<string>               $hosts
     */
    private function servidor(
        array $escopos,
        iterable $ferramentas,
        RequisicaoDoConector $requisicao,
        LoggerInterface $logger = new NullLogger(),
        ?ChamadaDoConector $chamada = null,
        array $hosts = ['localhost'],
        ?MiddlewareInterface $portao = null,
    ): ServidorMcpDoConector {
        $fabrica = new Psr17Factory();
        $validador = self::validador($escopos);

        return new ServidorMcpDoConector(
            new DespachoDasFerramentas($ferramentas, $requisicao),
            $validador,
            new RegistroDaChamadaMiddleware($logger, $requisicao, $validador),
            new EscopoQueFaltaMiddleware($fabrica),
            $portao ?? $this->portaoDuble($requisicao, $chamada ?? self::chamada($escopos)),
            $this->limiteDuble(),
            $hosts,
            $fabrica,
            $fabrica,
        );
    }

    /** @param list<string> $escopos */
    private static function validador(array $escopos): AuthorizationTokenValidatorInterface
    {
        return new class ($escopos) implements AuthorizationTokenValidatorInterface {
            /** @param list<string> $escopos */
            public function __construct(private readonly array $escopos)
            {
            }

            public function validate(string $accessToken): AuthorizationResult
            {
                return match ($accessToken) {
                    'recusado' => AuthorizationResult::unauthorized('invalid_token', 'Token recusado.'),
                    // O julgamento do conector suspenso, igual ao do validador de verdade.
                    'desligado' => AuthorizationResult::forbidden(ValidadorDoTokenDoConector::ERRO_CONECTOR_DESLIGADO, ValidadorDoTokenDoConector::DESCRICAO_LIBERACAO, []),
                    default => AuthorizationResult::allow(new AccessToken($this->escopos, ['sub' => 'dublê'])),
                };
            }
        };
    }

    private function portaoDuble(RequisicaoDoConector $requisicao, ChamadaDoConector $chamada): MiddlewareInterface
    {
        return new class ($this, $requisicao, $chamada) implements MiddlewareInterface {
            public function __construct(private readonly object $teste, private readonly RequisicaoDoConector $requisicao, private readonly ChamadaDoConector $chamada)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                ++$this->teste->passouNoPortao;
                $this->requisicao->abrir($this->chamada);

                return $handler->handle($request);
            }
        };
    }

    /**
     * Um portão que recusa como o do bloco A: anota o motivo em `recusa` (`PortaoNoMcpMiddleware::ANOTACAO_RECUSA`) e
     * responde 503 sem chamar o resto da cadeia. Com `$abertaAntes`, abre a requisição antes de recusar, como o portão
     * de verdade quando a gravação do último uso falha (motivo `erro`, `PortaoNoMcpMiddleware::MOTIVO_ERRO`).
     */
    private function portaoQueRecusa(RequisicaoDoConector $requisicao, string $motivo, ?ChamadaDoConector $abertaAntes = null): MiddlewareInterface
    {
        return new class ($this, $requisicao, $motivo, $abertaAntes) implements MiddlewareInterface {
            public function __construct(private readonly object $teste, private readonly RequisicaoDoConector $requisicao, private readonly string $motivo, private readonly ?ChamadaDoConector $abertaAntes)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                ++$this->teste->passouNoPortao;
                if ($this->abertaAntes !== null) {
                    $this->requisicao->abrir($this->abertaAntes);
                }
                $this->requisicao->anotar('recusa', $this->motivo);

                return (new Psr17Factory())->createResponse(503);
            }
        };
    }

    private function limiteDuble(): MiddlewareInterface
    {
        return new class ($this) implements MiddlewareInterface {
            public function __construct(private readonly object $teste)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                ++$this->teste->passouNoLimite;

                return $handler->handle($request);
            }
        };
    }

    /** @param list<string> $escopos */
    private static function chamada(array $escopos = ['mcp:leitura'], ?User $pessoa = null, ?Tenant $escritorio = null, ?UserTenant $vinculo = null): ChamadaDoConector
    {
        $pessoa ??= (new User())->setFullName('Pessoa Dublê');
        $escritorio ??= (new Tenant())->setName('Escritório Dublê');

        return new ChamadaDoConector(
            new ContextoDoConector($pessoa, $escritorio, $vinculo ?? new UserTenant($pessoa, $escritorio)),
            concessaoId: 41,
            consentimentoId: 42,
            hostDoCliente: 'claude.ai',
            escopos: $escopos,
            conexaoValeAte: new \DateTimeImmutable('2026-11-23 10:00:00'),
        );
    }

    /**
     * Uma ferramenta de mentira com o nome dado (do catálogo, ou não), um título próprio que o despacho tem de ignorar e
     * a resposta (ou a exceção) dada.
     *
     * @param array<string, mixed> $propriedades
     */
    private static function ferramenta(string $nome, RespostaDaFerramenta|\Throwable $resultado, array $propriedades = ['cpf' => ['type' => 'string']]): FerramentaDoConector
    {
        return new class ($nome, $resultado, $propriedades) implements FerramentaDoConector {
            /** @var list<array<string, mixed>> */
            public array $recebidos = [];

            /** @param array<string, mixed> $propriedades */
            public function __construct(private readonly string $nome, private readonly RespostaDaFerramenta|\Throwable $resultado, private readonly array $propriedades)
            {
            }

            public function nome(): string
            {
                return $this->nome;
            }

            public function titulo(): string
            {
                return 'Título que não vale';
            }

            public function descricao(): string
            {
                return 'Ferramenta de teste.';
            }

            public function esquemaDeEntrada(): array
            {
                return ['type' => 'object', 'properties' => $this->propriedades, 'additionalProperties' => false];
            }

            public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
            {
                $this->recebidos[] = $argumentos;
                if ($this->resultado instanceof \Throwable) {
                    throw $this->resultado;
                }

                return $this->resultado;
            }
        };
    }

    /**
     * Um POST JSON-RPC ao `/mcp` na era do `initialize` (2025-06-18), sem sessão.
     *
     * @param array<string, mixed> $params
     */
    private static function post(string $metodo, array $params = [], ?string $token = self::TOKEN, int|string $id = 1, string $host = 'localhost'): ServerRequestInterface
    {
        $corpo = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $metodo];
        if ($params !== []) {
            $corpo['params'] = $params;
        }
        $fabrica = new Psr17Factory();
        $pedido = $fabrica->createServerRequest('POST', 'http://' . $host . '/mcp')
            ->withHeader('Host', $host)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withHeader('MCP-Protocol-Version', '2025-06-18')
            ->withBody($fabrica->createStream(json_encode($corpo, \JSON_THROW_ON_ERROR)));

        return $token === null ? $pedido : $pedido->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * Um POST na era sem estado (2026-07-28): versão, capacidades e o método no cabeçalho, como o SDK exige.
     *
     * @param array<string, mixed> $params
     */
    private static function postModerno(string $metodo, array $params = []): ServerRequestInterface
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::MODERNA,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $fabrica = new Psr17Factory();

        return $fabrica->createServerRequest('POST', 'http://localhost/mcp')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withHeader('MCP-Protocol-Version', self::MODERNA)
            ->withHeader('Mcp-Method', $metodo)
            ->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->withBody($fabrica->createStream(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => $metodo, 'params' => $params], \JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private static function corpo(ResponseInterface $resposta): array
    {
        $corpo = json_decode((string) $resposta->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($corpo);

        return $corpo;
    }
}
