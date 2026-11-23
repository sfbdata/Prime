<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\PedidoDeEdicao;
use App\Comercial\Exception\CampanhaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O pedido de "Salvar alterações" da edição de campanha (pedido do Farlei M5.3) e a ida e volta da mensagem para o editor. */
#[CoversClass(PedidoDeEdicao::class)]
#[CoversClass(MensagemDaCampanha::class)]
final class PedidoDeEdicaoTest extends TestCase
{
    #[TestDox('Lê o modelo, a frequência e a data do datetime-local; sem data e sem frequência, única sem agenda')]
    public function testLe(): void
    {
        $pedido = PedidoDeEdicao::deArray(['modelo' => ['modo' => 'simples'], 'frequencia' => 'mensal', 'agendar' => '2030-05-17T09:30']);
        self::assertSame('mensal', $pedido->frequencia);
        self::assertSame('2030-05-17 09:30', $pedido->agendarPara?->format('Y-m-d H:i'));
        self::assertSame(['modo' => 'simples'], $pedido->modelo);

        $simples = PedidoDeEdicao::deArray(['modelo' => [], 'agendar' => '']);
        self::assertSame('unica', $simples->frequencia);
        self::assertNull($simples->agendarPara);
    }

    #[TestDox('Recusa o corpo sem modelo, a frequência fora da lista e a data fora do formato')]
    public function testRecusa(): void
    {
        foreach ([
            [['frequencia' => 'unica'], 'Pedido inválido.'],
            [['modelo' => 'texto'], 'Pedido inválido.'],
            [['modelo' => [], 'frequencia' => 'diaria'], 'Frequência inválida.'],
            [['modelo' => [], 'agendar' => '17/05/2030'], 'Data de agendamento inválida.'],
        ] as [$corpo, $erro]) {
            try {
                PedidoDeEdicao::deArray($corpo);
                self::fail('devia recusar: ' . $erro);
            } catch (CampanhaRecusada $e) {
                self::assertSame($erro, $e->getMessage());
            }
        }
    }

    #[TestDox('A mensagem volta ao editor no mesmo formato que o editor manda (ida e volta sem perda)')]
    public function testIdaEVolta(): void
    {
        $modelo = (array) (new ModelosDeCampanha())->fixo('convite');
        $mensagem = MensagemDaCampanha::deArray($modelo);
        $volta = $mensagem->paraArray();

        self::assertEquals($mensagem, MensagemDaCampanha::deArray($volta));
        foreach (['chave', 'modo', 'nome', 'icone', 'assunto', 'selo', 'titulo', 'corpo', 'itens', 'cta', 'link', 'blocos', 'html', 'imagem'] as $campo) {
            self::assertArrayHasKey($campo, $volta);
        }
    }
}
