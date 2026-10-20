<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\Cadeado;

use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\EstadoDoPedidoDeAcesso;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CadeadoDaPasta::class)]
#[CoversClass(PedidoDeAcessoAPasta::class)]
final class CadeadoDaPastaTest extends TestCase
{
    use CriaCenarioDoCadeadoTrait;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    #[TestDox('a grade é atualizada no lugar: quem continua não é apagado e regravado; quem fica Oculto sai da lista')]
    public function testGradeAtualizaNoLugar(): void
    {
        [$ana, $bia] = [$this->pessoa('Ana'), $this->pessoa('Bia')];
        $cadeado = new CadeadoDaPasta($this->tenant, $this->pasta(), ModoDoCadeado::PorPessoa, null, new \DateTimeImmutable());
        $cadeado->definirPorPessoa([[$ana, NivelDeAcesso::Editar], [$bia, NivelDeAcesso::Visualizar]], null, new \DateTimeImmutable());
        $linhaDaAna = $cadeado->getAcessos()[0];

        $cadeado->definirPorPessoa([[$ana, NivelDeAcesso::Visualizar], [$bia, NivelDeAcesso::Oculto]], null, new \DateTimeImmutable());

        self::assertCount(1, $cadeado->getAcessos());
        self::assertSame($linhaDaAna, $cadeado->getAcessos()[0]);
        self::assertSame(NivelDeAcesso::Visualizar, $cadeado->nivelDe($ana));
        self::assertSame(NivelDeAcesso::Oculto, $cadeado->nivelDe($bia));
    }

    #[TestDox('liberar no modo Todos converte para PorPessoa: os demais Ocultos e o solicitante no nível liberado')]
    public function testLiberarConverteTodos(): void
    {
        [$ana, $bia] = [$this->pessoa('Ana'), $this->pessoa('Bia')];
        $cadeado = new CadeadoDaPasta($this->tenant, $this->pasta(), ModoDoCadeado::Todos, null, new \DateTimeImmutable());

        $cadeado->liberar($ana, NivelDeAcesso::Visualizar, null, new \DateTimeImmutable());

        self::assertSame(ModoDoCadeado::PorPessoa, $cadeado->getModo());
        self::assertSame(NivelDeAcesso::Visualizar, $cadeado->nivelDe($ana));
        self::assertSame(NivelDeAcesso::Oculto, $cadeado->nivelDe($bia));
    }

    #[TestDox('ocultar para todos apaga a lista de pessoas')]
    public function testOcultarParaTodosApagaALista(): void
    {
        $ana     = $this->pessoa('Ana');
        $cadeado = new CadeadoDaPasta($this->tenant, $this->pasta(), ModoDoCadeado::PorPessoa, null, new \DateTimeImmutable());
        $cadeado->definirPorPessoa([[$ana, NivelDeAcesso::Editar]], null, new \DateTimeImmutable());

        $cadeado->ocultarParaTodos(null, new \DateTimeImmutable());

        self::assertSame([], $cadeado->getAcessos());
        self::assertSame(NivelDeAcesso::Oculto, $cadeado->nivelDe($ana));
    }

    #[TestDox('pedido: aguarda 24 h; dentro da janela não renova; depois renova o mesmo pendente')]
    public function testJanelaDoPedido(): void
    {
        $pedidoEm = new \DateTimeImmutable('2026-10-20 10:00');
        $pedido   = new PedidoDeAcessoAPasta($this->tenant, $this->pasta(), $this->pessoa('Ana'), NivelDeAcesso::Editar, '  preciso do contrato  ', $pedidoEm);

        self::assertSame('preciso do contrato', $pedido->getMotivo());
        self::assertTrue($pedido->aguardandoEm(new \DateTimeImmutable('2026-10-21 09:59')));
        self::assertFalse($pedido->aguardandoEm(new \DateTimeImmutable('2026-10-21 10:00')));

        try {
            $pedido->renovar(NivelDeAcesso::Visualizar, null, new \DateTimeImmutable('2026-10-20 18:00'));
            self::fail('renovou dentro das 24 h');
        } catch (\DomainException $e) {
            self::assertSame('Você já pediu acesso a esta pasta hoje. Aguarde o usuário primário.', $e->getMessage());
        }

        $pedido->renovar(NivelDeAcesso::Visualizar, null, new \DateTimeImmutable('2026-10-21 11:00'));
        self::assertSame(NivelDeAcesso::Visualizar, $pedido->getNivelPedido());
        self::assertNull($pedido->getMotivo());
    }

    #[TestDox('pedido: motivo acima de 300 caracteres é recusado')]
    public function testMotivoLongo(): void
    {
        $this->expectException(\DomainException::class);

        new PedidoDeAcessoAPasta($this->tenant, $this->pasta(), $this->pessoa('Ana'), NivelDeAcesso::Editar, str_repeat('a', 301), new \DateTimeImmutable());
    }

    #[TestDox('pedido decidido não aceita outra decisão, com o texto do desenho')]
    public function testDecisaoUnica(): void
    {
        $primario = $this->pessoa('Paula', primario: true);
        $pedido   = new PedidoDeAcessoAPasta($this->tenant, $this->pasta(), $this->pessoa('Ana'), NivelDeAcesso::Editar, null, new \DateTimeImmutable());
        $pedido->recusar($primario, new \DateTimeImmutable());

        self::assertSame(EstadoDoPedidoDeAcesso::Recusado, $pedido->getEstado());
        $this->expectExceptionMessage('Este pedido já foi recusado.');
        $pedido->liberar(NivelDeAcesso::Editar, $primario, new \DateTimeImmutable());
    }
}
