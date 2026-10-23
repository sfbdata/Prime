<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\DTO\PedidoDeCampanha;
use App\Comercial\DTO\PedidoDePublico;
use App\Comercial\Exception\CampanhaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O modo venda no servidor (F3h, spec comercial-e-emails §10; dc 05 VS 21.10.26 L627-645): na contagem e no envio,
 * `modo: 'venda'` aceita só a Base de contatos, o CSV e o Individual, o filtro só com a busca e o Estado e, no envio,
 * só os quatro modelos da venda ou um modelo próprio. Sem `modo`, tudo continua como na seção Comercial.
 */
#[CoversClass(PedidoDePublico::class)]
#[CoversClass(PedidoDeCampanha::class)]
final class ModoVendaDoPedidoTest extends TestCase
{
    #[TestDox('Sem modo, o pedido é da seção Comercial e aceita os seis públicos e o filtro inteiro')]
    public function testSemModoEhComercial(): void
    {
        foreach (['leads', 'prim', 'sec', 'todos'] as $publico) {
            $pedido = PedidoDePublico::deArray(['publico' => $publico, 'filtro' => ['gen' => 'F', 'cargo' => 'adv']]);
            self::assertSame('com', $pedido->modo);
            self::assertFalse($pedido->ehVenda());
        }
        self::assertSame('obrigado', PedidoDeCampanha::deArray($this->campanha(['modo' => 'com'], 'obrigado'))->mensagem->chave);
    }

    #[TestDox('Venda: Base de contatos, CSV e Individual valem, com a busca e o Estado no filtro')]
    public function testVendaAceita(): void
    {
        $leads = PedidoDePublico::deArray(['modo' => 'venda', 'publico' => 'leads', 'filtro' => ['q' => 'ana', 'uf' => 'AL', 'gen' => '']]);
        self::assertTrue($leads->ehVenda());
        self::assertSame(['q' => 'ana', 'uf' => 'AL'], $leads->filtro->paraArray());
        self::assertSame('csv', PedidoDePublico::deArray(['modo' => 'venda', 'publico' => 'csv', 'csv' => "ana@exemplo.com;Ana\n"])->publico);
        self::assertSame('indiv', PedidoDePublico::deArray(['modo' => 'venda', 'publico' => 'indiv', 'individual' => 'ana@exemplo.com'])->publico);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function recusasDoPublico(): iterable
    {
        foreach (['prim', 'sec', 'todos'] as $publico) {
            yield 'público ' . $publico => [['modo' => 'venda', 'publico' => $publico], 'Público inválido para a seção Indicações.'];
        }
        foreach (['gen' => 'F', 'id' => 'a', 'papel' => 'prim', 'cargo' => 'adv', 'oab' => 'conf', 'plano' => 'free', 'aniv' => 'mes'] as $campo => $valor) {
            yield 'filtro ' . $campo => [['modo' => 'venda', 'publico' => 'leads', 'filtro' => [$campo => $valor]], 'Na seção Indicações, o filtro aceita só a busca e o Estado.'];
        }
        yield 'modo desconhecido' => [['modo' => 'vip', 'publico' => 'leads'], 'Pedido inválido.'];
        yield 'modo fora do tipo' => [['modo' => ['venda'], 'publico' => 'leads'], 'Pedido inválido.'];
    }

    /** @param array<string, mixed> $dados */
    #[DataProvider('recusasDoPublico')]
    #[TestDox('Recusa na contagem: $_dataName')]
    public function testRecusasDoPublico(array $dados, string $mensagem): void
    {
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage($mensagem);

        PedidoDePublico::deArray($dados);
    }

    #[TestDox('Venda no envio: os quatro modelos do dc e os próprios (cu<id>) valem')]
    public function testModelosDaVenda(): void
    {
        foreach (['convite', 'vagas', 'news', 'novidades', 'cu12'] as $chave) {
            self::assertSame($chave, PedidoDeCampanha::deArray($this->campanha(['modo' => 'venda'], $chave))->mensagem->chave);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function modelosForaDaVenda(): iterable
    {
        foreach (['obrigado', 'validar', 'boasvindas', 'espaco90', 'pedido', 'campanha', 'cu0', 'cux'] as $chave) {
            yield $chave => [$chave];
        }
    }

    #[DataProvider('modelosForaDaVenda')]
    #[TestDox('Recusa no envio da venda: o modelo $chave')]
    public function testModeloForaDaVenda(string $chave): void
    {
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage('Modelo inválido para a seção Indicações.');

        PedidoDeCampanha::deArray($this->campanha(['modo' => 'venda'], $chave));
    }

    #[TestDox('Recusa no envio da venda: o público e o filtro passam pela mesma regra da contagem')]
    public function testEnvioDaVendaConfereOPublico(): void
    {
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage('Público inválido para a seção Indicações.');

        PedidoDeCampanha::deArray($this->campanha(['modo' => 'venda', 'publico' => 'todos'], 'convite'));
    }

    /**
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    private function campanha(array $troca, string $modelo): array
    {
        return array_merge([
            'chave' => '0f8c3a6e-1d2b-4c5d-9e8f-123456789abc',
            'modelo' => ['chave' => $modelo, 'modo' => 'simples', 'assunto' => 'Oi', 'selo' => 'S', 'titulo' => 'T', 'corpo' => 'Olá'],
            'publico' => 'indiv', 'individual' => 'ana@exemplo.com', 'frequencia' => 'unica', 'agendar' => '',
        ], $troca);
    }
}
