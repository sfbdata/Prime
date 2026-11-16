<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Exception\ClienteRecusado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClienteRecusado::class)]
final class ClienteRecusadoTest extends TestCase
{
    #[TestDox('Leva o motivo e o host; a mensagem à pessoa é uma só, sem a URL do app')]
    public function testMotivoEHost(): void
    {
        $recusa = new ClienteRecusado(ClienteRecusado::MOTIVO_HOST_NAO_PERMITIDO, 'exemplo.com');

        self::assertSame('host_nao_permitido', $recusa->motivo);
        self::assertSame('exemplo.com', $recusa->host);
        self::assertSame(ClienteRecusado::MENSAGEM, $recusa->getMessage());
    }

    #[TestDox('Motivo fora da lista fechada não existe')]
    public function testMotivoDesconhecido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClienteRecusado('outro', null);
    }

    #[TestDox('hostDe tira o host em minúsculas; o que não é URL com host dá nulo')]
    public function testHostDe(): void
    {
        self::assertSame('claude.ai', ClienteRecusado::hostDe('https://Claude.AI/oauth/claude-code-client-metadata'));
        self::assertNull(ClienteRecusado::hostDe('meu-app'));
        self::assertNull(ClienteRecusado::hostDe(''));
    }
}
