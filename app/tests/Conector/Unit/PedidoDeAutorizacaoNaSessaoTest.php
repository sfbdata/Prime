<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\PedidoDeAutorizacao;
use App\Conector\Service\PedidoDeAutorizacaoNaSessao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * O pedido do `/oauth/authorize` entre o GET e o POST (spec mcp-remoto-oauth 9.4; 12.27): fica na sessão, sai por um
 * id aleatório de uso único, vence, e a sessão guarda poucos.
 */
#[CoversClass(PedidoDeAutorizacaoNaSessao::class)]
#[CoversClass(PedidoDeAutorizacao::class)]
final class PedidoDeAutorizacaoNaSessaoTest extends TestCase
{
    private Session $sessao;
    private MockClock $relogio;

    protected function setUp(): void
    {
        $this->sessao = new Session(new MockArraySessionStorage());
        $this->relogio = new MockClock('2026-11-16 10:00:00');
    }

    private function sut(): PedidoDeAutorizacaoNaSessao
    {
        $request = new Request();
        $request->setSession($this->sessao);
        $pilha = new RequestStack();
        $pilha->push($request);

        return new PedidoDeAutorizacaoNaSessao($pilha, $this->relogio);
    }

    private static function pedido(string $state = 'xyz', string $redirect = 'https://claude.ai/api/mcp/auth_callback'): PedidoDeAutorizacao
    {
        return new PedidoDeAutorizacao('https://claude.ai/oauth/x', $redirect, $state, str_repeat('a', 43), 'https://bluejus.com.br/mcp', ['mcp:leitura', 'offline_access']);
    }

    #[TestDox('guardar e retirar devolve o mesmo pedido; retirar de novo não devolve nada (uso único)')]
    public function testUsoUnico(): void
    {
        $sut = $this->sut();
        $id = $sut->guardar(self::pedido());

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $id);
        self::assertEquals(self::pedido(), $sut->retirar($id));
        self::assertNull($sut->retirar($id));
    }

    #[TestDox('ler devolve o pedido sem tirá-lo da sessão; vencido ou desconhecido não devolve nada')]
    public function testLerNaoGasta(): void
    {
        $sut = $this->sut();
        $id = $sut->guardar(self::pedido());

        self::assertEquals(self::pedido(), $sut->ler($id));
        self::assertEquals(self::pedido(), $sut->ler($id));
        self::assertNull($sut->ler('outro'));
        self::assertNull($sut->ler(null));
        self::assertEquals(self::pedido(), $sut->retirar($id));
        self::assertNull($sut->ler($id));

        $vencido = $sut->guardar(self::pedido());
        $this->relogio->sleep(1800);
        self::assertNull($sut->ler($vencido));
    }

    #[TestDox('id desconhecido, vazio ou nulo não devolve pedido e não tira os outros')]
    public function testIdDesconhecido(): void
    {
        $sut = $this->sut();
        $id = $sut->guardar(self::pedido());

        self::assertNull($sut->retirar('outro'));
        self::assertNull($sut->retirar(''));
        self::assertNull($sut->retirar(null));
        self::assertNotNull($sut->retirar($id));
    }

    #[TestDox('dois pedidos em abas diferentes: cada id devolve o seu')]
    public function testDoisPedidos(): void
    {
        $sut = $this->sut();
        $a = $sut->guardar(self::pedido('a'));
        $b = $sut->guardar(self::pedido('b'));

        self::assertSame('b', $sut->retirar($b)?->state);
        self::assertSame('a', $sut->retirar($a)?->state);
    }

    #[TestDox('o pedido vence em 30 minutos')]
    public function testVence(): void
    {
        $sut = $this->sut();
        $id = $sut->guardar(self::pedido());

        $this->relogio->sleep(1800);

        self::assertNull($sut->retirar($id));
    }

    #[TestDox('a sessão guarda no máximo 5 pedidos: o mais antigo sai')]
    public function testMaximo(): void
    {
        $sut = $this->sut();
        $ids = [];
        for ($i = 0; $i < 6; ++$i) {
            $ids[] = $sut->guardar(self::pedido((string) $i));
        }

        self::assertNull($sut->retirar($ids[0]));
        self::assertSame('5', $sut->retirar($ids[5])?->state);
    }

    #[TestDox('sessão adulterada (forma errada) não vira pedido')]
    public function testSessaoAdulterada(): void
    {
        $this->sessao->set('conector_oauth_pedidos', ['x' => ['criado_em' => $this->relogio->now()->getTimestamp(), 'pedido' => ['client_id' => 1]]]);

        self::assertNull($this->sut()->retirar('x'));
    }

    #[TestDox('o pedido mostra o host do client_id e do retorno, e reconhece o loopback')]
    public function testHostsDoPedido(): void
    {
        $pedido = self::pedido(redirect: 'http://127.0.0.1:5000/callback');

        self::assertSame('claude.ai', $pedido->hostDoCliente());
        self::assertSame('127.0.0.1', $pedido->hostDoRedirect());
        self::assertTrue($pedido->voltaParaLoopback());
        self::assertFalse(self::pedido()->voltaParaLoopback());
        self::assertTrue(self::pedido(redirect: 'http://localhost:8080/callback')->voltaParaLoopback());
        self::assertTrue(self::pedido()->pediuRenovacao());
        self::assertFalse(self::pedido()->pediuEscrita());
    }
}
