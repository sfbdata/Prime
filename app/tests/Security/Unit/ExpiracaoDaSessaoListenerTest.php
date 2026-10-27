<?php

declare(strict_types=1);

namespace App\Tests\Security\Unit;

use App\Security\ExpiracaoDaSessaoListener;
use App\Shared\Contract\ConfiguracaoDaPlataforma;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ExpiracaoDaSessaoListener::class)]
final class ExpiracaoDaSessaoListenerTest extends TestCase
{
    private const PADRAO = 24;

    private Session $sessao;
    private TokenStorage $tokens;
    private int $inicio;

    protected function setUp(): void
    {
        $this->sessao = new Session(new MockArraySessionStorage());
        $this->sessao->start(); // carimba o último uso em time()
        $this->inicio = time();
        $this->tokens = new TokenStorage();
    }

    #[TestDox('Dentro do prazo, mantém a sessão e não responde')]
    public function testDentroDoPrazoMantem(): void
    {
        $this->entrar();
        $evento = $this->executar(['sessao' => '60'], minutosParado: 59);

        self::assertNull($evento->getResponse());
        self::assertNotNull($this->tokens->getToken());
    }

    #[TestDox('Passou do prazo: invalida a sessão, limpa o token e redireciona ao /login com o aviso')]
    public function testPassouInvalidaERedireciona(): void
    {
        $this->entrar();
        $this->sessao->set('marca', 'x');
        $evento = $this->executar(['sessao' => '60'], minutosParado: 61);

        self::assertSame(302, $evento->getResponse()?->getStatusCode());
        self::assertSame('/login', $evento->getResponse()->headers->get('Location'));
        self::assertNull($this->tokens->getToken());
        self::assertFalse($this->sessao->has('marca'));
        self::assertSame(['AVISO:entrada.sessao.expirada'], $this->sessao->getFlashBag()->get('error'));
    }

