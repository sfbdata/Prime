<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Exception\RespostaInvalidaException;
use App\Inteligencia\Service\InterpretadorDeRespostaDoChat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(InterpretadorDeRespostaDoChat::class)]
final class InterpretadorDeRespostaDoChatTest extends TestCase
{
    private InterpretadorDeRespostaDoChat $interpretador;

    protected function setUp(): void
    {
        $this->interpretador = new InterpretadorDeRespostaDoChat();
    }

    #[TestDox('JSON com texto em volta: recorta do primeiro { ao último }; a resposta preserva as quebras de linha')]
    public function testJsonComTextoEmVolta(): void
    {
        $resultado = $this->interpretador->interpretar("Claro! ```json\n" . '{"resposta":"Linha 1\nLinha 2","pontos":[{"tipo":"prazo","texto":"Conferir a intimação"}]}' . "\n``` fim");

        self::assertSame("Linha 1\nLinha 2", $resultado->resumo);
        self::assertSame([['tipo' => 'prazo', 'texto' => 'Conferir a intimação']], $resultado->pontos);
        self::assertNull($resultado->quemAge);
        self::assertNull($resultado->textoDaAnalise);
    }

    #[TestDox('sem "resposta" (ausente, vazia, não string) é inválida e leva o texto bruto')]
    public function testSemResposta(): void
    {
        foreach (['{"resumo":"x"}', '{"resposta":"   "}', '{"resposta":["a"]}', '{"resposta":null}'] as $texto) {
            try {
                $this->interpretador->interpretar($texto);
                self::fail('devia recusar: ' . $texto);
            } catch (RespostaInvalidaException $e) {
                self::assertSame($texto, $e->textoBruto);
            }
        }
    }

    #[TestDox('sem JSON ou JSON malformado é inválida')]
    public function testSemJson(): void
    {
        foreach (['Olá, tudo bem?', '{resposta: sem aspas}', '} antes {'] as $texto) {
            try {
                $this->interpretador->interpretar($texto);
                self::fail('devia recusar: ' . $texto);
            } catch (RespostaInvalidaException $e) {
                self::assertSame($texto, $e->textoBruto);
            }
        }
    }

    #[TestDox('ações, métricas e trace são ignorados: nada vira ação')]
    public function testAcoesIgnoradas(): void
    {
        $resultado = $this->interpretador->interpretar((string) json_encode([
            'resposta' => 'Feito o que dá.',
            'acoes' => [['tipo' => 'registrar_compromisso', 'meta' => 'x']],
            'metricas' => [['rotulo' => 'a', 'valor' => '1']],
            'trace' => ['intencao' => 'x'],
        ]));

        self::assertSame('Feito o que dá.', $resultado->resumo);
        self::assertSame([], $resultado->pontos);
    }

    #[TestDox('até 6 pontos; ponto sem texto cai; tipo desconhecido vira info')]
    public function testPontos(): void
    {
        $pontos = [['tipo' => 'inventado', 'texto' => 'P0'], ['tipo' => 'ok'], 'lixo'];
        for ($i = 1; $i <= 8; ++$i) {
            $pontos[] = ['tipo' => 'atencao', 'texto' => 'P' . $i];
        }

        $resultado = $this->interpretador->interpretar((string) json_encode(['resposta' => 'R', 'pontos' => $pontos]));

        self::assertCount(6, $resultado->pontos);
        self::assertSame(['tipo' => 'info', 'texto' => 'P0'], $resultado->pontos[0]);
        self::assertSame('P5', $resultado->pontos[5]['texto']);
    }

    #[TestDox('travessão e meia-risca saem da resposta e dos pontos')]
    public function testTravessao(): void
    {
        $resultado = $this->interpretador->interpretar((string) json_encode([
            'resposta' => "Primeiro — segundo – terceiro\nlinha - dois",
            'pontos' => [['tipo' => 'info', 'texto' => 'A — B']],
        ], JSON_UNESCAPED_UNICODE));

        self::assertSame("Primeiro, segundo, terceiro\nlinha, dois", $resultado->resumo);
        self::assertSame('A, B', $resultado->pontos[0]['texto']);
        self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $resultado->resumo);
    }
}
