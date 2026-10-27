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
            'resposta' => "Primeiro \u{2014} segundo \u{2013} terceiro\nlinha - dois",
            'pontos' => [['tipo' => 'info', 'texto' => "A \u{2014} B"]],
        ], JSON_UNESCAPED_UNICODE));

        self::assertSame("Primeiro, segundo, terceiro\nlinha, dois", $resultado->resumo);
        self::assertSame('A, B', $resultado->pontos[0]['texto']);
        self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $resultado->resumo);
    }

    #[TestDox('\t vira espaço (não cola palavras), ZWJ/ZWNJ ficam, controle sai, meia-risca entre dígitos vira " a "')]
    public function testLimpezaDeCaracteres(): void
    {
        $resultado = $this->interpretador->interpretar((string) json_encode([
            'resposta' => "col1\tcol2 prazo de 10\u{2013}20 dias\x07 fim \u{200D}\u{200C}ok",
            'pontos' => [['tipo' => 'info', 'texto' => "a\tb 5\u{2013}7"]],
        ], JSON_UNESCAPED_UNICODE));

        self::assertSame("col1 col2 prazo de 10 a 20 dias fim \u{200D}\u{200C}ok", $resultado->resumo);
        self::assertSame('a b 5 a 7', $resultado->pontos[0]['texto']);
    }

    #[TestDox('bidi, zero-width, marcas de direção e BOM não voltam: nenhum deles sobrevive na resposta nem nos pontos')]
    public function testInvisiveisSaem(): void
    {
        $invisiveis = ["\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}", "\u{200B}", "\u{200E}", "\u{200F}", "\u{FEFF}"];
        foreach ($invisiveis as $invisivel) {
            $resultado = $this->interpretador->interpretar((string) json_encode([
                'resposta' => 'ab' . $invisivel . 'cd',
                'pontos' => [['tipo' => 'info', 'texto' => 'ef' . $invisivel . 'gh']],
            ]));

            self::assertSame('abcd', $resultado->resumo, sprintf('U+%04X', mb_ord($invisivel)));
            self::assertSame('efgh', $resultado->pontos[0]['texto'], sprintf('U+%04X', mb_ord($invisivel)));
        }
    }
}