    #[TestDox('Pedido de dados (XHR ou Accept json) com a sessão vencida recebe 401 em JSON')]
    public function testXhrRecebe401(): void
    {
        $this->entrar();
        $evento = $this->executar(['sessao' => '60'], minutosParado: 61, cabecalhos: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertSame(401, $evento->getResponse()?->getStatusCode());
        self::assertJson((string) $evento->getResponse()->getContent());

        $this->setUp();
        $this->entrar();
        $evento = $this->executar(['sessao' => '60'], minutosParado: 61, cabecalhos: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(401, $evento->getResponse()?->getStatusCode());
    }

    #[TestDox('Quem entrou pelo lembrar-me não expira aqui')]
    public function testRememberMeNaoExpira(): void
    {
        $this->tokens->setToken(new RememberMeToken(new InMemoryUser('a@b.c', null, ['ROLE_USER']), 'main'));
        $evento = $this->executar(['sessao' => '60'], minutosParado: 600);

        self::assertNull($evento->getResponse());
        self::assertNotNull($this->tokens->getToken());
    }

    #[TestDox('Anônimo é ignorado')]
    public function testAnonimoIgnorado(): void
    {
        $evento = $this->executar(['sessao' => '60'], minutosParado: 600);

        self::assertNull($evento->getResponse());
    }

    #[TestDox('Requisição sem sessão anterior (sem cookie) é ignorada')]
    public function testSemSessaoAnterior(): void
    {
        $this->entrar();
        $evento = $this->executar(['sessao' => '60'], minutosParado: 600, cookie: false);

        self::assertNull($evento->getResponse());
        self::assertNotNull($this->tokens->getToken());
    }

    /** @return iterable<string, array{string}> */
    public static function rotasIgnoradas(): iterable
    {
        yield '/login' => ['/login'];
        yield '/login/google' => ['/login/google'];
        yield '/logout' => ['/logout'];
        yield 'asset' => ['/assets/app.js'];
    }

    #[DataProvider('rotasIgnoradas')]
    #[TestDox('A rota $caminho não é atingida pela expiração')]
    public function testRotasIgnoradas(string $caminho): void
    {
        $this->entrar();
        $evento = $this->executar(['sessao' => '60'], minutosParado: 600, caminho: $caminho);

        self::assertNull($evento->getResponse());
    }

    /** @return iterable<string, array{string}> */
    public static function valoresInvalidos(): iterable
    {
        yield 'vazio' => [''];
        yield 'texto' => ['abc'];
        yield 'abaixo do mínimo' => ['1'];
        yield 'acima do máximo' => ['99999'];
        yield 'negativo' => ['-5'];
    }

    #[DataProvider('valoresInvalidos')]
    #[TestDox('Valor inválido ("$valor") usa o padrão do ambiente (24 min)')]
    public function testValorInvalidoUsaOPadrao(string $valor): void
    {
        $this->entrar();
        self::assertNull($this->executar(['sessao' => $valor], minutosParado: self::PADRAO - 1)->getResponse());

        $this->setUp();
        $this->entrar();
        self::assertNotNull($this->executar(['sessao' => $valor], minutosParado: self::PADRAO + 1)->getResponse());
    }

    #[TestDox('Leitor que lança usa o padrão e nunca derruba a requisição')]
    public function testLeitorQueLancaUsaOPadrao(): void
    {
        $leitor = new class () implements ConfiguracaoDaPlataforma {
            public function valor(string $chave): string
            {
                throw new \RuntimeException('banco fora do ar');
            }
        };

        $this->entrar();
        self::assertNull($this->executar([], self::PADRAO - 1, leitor: $leitor)->getResponse());

        $this->setUp();
        $this->entrar();
        self::assertNotNull($this->executar([], self::PADRAO + 1, leitor: $leitor)->getResponse());
    }

    #[TestDox('Token de formulário com o cookie REMEMBERME não expira; sem o cookie, expira')]
    public function testCookieLembrarMeProtege(): void
    {
        $this->entrar();
        self::assertNull($this->executar(['sessao' => '60'], 600, cookies: ['REMEMBERME' => 'x'])->getResponse());

        $this->setUp();
        $this->entrar();
        self::assertNotNull($this->executar(['sessao' => '60'], 600)->getResponse());
    }

    /** @return iterable<string, array{string}> */
    public static function rotasPublicas(): iterable
    {
        yield 'convite' => ['/convite/abc'];
        yield 'senha' => ['/senha/esqueci'];
    }

    #[DataProvider('rotasPublicas')]
    #[TestDox('Rota PUBLIC_ACCESS $caminho não derruba a sessão velha')]
    public function testRotaPublicaNaoExpira(string $caminho): void
    {
        $this->entrar();
        self::assertNull($this->executar(['sessao' => '60'], 600, caminho: $caminho, publicas: [$caminho])->getResponse());
    }

    #[TestDox('O prazo efetivo é limitado pelo gc_maxlifetime: sessao=1440 com gc de 60 min expira em 61')]
    public function testGcLimitaOPrazo(): void
    {
        $this->entrar();
        self::assertNull($this->executar(['sessao' => '1440'], 59, gc: 3600)->getResponse());

        $this->setUp();
        $this->entrar();
        self::assertNotNull($this->executar(['sessao' => '1440'], 61, gc: 3600)->getResponse());
    }

    private function entrar(): void
    {
        $this->tokens->setToken(new PostAuthenticationToken(new InMemoryUser('a@b.c', null, ['ROLE_USER']), 'main', ['ROLE_USER']));
    }

    /**
     * @param array<string, string>  $config
     * @param array<string, string>  $cabecalhos
     * @param array<string, string>  $cookies
     * @param list<string>           $publicas caminhos que o access_control marca como PUBLIC_ACCESS
     */
    private function executar(
        array $config,
        int $minutosParado,
        string $caminho = '/painel',
        array $cabecalhos = [],
        bool $cookie = true,
        ?ConfiguracaoDaPlataforma $leitor = null,
        array $cookies = [],
        array $publicas = [],
        int $gc = 86400,
    ): RequestEvent {
        $request = Request::create($caminho, 'GET', [], ($cookie ? [$this->sessao->getName() => 'abc'] : []) + $cookies, [], $cabecalhos);
        $request->setSession($this->sessao);

        $relogio = new MockClock(new \DateTimeImmutable('@' . ($this->inicio + $minutosParado * 60)));
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/login');
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnCallback(static fn (string $id): string => 'AVISO:' . $id);

        $mapa = $this->createStub(AccessMapInterface::class);
        $mapa->method('getPatterns')->willReturnCallback(
            static fn (Request $r): array => [in_array($r->getPathInfo(), $publicas, true) ? ['PUBLIC_ACCESS'] : ['ROLE_USER'], null],
        );

        $listener = new ExpiracaoDaSessaoListener(
            $leitor ?? new ConfiguracaoDaPlataformaFalsa($config),
            $this->tokens,
            $urls,
            $tradutor,
            $relogio,
            self::PADRAO,
            $gc,
            $mapa,
        );
        $evento = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($evento);

        return $evento;
    }
}
