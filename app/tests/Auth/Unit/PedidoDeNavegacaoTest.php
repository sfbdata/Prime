<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Security\PedidoDeNavegacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(PedidoDeNavegacao::class)]
final class PedidoDeNavegacaoTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>, bool}>
     */
    public static function pedidos(): iterable
    {
        // Navegação: vai ao login e pode virar destino.
        yield 'navegador moderno abrindo a página' => ['GET', ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_ACCEPT' => 'text/html'], true];
        yield 'formulário enviado' => ['POST', ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_ACCEPT' => 'text/html'], true];
        yield 'navegador antigo abrindo a página' => ['GET', ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*;q=0.8'], true];
        yield 'Accept misto' => ['GET', ['HTTP_ACCEPT' => 'text/html,application/json'], true];
        yield 'Accept em maiúsculas' => ['GET', ['HTTP_ACCEPT' => 'TEXT/HTML'], true];
        yield 'navigate sem Dest, com text/html' => ['GET', ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_ACCEPT' => 'text/html'], true];
        // Pedido de dados: 401 em JSON, nunca destino.
        yield 'XHR' => ['GET', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html'], false];
        yield 'fetch moderno' => ['GET', ['HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_ACCEPT' => '*/*'], false];
        yield 'fetch same-origin pedindo HTML' => ['GET', ['HTTP_SEC_FETCH_MODE' => 'same-origin', 'HTTP_ACCEPT' => 'text/html'], false];
        yield 'iframe' => ['GET', ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_ACCEPT' => 'text/html'], false];
        yield 'Dest empty sem Mode' => ['GET', ['HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_ACCEPT' => 'text/html'], false];
        yield 'fetch antigo, Accept qualquer coisa' => ['GET', ['HTTP_ACCEPT' => '*/*'], false];
        yield 'quem só aceita JSON' => ['GET', ['HTTP_ACCEPT' => 'application/json'], false];
        yield 'navigate sem Dest, sem text/html' => ['GET', ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_ACCEPT' => '*/*'], false];
        yield 'Accept vazio' => ['GET', ['HTTP_ACCEPT' => ''], false];
    }

    /**
     * @param array<string, string> $cabecalhos
     */
    #[DataProvider('pedidos')]
    #[TestDox('$_dataName')]
    public function testDizSeENavegacao(string $metodo, array $cabecalhos, bool $esperado): void
    {
        $request = Request::create('/agenda', $metodo, [], [], [], $cabecalhos);

        self::assertSame($esperado, PedidoDeNavegacao::eNavegacao($request));
    }

    #[TestDox('Sem Accept nenhum (o KernelBrowser dos testes) é navegação; XHR sem Accept, não')]
    public function testSemAcceptEhNavegacao(): void
    {
        $request = Request::create('/agenda');
        $request->headers->remove('Accept');
        self::assertTrue(PedidoDeNavegacao::eNavegacao($request));

        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        self::assertFalse(PedidoDeNavegacao::eNavegacao($request));
    }

    #[TestDox('A resposta de sessão vencida é 401 em JSON com a frase do aviso')]
    public function testRespostaDeSessaoVencida(): void
    {
        $resposta = PedidoDeNavegacao::respostaDeSessaoVencida();

        self::assertSame(401, $resposta->getStatusCode());
        self::assertSame(['erro' => 'Sua sessão terminou. Entre de novo.'], json_decode((string) $resposta->getContent(), true));
    }

    #[TestDox('Quadro interno: navegação em iframe, frame, embed ou object; documento e fetch não são')]
    public function testQuadroInterno(): void
    {
        $pedido = static fn (array $cab): Request => Request::create('/x', 'GET', [], [], [], $cab);

        self::assertTrue(PedidoDeNavegacao::eQuadroInterno($pedido(['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'iframe'])));
        self::assertTrue(PedidoDeNavegacao::eQuadroInterno($pedido(['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'embed'])));
        self::assertFalse(PedidoDeNavegacao::eQuadroInterno($pedido(['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document'])));
        self::assertFalse(PedidoDeNavegacao::eQuadroInterno($pedido(['HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_DEST' => 'iframe'])));
        self::assertFalse(PedidoDeNavegacao::eQuadroInterno($pedido([])));
    }
}
