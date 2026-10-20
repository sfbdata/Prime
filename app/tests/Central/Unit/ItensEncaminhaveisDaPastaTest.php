<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Service\ItensEncaminhaveisDaPasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Tests\Central\Support\MontaEncaminhamentoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O cartão do item encaminhado: texto puro, sem menção ativa, cortado; item de fora da pasta é 404. */
#[CoversClass(ItensEncaminhaveisDaPasta::class)]
final class ItensEncaminhaveisDaPastaTest extends TestCase
{
    use MontaEncaminhamentoTrait;

    #[TestDox('Limpeza: token de menção vira @Nome, HTML sai, entidades decodificam e os brancos colapsam')]
    public function testLimpezaDoTexto(): void
    {
        $html = '<p>Oi @[Ana Lima](user:7),</p><p>veja&nbsp;o   prazo &amp; a <strong>guia</strong>.</p><br>Fim';

        self::assertSame('Oi @Ana Lima, veja o prazo & a guia. Fim', ItensEncaminhaveisDaPasta::textoLimpo($html));
    }

    #[TestDox('Limpeza: a menção escrita com &#64; (como o editor grava) também vira @Nome')]
    public function testLimpezaDaMencaoComEntidade(): void
    {
        self::assertSame('para @Bruno', ItensEncaminhaveisDaPasta::textoLimpo('para &#64;[Bruno](user:9)'));
    }

    #[TestDox('Limpeza: passa de 160 caracteres, corta em 160 e põe reticências; até 160 fica inteiro')]
    public function testCorteEm160(): void
    {
        $longo = str_repeat('a', 200);
        $exato = str_repeat('b', 160);

        self::assertSame(str_repeat('a', 160) . '…', ItensEncaminhaveisDaPasta::textoLimpo($longo));
        self::assertSame($exato, ItensEncaminhaveisDaPasta::textoLimpo($exato));
        self::assertSame('', ItensEncaminhaveisDaPasta::textoLimpo(null));
    }

    #[TestDox('Neutralização: "@[" vira "@ [", e nenhum token de menção sobrevive')]
    public function testNeutralizacao(): void
    {
        self::assertSame('@ [Ana](user:1) e @ [x', ItensEncaminhaveisDaPasta::neutralizarMencoes('@[Ana](user:1) e @[x'));
    }

    #[TestDox('Registro de Dados: título com autor e data, resumo limpo, e o "@[" que sobrou do HTML desarmado')]
    public function testRegistroDeDados(): void
    {
        $tenant = new Tenant();
        $pasta = $this->pastaDe($tenant);
        $registro = (new PastaMensagem())->setTenant($tenant)->setPasta($pasta)
            ->setAutor($this->pessoa(9, 'Carla Souza'))
            ->setConteudo('<p>Cobrar &#91;guia&#93; e &#64;&#91;Zé](user:3) amanhã</p>');
        $mensagens = $this->createMock(PastaMensagemRepository::class);
        $mensagens->expects(self::once())->method('findByIdAndPastaAndTenant')->with(31, $pasta, $tenant)->willReturn($registro);

        $item = $this->itens($mensagens)->resumir($tenant, $pasta, TipoDeItemEncaminhado::RegistroDados, 31);

        self::assertSame('Registro de Carla Souza · ' . $registro->getCriadaEm()->format('d/m/Y H:i'), $item->titulo);
        self::assertSame('Cobrar [guia] e @ [Zé](user:3) amanhã', $item->resumo);
    }

    #[TestDox('Registro de outra pasta ou de outro escritório (a consulta não acha) é 404')]
    public function testRegistroDeForaEh404(): void
    {
        $tenant = new Tenant();
        $mensagens = $this->createStub(PastaMensagemRepository::class);
        $mensagens->method('findByIdAndPastaAndTenant')->willReturn(null);

        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $this->itens($mensagens)->resumir($tenant, $this->pastaDe($tenant), TipoDeItemEncaminhado::RegistroDados, 31);
    }

    #[TestDox('Item que não é a pasta exige id: sem id é 404')]
    public function testItemSemIdEh404(): void
    {
        $tenant = new Tenant();

        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $this->itens()->resumir($tenant, $this->pastaDe($tenant), TipoDeItemEncaminhado::Meta, null);
    }

    #[TestDox('A pasta: título "Pasta NUP", sem resumo, o id do item é ignorado')]
    public function testPasta(): void
    {
        $tenant = new Tenant();
        $item = $this->itens()->resumir($tenant, $this->pastaDe($tenant, 50, 'BJ-2026-7'), TipoDeItemEncaminhado::Pasta, 999);

        self::assertSame('Pasta BJ-2026-7', $item->titulo);
        self::assertSame('', $item->resumo);
    }
}
