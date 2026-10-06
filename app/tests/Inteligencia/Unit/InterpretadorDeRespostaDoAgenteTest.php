<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Exception\RespostaInvalidaException;
use App\Inteligencia\Service\InterpretadorDeRespostaDoAgente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(InterpretadorDeRespostaDoAgente::class)]
final class InterpretadorDeRespostaDoAgenteTest extends TestCase
{
    private InterpretadorDeRespostaDoAgente $interpretador;

    protected function setUp(): void
    {
        $this->interpretador = new InterpretadorDeRespostaDoAgente();
    }

    #[TestDox('JSON limpo vira resumo, pontos, quem age e o texto integral com as quebras preservadas')]
    public function testJsonLimpo(): void
    {
        $r = $this->interpretador->interpretar((string) json_encode([
            'resumo' => 'Réplica em curso — prazo corre.',
            'pontos' => [['tipo' => 'prazo', 'texto' => 'Réplica em 15 dias.']],
            'quem' => 'Dra. Ana',
            'texto' => "CONCLUSÃO\n• Réplica em curso — FATO CONFIRMADO (DJEN, 02/10/2026).\n\nPRÓXIMA PROVIDÊNCIA\n• Protocolar réplica.\n\nNecessita de conferência do advogado.",
        ], JSON_UNESCAPED_UNICODE));

        self::assertSame('Réplica em curso, prazo corre.', $r->resumo, 'travessão vira vírgula');
        self::assertSame([['tipo' => 'prazo', 'texto' => 'Réplica em 15 dias.']], $r->pontos);
        self::assertSame('Dra. Ana', $r->quemAge);
        self::assertSame(
            "CONCLUSÃO\n• Réplica em curso, FATO CONFIRMADO (DJEN, 02/10/2026).\n\nPRÓXIMA PROVIDÊNCIA\n• Protocolar réplica.\n\nNecessita de conferência do advogado.",
            $r->textoDaAnalise,
        );
    }

    #[TestDox('JSON com texto em volta (cerca de markdown) é recortado do 1º { ao último }')]
    public function testJsonComTextoEmVolta(): void
    {
        $r = $this->interpretador->interpretar("Segue:\n```json\n{\"resumo\":\"Despacho de mero expediente.\",\"pontos\":[],\"quem\":\"\"}\n```");

        self::assertSame('Despacho de mero expediente.', $r->resumo);
        self::assertSame([], $r->pontos);
        self::assertNull($r->quemAge);
        self::assertNull($r->textoDaAnalise, 'sem "texto" não se escreve análise pelo modelo');
    }

    #[TestDox('tipo fora da lista vira info; mais de 8 pontos → fica com os 8 primeiros')]
    public function testPontos(): void
    {
        $pontos = [];
        for ($i = 1; $i <= 10; ++$i) {
            $pontos[] = ['tipo' => $i === 1 ? 'urgentissimo' : 'ok', 'texto' => 'p' . $i];
        }

        $r = $this->interpretador->interpretar((string) json_encode(['resumo' => 'x', 'pontos' => $pontos]));

        self::assertCount(8, $r->pontos);
        self::assertSame('info', $r->pontos[0]['tipo']);
        self::assertSame('ok', $r->pontos[1]['tipo']);
        self::assertSame('p8', $r->pontos[7]['texto']);
    }

    #[TestDox('texto integral: \\r\\n vira \\n, controle sai, três quebras viram duas, travessão vira vírgula, teto com reticência')]
    public function testLimpezaDoTexto(): void
    {
        $r = $this->interpretador->interpretar((string) json_encode([
            'resumo' => 'x',
            'texto' => "A\r\nB\x07 — C\n\n\n\nD",
        ]));
        self::assertSame("A\nB, C\n\nD", $r->textoDaAnalise);

        $longo = str_repeat('a', InterpretadorDeRespostaDoAgente::TAMANHO_MAXIMO_DO_TEXTO + 50);
        $r = $this->interpretador->interpretar((string) json_encode(['resumo' => 'x', 'texto' => $longo]));
        self::assertSame(InterpretadorDeRespostaDoAgente::TAMANHO_MAXIMO_DO_TEXTO + 1, mb_strlen((string) $r->textoDaAnalise));
        self::assertStringEndsWith('…', (string) $r->textoDaAnalise);
    }

    #[TestDox('quem age é cortado no tamanho da coluna')]
    public function testQuemCortado(): void
    {
        $r = $this->interpretador->interpretar((string) json_encode(['resumo' => 'x', 'quem' => str_repeat('q', 200)]));

        self::assertSame(120, mb_strlen((string) $r->quemAge));
    }

    #[TestDox('sem objeto JSON, JSON malformado ou sem resumo → RespostaInvalidaException com o texto bruto preservado')]
    public function testInvalida(): void
    {
        foreach (['Desculpe, não posso.', '{"resumo": "x", }', '{"pontos":[]}', '{"resumo":"   "}'] as $texto) {
            try {
                $this->interpretador->interpretar($texto);
                self::fail('deveria ter lançado para: ' . $texto);
            } catch (RespostaInvalidaException $e) {
                self::assertSame($texto, $e->textoBruto);
                self::assertStringStartsWith('resposta inválida', $e->getMessage());
            }
        }
    }
}
