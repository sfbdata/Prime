<?php

declare(strict_types=1);

namespace App\Tests\Security\Unit;

use App\Entity\Auth\User;
use App\Security\SessaoEncerradaEmTodosOsAparelhosListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Sair de todos os aparelhos" na rota sem estado: o `/mcp` do conector de I.A. com o cookie do navegador junto não lê
 * nem invalida a sessão do navegador; a navegação comum continua caindo depois do corte.
 */
#[CoversClass(SessaoEncerradaEmTodosOsAparelhosListener::class)]
final class SessaoEncerradaEmTodosOsAparelhosListenerTest extends TestCase
{
    private Session $sessao;
    private TokenStorage $tokens;

    protected function setUp(): void
    {
        $this->sessao = new Session(new MockArraySessionStorage());
        $this->sessao->start();
        $this->sessao->set('marca', 'x');
        $this->tokens = new TokenStorage();

        $pessoa = new User();
        $pessoa->encerrarSessoes(new \DateTimeImmutable('+1 hour'));
        $this->tokens->setToken(new PostAuthenticationToken($pessoa, 'main', ['ROLE_USER']));
    }

    #[TestDox('Rota sem estado com o cookie do navegador e corte vencido: nem toca a sessão, nem limpa o token, nem responde')]
    public function testRotaSemEstadoNaoTocaASessao(): void
    {
        $usoAntes = $this->sessao->getUsageIndex();

        $evento = $this->executar(semEstado: true);

        self::assertNull($evento->getResponse());
        self::assertNotNull($this->tokens->getToken());
        self::assertSame($usoAntes, $this->sessao->getUsageIndex(), 'nem leitura nem escrita na sessão');
        self::assertTrue($this->sessao->has('marca'));
    }

    #[TestDox('Navegação com o corte vencido: invalida a sessão, limpa o token e responde 401 ao pedido de dados')]
    public function testNavegadorContinuaCaindo(): void
    {
        $evento = $this->executar(semEstado: false);

        self::assertSame(401, $evento->getResponse()?->getStatusCode());
        self::assertNull($this->tokens->getToken());
        self::assertFalse($this->sessao->has('marca'));
    }

    private function executar(bool $semEstado): RequestEvent
    {
        $request = Request::create('/mcp', 'POST', [], [$this->sessao->getName() => 'abc'], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setSession($this->sessao);
        if ($semEstado) {
            $request->attributes->set('_stateless', true);
        }

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/login');
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnArgument(0);

        $evento = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new SessaoEncerradaEmTodosOsAparelhosListener($this->tokens, $urls, $tradutor))($evento);

        return $evento;
    }
}
