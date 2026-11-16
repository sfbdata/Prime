<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ContextoDoConector;
use App\Conector\Mcp\LimiteDeChamadasMiddleware;
use App\Conector\Mcp\PortaoNoMcpMiddleware;
use App\Conector\Service\RequisicaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Http\Discovery\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Os limites de chamadas do `/mcp` (spec mcp-remoto-oauth 8): por pessoa e escritório, e pelo escritório inteiro;
 * passou, 429 com `Retry-After` e nada despachado; sem requisição aberta pelo portão, 503.
 */
#[CoversClass(LimiteDeChamadasMiddleware::class)]
final class LimiteDeChamadasMiddlewareTest extends TestCase
{
    private int $despachos = 0;
    private RequisicaoDoConector $requisicao;

    #[TestDox('por pessoa e escritório: a chamada além do limite recebe 429 com Retry-After e não despacha')]
    public function testLimitePorPessoa(): void
    {
        $sut = $this->sut(porPessoa: 2, porEscritorio: 100);

        self::assertSame(200, $this->chamar($sut, 10, 7)->getStatusCode());
        self::assertSame(200, $this->chamar($sut, 10, 7)->getStatusCode());
        $resposta = $this->chamar($sut, 10, 7);

        self::assertSame(429, $resposta->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $resposta->getHeaderLine('Retry-After'));
        self::assertStringContainsString(LimiteDeChamadasMiddleware::MENSAGEM, (string) $resposta->getBody());
        self::assertSame(2, $this->despachos);
        self::assertSame(200, $this->chamar($sut, 11, 7)->getStatusCode(), 'outra pessoa no mesmo escritório tem o próprio limite');
        self::assertSame(200, $this->chamar($sut, 10, 8)->getStatusCode(), 'a mesma pessoa em outro escritório tem o próprio limite');
    }

    #[TestDox('por escritório: a soma das pessoas do escritório passa do limite e recebe 429; outro escritório segue')]
    public function testLimitePorEscritorio(): void
    {
        $sut = $this->sut(porPessoa: 100, porEscritorio: 2);

        self::assertSame(200, $this->chamar($sut, 10, 7)->getStatusCode());
        self::assertSame(200, $this->chamar($sut, 11, 7)->getStatusCode());
        $resposta = $this->chamar($sut, 12, 7);

        self::assertSame(429, $resposta->getStatusCode());
        self::assertTrue($resposta->hasHeader('Retry-After'));
        self::assertSame(200, $this->chamar($sut, 12, 8)->getStatusCode());
        self::assertSame(3, $this->despachos);
    }

    #[TestDox('sem requisição aberta pelo portão: 503 e nada despachado')]
    public function testSemRequisicaoAberta(): void
    {
        $resposta = $this->sut()->process((new Psr17Factory())->createServerRequest('POST', 'http://localhost/mcp'), $this->despacho());

        self::assertSame(503, $resposta->getStatusCode());
        self::assertSame(0, $this->despachos);
    }

    private function sut(int $porPessoa = 100, int $porEscritorio = 100): LimiteDeChamadasMiddleware
    {
        $this->requisicao = new RequisicaoDoConector();
        $fabrica = new Psr17Factory();
        $armazenamento = new InMemoryStorage();

        return new LimiteDeChamadasMiddleware(
            $this->requisicao,
            new RateLimiterFactory(['id' => 'conector_chamada', 'policy' => 'sliding_window', 'limit' => $porPessoa, 'interval' => '1 minute'], $armazenamento),
            new RateLimiterFactory(['id' => 'conector_chamada_escritorio', 'policy' => 'sliding_window', 'limit' => $porEscritorio, 'interval' => '1 minute'], $armazenamento),
            $fabrica,
            $fabrica,
        );
    }

    private function chamar(LimiteDeChamadasMiddleware $sut, int $pessoaId, int $escritorioId): ResponseInterface
    {
        $this->requisicao->reset();
        $this->requisicao->abrir($this->chamada($pessoaId, $escritorioId));

        $resposta = $sut->process((new Psr17Factory())->createServerRequest('POST', 'http://localhost/mcp'), $this->despacho());
        if ($resposta->getStatusCode() === 429) {
            self::assertSame(LimiteDeChamadasMiddleware::MOTIVO_LIMITE, $this->requisicao->anotacao(PortaoNoMcpMiddleware::ANOTACAO_RECUSA));
        }

        return $resposta;
    }

    private function chamada(int $pessoaId, int $escritorioId): ChamadaDoConector
    {
        $pessoa = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, $pessoaId);
        $escritorio = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($escritorio, $escritorioId);

        return new ChamadaDoConector(new ContextoDoConector($pessoa, $escritorio, new UserTenant($pessoa, $escritorio)), 1, 1, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));
    }

    private function despacho(): RequestHandlerInterface
    {
        $contar = function (): void {
            ++$this->despachos;
        };

        return new class ($contar) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $contar)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->contar)();

                return (new Psr17Factory())->createResponse(200);
            }
        };
    }
}
