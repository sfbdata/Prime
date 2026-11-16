<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\HostsDeClientePermitidos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Os hosts do `client_id` em configuração (spec mcp-remoto-oauth 3.4 e 14.d; teste 12.12). */
#[CoversClass(HostsDeClientePermitidos::class)]
final class HostsDeClientePermitidosTest extends TestCase
{
    #[TestDox('Comparação exata em minúsculas: sem subdomínio implícito nem sufixo')]
    public function testComparacaoExata(): void
    {
        $hosts = new HostsDeClientePermitidos(['claude.ai', 'chatgpt.com']);

        self::assertTrue($hosts->permite('claude.ai'));
        self::assertTrue($hosts->permite('CLAUDE.AI'));
        self::assertTrue($hosts->permite('chatgpt.com'));
        self::assertFalse($hosts->permite('api.claude.ai'));
        self::assertFalse($hosts->permite('claude.ai.evil.com'));
        self::assertFalse($hosts->permite('evilclaude.ai'));
        self::assertFalse($hosts->permite('claude.ai.'));
        self::assertFalse($hosts->permite(''));
        self::assertFalse($hosts->permite(null));
    }

    #[TestDox('Trocar o parâmetro muda a lista; espaços, maiúsculas, vazios e repetidos são normalizados')]
    public function testOParametroMandaNaLista(): void
    {
        $outra = new HostsDeClientePermitidos([' Exemplo.com.BR ', '', 'exemplo.com.br', 42]);

        self::assertSame(['exemplo.com.br'], $outra->hosts());
        self::assertTrue($outra->permite('exemplo.com.br'));
        self::assertFalse($outra->permite('claude.ai'));
        self::assertFalse((new HostsDeClientePermitidos([]))->permite('claude.ai'));
    }
}
