<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Mcp\LimiteDeChamadasMiddleware;
use App\Conector\Mcp\PortaoNoMcpMiddleware;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Repository\TokenDoConectorRepository;
use App\Conector\Service\ConferenciaDaConcessao;
use App\Conector\Service\PortaoDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\Service\ValidadorDoTokenDoConector;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * A cadeia PSR-15 do `/mcp` montada à mão, sem o controller (bloco A do PR 5): o `AuthorizationMiddleware` de verdade
 * do SDK com o validador do teste, depois o {@see PortaoNoMcpMiddleware} e o {@see LimiteDeChamadasMiddleware}, e
 * no fim um despacho falso que só conta. Os tokens de acesso e de renovação são gravados direto no banco, sobre o
 * cenário do servidor de autorização.
 */
trait CadeiaDoMcpTrait
{
    use CenarioDoServidorDeAutorizacaoTrait;

    /** Quantas vezes o despacho (o que viria depois do portão: o transporte do SDK e as ferramentas) rodou. */
    private int $despachos = 0;

    /**
     * Um validador e uma requisição por teste, montados com as peças do container: até o controller do `/mcp` (bloco
     * B) usá-los, o Symfony tira do container compilado os serviços privados sem uso (ou os embute no autenticador). O
     * mesmo objeto vai ao `AuthorizationMiddleware` e ao portão, como no container de verdade (um juiz só).
     */
    private ?ValidadorDoTokenDoConector $validadorDoTeste = null;
    private ?RequisicaoDoConector $requisicaoDoTeste = null;

    /** Grava um token de acesso da concessão e devolve o valor em texto (o que o app de I.A. manda no bearer). */
    private function tokenDeAcesso(ConcessaoDoConector $concessao): string
    {
        $segredo = (new SegredoOpaco())->gerar();
        $this->em()->persist(TokenDoConector::deAcesso($segredo['hash'], $concessao, $this->agora()));
        $this->em()->flush();

        return $segredo['valor'];
    }

    private function tokenDeRenovacao(ConcessaoDoConector $concessao): string
    {
        $segredo = (new SegredoOpaco())->gerar();
        $this->em()->persist(TokenDoConector::deRenovacao($segredo['hash'], $concessao, $this->agora()));
        $this->em()->flush();

        return $segredo['valor'];
    }

    private function validador(): ValidadorDoTokenDoConector
    {
        return $this->validadorDoTeste ??= new ValidadorDoTokenDoConector(
            static::getContainer()->get(TokenDoConectorRepository::class),
            new SegredoOpaco(),
            static::getContainer()->get(ConferenciaDaConcessao::class),
            Clock::get(),
        );
    }

    private function requisicao(): RequisicaoDoConector
    {
        return $this->requisicaoDoTeste ??= new RequisicaoDoConector();
    }

    private function middlewareDoPortao(?PortaoDoConector $portao = null): PortaoNoMcpMiddleware
    {
        $fabrica = new Psr17Factory();

        return new PortaoNoMcpMiddleware(
            $this->validador(),
            $portao ?? $this->portao(),
            $this->requisicao(),
            static::getContainer()->get(ConsentimentoDoConectorRepository::class),
            Clock::get(),
            $fabrica,
        );
    }

    private function middlewareDoLimite(int $porPessoa = 1000, int $porEscritorio = 1000): LimiteDeChamadasMiddleware
    {
        $fabrica = new Psr17Factory();
        $limitador = static fn (string $id, int $limite): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limite, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );

        return new LimiteDeChamadasMiddleware(
            $this->requisicao(),
            $limitador('conector_chamada_teste', $porPessoa),
            $limitador('conector_chamada_escritorio_teste', $porEscritorio),
            $fabrica,
            $fabrica,
        );
    }

    private function pedidoMcp(?string $bearer): ServerRequestInterface
    {
        $pedido = (new Psr17Factory())->createServerRequest('POST', 'http://localhost/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new Psr17Factory())->createStream('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));

        return $bearer === null ? $pedido : $pedido->withHeader('Authorization', 'Bearer ' . $bearer);
    }

    /**
     * Uma requisição do `/mcp` pela cadeia: autorização do SDK, portão e limite, nessa ordem (a do bloco B).
     */
    private function chamarMcp(?string $bearer, ?PortaoNoMcpMiddleware $portao = null, ?LimiteDeChamadasMiddleware $limite = null): ResponseInterface
    {
        $fabrica = new Psr17Factory();
        $metadado = new ProtectedResourceMetadata(ServidorDeAutorizacao::RESOURCE, [ServidorDeAutorizacao::ISSUER], ServidorDeAutorizacao::ESCOPOS_DO_RECURSO);
        /** @var list<MiddlewareInterface> $cadeia */
        $cadeia = [
            new AuthorizationMiddleware($this->validador(), $metadado, null, $fabrica, $fabrica),
            $portao ?? $this->middlewareDoPortao(),
            $limite ?? $this->middlewareDoLimite(),
        ];
        $despacho = function (): ResponseInterface {
            ++$this->despachos;

            return (new Psr17Factory())->createResponse(200);
        };

        return self::encadear($cadeia, $despacho)->handle($this->pedidoMcp($bearer));
    }

    /**
     * @param list<MiddlewareInterface>    $cadeia
     * @param callable(): ResponseInterface $fim
     */
    private static function encadear(array $cadeia, callable $fim): RequestHandlerInterface
    {
        $proximo = new class ($fim) implements RequestHandlerInterface {
            /** @param callable(): ResponseInterface $fim */
            public function __construct(private $fim)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->fim)();
            }
        };

        foreach (array_reverse($cadeia) as $middleware) {
            $proximo = new class ($middleware, $proximo) implements RequestHandlerInterface {
                public function __construct(private MiddlewareInterface $middleware, private RequestHandlerInterface $proximo)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->proximo);
                }
            };
        }

        return $proximo;
    }

    /** Fim da requisição (e mensagem seguinte do worker): o `kernel.reset` de todos os serviços. */
    private function fimDaRequisicao(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        $this->validador()->reset();
        $this->requisicao()->reset();
    }
}
