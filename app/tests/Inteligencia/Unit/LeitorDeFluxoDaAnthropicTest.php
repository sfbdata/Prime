<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Service\LeitorDeFluxoDaAnthropic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorDeFluxoDaAnthropic::class)]
final class LeitorDeFluxoDaAnthropicTest extends TestCase
{
    private const FLUXO = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"model\":\"claude-haiku-5-5\",\"usage\":{\"input_tokens\":25}}}\n\n"
        . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Olá\"}}\n\n"
        . "event: ping\ndata: {\"type\":\"ping\"}\n\n"
        . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\", mundo\"}}\n\n"
        . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":7}}\n\n"
        . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

    #[TestDox('Fluxo completo: trechos na ordem, modelo, tokens reais e fim normal')]
    public function testFluxoCompleto(): void
    {
        $leitor = new LeitorDeFluxoDaAnthropic();

        $trechos = $leitor->alimentar(self::FLUXO);

        self::assertSame(['Olá', ', mundo'], $trechos);
        self::assertTrue($leitor->terminou());
        self::assertNull($leitor->erro());
        self::assertSame('claude-haiku-5-5', $leitor->modelo());
        self::assertSame(25, $leitor->tokensEntrada());
        self::assertSame(7, $leitor->tokensSaida());
        self::assertFalse($leitor->cortadaNoLimite());
    }

    #[TestDox('Pedaços cortados no meio do evento e com CRLF dão o mesmo resultado')]
    public function testPedacosQuebrados(): void
    {
        $leitor = new LeitorDeFluxoDaAnthropic();
        $trechos = [];
        foreach (str_split(str_replace("\n", "\r\n", self::FLUXO), 17) as $pedaco) {
            $trechos = [...$trechos, ...$leitor->alimentar($pedaco)];
        }

        self::assertSame(['Olá', ', mundo'], $trechos);
        self::assertTrue($leitor->terminou());
    }

    #[TestDox('Parada por max_tokens marca a resposta como cortada')]
    public function testCortadaNoLimite(): void
    {
        $leitor = new LeitorDeFluxoDaAnthropic();
        $leitor->alimentar("data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"max_tokens\"},\"usage\":{\"output_tokens\":2000}}\n\n");

        self::assertTrue($leitor->cortadaNoLimite());
        self::assertSame(2000, $leitor->tokensSaida());
    }

    #[TestDox('Erro dentro do fluxo vira só o tipo (letras e sublinhado), sem a mensagem do provedor')]
    public function testErroNoFluxo(): void
    {
        $leitor = new LeitorDeFluxoDaAnthropic();
        $leitor->alimentar("event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"texto do cliente\"}}\n\n");

        self::assertSame('overloaded_error', $leitor->erro());
        self::assertFalse($leitor->terminou());
    }

    #[TestDox('Lixo no data e eventos desconhecidos são ignorados')]
    public function testLixo(): void
    {
        $leitor = new LeitorDeFluxoDaAnthropic();

        self::assertSame([], $leitor->alimentar("data: isto-nao-e-json\n\ndata: {\"type\":\"coisa_nova\"}\n\n"));
        self::assertFalse($leitor->terminou());
    }
}
