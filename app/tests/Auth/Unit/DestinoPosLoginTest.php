<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Service\DestinoPosLogin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(DestinoPosLogin::class)]
final class DestinoPosLoginTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function destinosAceitos(): iterable
    {
        yield 'caminho simples' => ['/agenda', '/agenda'];
        yield 'raiz' => ['/', '/'];
        yield 'caminho com query' => ['/agenda?dia=2026-10-19', '/agenda?dia=2026-10-19'];
        yield 'query com espaço codificado' => ['/busca?q=a%20b', '/busca?q=a%20b'];
        yield 'parecido com login, mas outra tela' => ['/loginx', '/loginx'];
        yield 'URL absoluta do próprio host' => ['http://localhost/agenda?dia=1', '/agenda?dia=1'];
        yield 'URL absoluta https do próprio host' => ['https://localhost/agenda', '/agenda'];
        yield 'host em maiúsculas' => ['http://LOCALHOST/agenda', '/agenda'];
        yield 'URL absoluta sem caminho' => ['http://localhost', '/'];
        yield 'fragmento cai na redução' => ['http://localhost/agenda#topo', '/agenda'];
    }

    #[DataProvider('destinosAceitos')]
    #[TestDox('Aceita $_dataName')]
    public function testAceitaDestinoDoProprioSite(string $destino, string $esperado): void
    {
        self::assertSame($esperado, (new DestinoPosLogin())->validar($destino, Request::create('http://localhost/login')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function destinosRecusados(): iterable
    {
        yield 'vazio' => [''];
        yield 'relativo ao protocolo' => ['//evil.com'];
        yield 'barra e barra invertida' => ['/\\evil.com'];
        yield 'barra invertida dupla' => ['\\\\evil.com'];
        yield 'barra invertida no meio' => ['/agenda\\x'];
        yield 'outro host' => ['https://evil.com'];
        yield 'host que começa igual' => ['http://localhost.evil.com/agenda'];
        yield 'host certo no caminho de outro' => ['http://evil.com/localhost'];
        yield 'host certo como usuário' => ['http://localhost@evil.com/'];
        yield 'host certo depois da interrogação' => ['http://evil.com?@localhost'];
        yield 'barra invertida antes da arroba' => ['http://localhost\\@evil.com'];
        yield 'mesmo host, outra porta' => ['http://localhost:8080/agenda'];
        yield 'mesmo host, caminho relativo ao protocolo' => ['http://localhost//evil.com'];
        yield 'esquema com uma barra' => ['http:/evil.com'];
        yield 'esquema com três barras' => ['https:///evil.com'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript em maiúsculas' => ['JAVASCRIPT:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'ftp do próprio host' => ['ftp://localhost/agenda'];
        yield 'sem barra na frente' => ['agenda'];
        yield 'barras codificadas sem barra na frente' => ['%2F%2Fevil.com'];
        yield 'barras codificadas depois da barra' => ['/%2F%2Fevil.com'];
        yield 'barra invertida codificada' => ['/%5Cevil.com'];
        yield 'tab codificado' => ['/%09/evil.com'];
        yield 'espaço à frente' => [' /agenda'];
        yield 'tab à frente' => ["\t/agenda"];
        yield 'quebra de linha à frente' => ["\n/agenda"];
        yield 'quebra de linha no fim' => ["/agenda\n"];
        yield 'tab no meio' => ["/\t/evil.com"];
        yield 'byte nulo' => ["/agenda\0"];
        yield 'login' => ['/login'];
        yield 'login com query' => ['/login?x=1'];
        yield 'login em maiúsculas' => ['/LOGIN'];
        yield 'o próprio véu' => ['/login/entrando?para=/agenda'];
        yield 'login absoluto do próprio host' => ['http://localhost/login'];
        yield 'logout' => ['/logout'];
        yield 'senha' => ['/senha/esqueci'];
        yield 'cadastro' => ['/cadastro'];
        yield 'login codificado' => ['/%6Cogin'];
        yield 'longo demais' => ['/' . str_repeat('a', 2100)];
    }

    #[DataProvider('destinosRecusados')]
    #[TestDox('Recusa $_dataName')]
    public function testRecusaDestinoPerigosoOuEmLaco(string $destino): void
    {
        self::assertNull((new DestinoPosLogin())->validar($destino, Request::create('http://localhost/login')));
    }

    #[TestDox('Destino nulo vira nulo')]
    public function testNuloViraNulo(): void
    {
        self::assertNull((new DestinoPosLogin())->validar(null, Request::create('http://localhost/login')));
    }

    #[TestDox('Com porta fora do padrão, só a mesma porta vale')]
    public function testPortaFazParteDoHost(): void
    {
        $sut = new DestinoPosLogin();
        $request = Request::create('http://bluejus.test:8080/login');

        self::assertSame('/agenda', $sut->validar('http://bluejus.test:8080/agenda', $request));
        self::assertNull($sut->validar('http://bluejus.test/agenda', $request));
        self::assertNull($sut->validar('http://bluejus.test:8081/agenda', $request));
    }
}
