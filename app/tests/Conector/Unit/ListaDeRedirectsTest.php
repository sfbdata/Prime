<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\ListaDeRedirects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A lista exata de `redirect_uri` do servidor (spec mcp-remoto-oauth 3.4 e 9.6; teste 12.12). */
#[CoversClass(ListaDeRedirects::class)]
final class ListaDeRedirectsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function aceitos(): iterable
    {
        yield 'claude.ai' => ['https://claude.ai/api/mcp/auth_callback'];
        yield 'chatgpt com RFC 9207' => ['https://chatgpt.com/connector_platform_oauth_redirect'];
        yield 'chatgpt com callback_id' => ['https://chatgpt.com/connector/oauth/abc_DEF-123'];
        yield 'loopback 127.0.0.1 com porta alta' => ['http://127.0.0.1:54321/callback'];
        yield 'loopback 127.0.0.1 com porta 1' => ['http://127.0.0.1:1/callback'];
        yield 'loopback localhost com porta' => ['http://localhost:8080/callback'];
        yield 'loopback localhost com a maior porta' => ['http://localhost:65535/callback'];
        yield 'loopback sem porta' => ['http://127.0.0.1/callback'];
    }

    /** @return iterable<string, array{string}> */
    public static function recusados(): iterable
    {
        yield 'claude.com (citado por terceiro, fora da lista)' => ['https://claude.com/api/mcp/auth_callback'];
        yield 'claude.ai com barra no fim' => ['https://claude.ai/api/mcp/auth_callback/'];
        yield 'claude.ai em http' => ['http://claude.ai/api/mcp/auth_callback'];
        yield 'claude.ai com query' => ['https://claude.ai/api/mcp/auth_callback?x=1'];
        yield 'chatgpt sem callback_id' => ['https://chatgpt.com/connector/oauth/'];
        yield 'chatgpt com caminho a mais' => ['https://chatgpt.com/connector/oauth/abc/def'];
        yield 'chatgpt com quebra de linha no fim' => ["https://chatgpt.com/connector/oauth/abc\n"];
        yield 'chatgpt em subdomínio' => ['https://evil.chatgpt.com/connector/oauth/abc'];
        yield 'chatgpt.com.evil.com' => ['https://chatgpt.com.evil.com/connector/oauth/abc'];
        yield 'localhost.evil.com' => ['http://localhost.evil.com:8080/callback'];
        yield 'localhost com usuário' => ['http://localhost@evil.com:8080/callback'];
        yield 'loopback em https' => ['https://127.0.0.1:8080/callback'];
        yield 'loopback IPv6' => ['http://[::1]:8080/callback'];
        yield 'loopback 127.0.0.2' => ['http://127.0.0.2:8080/callback'];
        yield 'loopback com outro caminho' => ['http://127.0.0.1:8080/outro'];
        yield 'loopback com quebra de linha no fim' => ["http://127.0.0.1:8080/callback\n"];
        yield 'loopback na porta 0' => ['http://127.0.0.1:0/callback'];
        yield 'loopback acima da maior porta' => ['http://localhost:65536/callback'];
        yield 'loopback com porta vazia' => ['http://localhost:/callback'];
        yield 'site qualquer' => ['https://evil.example/callback'];
    }

    #[DataProvider('aceitos')]
    #[TestDox('Na lista: $redirect')]
    public function testAceita(string $redirect): void
    {
        self::assertTrue((new ListaDeRedirects())->permite($redirect));
    }

    #[DataProvider('recusados')]
    #[TestDox('Fora da lista: $redirect')]
    public function testRecusa(string $redirect): void
    {
        self::assertFalse((new ListaDeRedirects())->permite($redirect));
    }

    #[TestDox('No documento: byte a byte, menos a porta do loopback (RFC 8252); host do loopback não se troca')]
    public function testConstaDoDocumento(): void
    {
        $lista = new ListaDeRedirects();
        $doClaudeCode = ['http://localhost/callback', 'http://127.0.0.1/callback'];

        self::assertTrue($lista->constaDoDocumento($doClaudeCode, 'http://127.0.0.1:54321/callback'));
        self::assertTrue($lista->constaDoDocumento($doClaudeCode, 'http://localhost:3000/callback'));
        self::assertTrue($lista->constaDoDocumento(['http://127.0.0.1:9999/callback'], 'http://127.0.0.1:1234/callback'));
        self::assertTrue($lista->constaDoDocumento(['https://claude.ai/api/mcp/auth_callback'], 'https://claude.ai/api/mcp/auth_callback'));

        self::assertFalse($lista->constaDoDocumento(['http://127.0.0.1/callback'], 'http://localhost:3000/callback'));
        self::assertFalse($lista->constaDoDocumento(['http://127.0.0.1/outro'], 'http://127.0.0.1:3000/callback'));
        self::assertFalse($lista->constaDoDocumento(['https://claude.ai/api/mcp/auth_callback/'], 'https://claude.ai/api/mcp/auth_callback'));
        self::assertFalse($lista->constaDoDocumento(['https://chatgpt.com/connector/oauth/abc'], 'https://chatgpt.com/connector/oauth/abd'));
        self::assertFalse($lista->constaDoDocumento(['http://localhost.evil.com/callback'], 'http://localhost:3000/callback'));
    }
}
