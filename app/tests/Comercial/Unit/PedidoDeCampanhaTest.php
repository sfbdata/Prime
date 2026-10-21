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

/** O pedido do "Enviar campanha" (spec §8.1 e §8.4): chave de idempotência, frequência, público e a data do datetime-local. */
#[CoversClass(PedidoDeCampanha::class)]
#[CoversClass(PedidoDePublico::class)]
final class PedidoDeCampanhaTest extends TestCase
{
    #[TestDox('O pedido completo: a chave, o modelo, o público lido e a data local')]
    public function testPedidoValido(): void
    {
        $pedido = PedidoDeCampanha::deArray($this->dados(['agendar' => '2031-03-05T14:30', 'frequencia' => 'mensal']));

        self::assertSame('0f8c3a6e-1d2b-4c5d-9e8f-123456789abc', $pedido->chave);
        self::assertSame('mensal', $pedido->frequencia);
        self::assertSame('2031-03-05 14:30:00', $pedido->agendarPara?->format('Y-m-d H:i:s'));
        self::assertSame('indiv', $pedido->publico->publico);
        self::assertSame('ana@exemplo.com', $pedido->publico->informados[0]->email);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function recusas(): iterable
    {
        yield 'chave curta' => [['chave' => 'abc'], 'Pedido inválido.'];
        yield 'frequência fora do dc' => [['frequencia' => 'diaria'], 'Frequência inválida.'];
        yield 'público desconhecido' => [['publico' => 'vip'], 'Público inválido.'];
        yield 'data fora do formato' => [['agendar' => '05/03/2031 14:30'], 'Data de agendamento inválida.'];
        yield 'data impossível' => [['agendar' => '2031-02-30T10:00'], 'Data de agendamento inválida.'];
        yield 'sem modelo' => [['modelo' => null], 'Pedido inválido.'];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('recusas')]
    #[TestDox('Recusa: $mensagem')]
    public function testRecusas(array $troca, string $mensagem): void
    {
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage($mensagem);

        PedidoDeCampanha::deArray($this->dados($troca));
    }

    #[TestDox('Sem data, a campanha não tem agendamento; com segundos, o datetime-local também vale')]
    public function testDatas(): void
    {
        self::assertNull(PedidoDeCampanha::deArray($this->dados(['agendar' => '']))->agendarPara);
        self::assertSame('2031-03-05 14:30:15', PedidoDeCampanha::deArray($this->dados(['agendar' => '2031-03-05T14:30:15']))->agendarPara?->format('Y-m-d H:i:s'));
    }

    /**
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    private function dados(array $troca = []): array
    {
        return array_merge([
            'chave' => '0f8c3a6e-1d2b-4c5d-9e8f-123456789abc',
            'modelo' => ['chave' => 'convite', 'modo' => 'simples', 'assunto' => 'Oi', 'selo' => 'S', 'titulo' => 'T', 'corpo' => 'Olá'],
            'publico' => 'indiv', 'individual' => 'ana@exemplo.com', 'frequencia' => 'unica', 'agendar' => '',
        ], $troca);
    }
}
