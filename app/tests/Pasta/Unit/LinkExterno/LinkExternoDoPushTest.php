<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\LinkExterno;

use App\Pasta\Entity\EstadoDoLinkExterno;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Exception\LinkExternoDoPushException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkExternoDoPush::class)]
final class LinkExternoDoPushTest extends TestCase
{
    use CriaLinkExternoTrait;

    #[TestDox('Nasce ativo e vale 30 dias a partir da criação')]
    public function testNasceAtivoPor30Dias(): void
    {
        $link = $this->link();

        self::assertSame(EstadoDoLinkExterno::Ativo, $link->estado($this->agora()));
        self::assertEquals($this->agora()->modify('+30 days'), $link->getExpiraEm());
        self::assertSame(EstadoDoLinkExterno::Ativo, $link->estado($this->agora()->modify('+30 days -1 second')));
        self::assertSame(EstadoDoLinkExterno::Expirado, $link->estado($this->agora()->modify('+30 days')));
    }

    #[TestDox('Revogado vence expirado; revogar de novo não muda quem nem quando')]
    public function testRevogadoTemPrecedenciaEIdempotente(): void
    {
        $link = $this->linkExpirado();
        $quem = $this->usuario('Primeiro');
        $link->revogar($quem, $this->agora());
        $link->revogar($this->usuario('Segundo'), $this->agora()->modify('+1 hour'));

        self::assertSame(EstadoDoLinkExterno::Revogado, $link->estado($this->agora()));
        self::assertSame($quem, $link->getRevogadoPor());
        self::assertEquals($this->agora(), $link->getRevogadoEm());
    }

    #[TestDox('Renovar dá mais 30 dias a partir de agora e responde o pedido pendente como renovado')]
    public function testRenovar(): void
    {
        $link = $this->linkExpirado();
        $link->pedirRenovacao('Carlos', 'Preciso acompanhar', $this->agora());
        self::assertSame(EstadoDoLinkExterno::RenovacaoPedida, $link->estado($this->agora()));

        $link->renovar($this->agora());

        self::assertSame(EstadoDoLinkExterno::Ativo, $link->estado($this->agora()));
        self::assertEquals($this->agora()->modify('+30 days'), $link->getExpiraEm());
        self::assertSame('renovado', $link->getRenovacaoResposta());
    }

    #[TestDox('Renovar recusa link ativo e link revogado')]
    public function testRenovarRecusaAtivoERevogado(): void
    {
        try {
            $this->link()->renovar($this->agora());
            self::fail('Ativo não pode ser renovado');
        } catch (LinkExternoDoPushException $e) {
            self::assertSame('Só um link expirado pode ser renovado.', $e->getMessage());
        }

        $revogado = $this->linkExpirado();
        $revogado->revogar($this->usuario(), $this->agora());
        $this->expectExceptionObject(LinkExternoDoPushException::renovarRevogado());
        $revogado->renovar($this->agora());
    }

    #[TestDox('Pedido de renovação: só expirado, sem pendente nem recusado; recusa fecha novos pedidos')]
    public function testPedidoDeRenovacao(): void
    {
        self::assertFalse($this->link()->aceitaPedidoDeRenovacao($this->agora()));

        $link = $this->linkExpirado();
        self::assertTrue($link->aceitaPedidoDeRenovacao($this->agora()));
        $link->pedirRenovacao('  ' . str_repeat('N', 70) . '  ', '', $this->agora());
        self::assertSame(60, mb_strlen((string) $link->getRenovacaoNome()));
        self::assertNull($link->getRenovacaoMensagem());
        self::assertTrue($link->temPedidoPendente());
        self::assertFalse($link->aceitaPedidoDeRenovacao($this->agora()));

        $link->recusarRenovacao();
        self::assertTrue($link->renovacaoFoiRecusada());
        self::assertSame(EstadoDoLinkExterno::Expirado, $link->estado($this->agora()));
        self::assertFalse($link->aceitaPedidoDeRenovacao($this->agora()));

        $this->expectExceptionObject(LinkExternoDoPushException::pedidoIndisponivel());
        $link->pedirRenovacao('Outro', null, $this->agora());
    }

    #[TestDox('Recusar sem pedido pendente é recusado')]
    public function testRecusarSemPedido(): void
    {
        $this->expectExceptionObject(LinkExternoDoPushException::semPedidoPendente());
        $this->linkExpirado()->recusarRenovacao();
    }

    #[TestDox('Código: acerto zera as tentativas; 5 erros seguidos bloqueiam por 15 minutos, inclusive o código certo')]
    public function testTentativasEBloqueio(): void
    {
        $link = $this->link('123456');
        $agora = $this->agora();

        self::assertFalse($link->conferirCodigo('000000', $agora));
        self::assertFalse($link->conferirCodigo('000001', $agora));
        self::assertSame(2, $link->getTentativasCodigo());
        self::assertTrue($link->conferirCodigo('123456', $agora));
        self::assertSame(0, $link->getTentativasCodigo());

        for ($i = 0; $i < 5; ++$i) {
            self::assertFalse($link->conferirCodigo('999999', $agora));
        }

        self::assertTrue($link->estaBloqueado($agora));
        self::assertFalse($link->conferirCodigo('123456', $agora->modify('+14 minutes')));
        self::assertFalse($link->estaBloqueado($agora->modify('+15 minutes')));
        self::assertTrue($link->conferirCodigo('123456', $agora->modify('+15 minutes')));
    }

    #[TestDox('Link sem código não pede código')]
    public function testSemCodigo(): void
    {
        $link = $this->link();

        self::assertFalse($link->exigeCodigo());
        self::assertTrue($link->conferirCodigo('', $this->agora()));
    }
}
