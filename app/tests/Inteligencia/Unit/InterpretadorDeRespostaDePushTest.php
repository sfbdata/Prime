<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Exception\RespostaInvalidaException;
use App\Inteligencia\Service\InterpretadorDeRespostaDePush;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(InterpretadorDeRespostaDePush::class)]
final class InterpretadorDeRespostaDePushTest extends TestCase
{
    private InterpretadorDeRespostaDePush $interpretador;

    protected function setUp(): void
    {
        $this->interpretador = new InterpretadorDeRespostaDePush();
    }

    #[TestDox('JSON limpo vira resumo, pontos e quem age')]
    public function testJsonLimpo(): void
    {
        $r = $this->interpretador->interpretar(
            '{"resumo":"Sentença publicada.","pontos":[{"tipo":"prazo","texto":"Apelação em 15 dias úteis, termo inicial a conferir."}],"quem":"Dra. Ana"}',
        );

        self::assertSame('Sentença publicada.', $r->resumo);
        self::assertSame([['tipo' => 'prazo', 'texto' => 'Apelação em 15 dias úteis, termo inicial a conferir.']], $r->pontos);
        self::assertSame('Dra. Ana', $r->quemAge);
    }

    #[TestDox('JSON com texto em volta (cerca de markdown, frase antes/depois) é recortado do 1º { ao último }')]
    public function testJsonComTextoEmVolta(): void
    {
        $r = $this->interpretador->interpretar(
            "Claro! Aqui está a análise:\n```json\n{\"resumo\":\"Despacho de mero expediente.\",\"pontos\":[],\"quem\":\"\"}\n```\nQualquer dúvida, avise.",
        );

        self::assertSame('Despacho de mero expediente.', $r->resumo);
        self::assertSame([], $r->pontos);
        self::assertNull($r->quemAge, 'quem vazio vira null, não string vazia');
    }

    #[TestDox('tipo fora da lista vira info')]
    public function testTipoForaDaListaViraInfo(): void
    {
        $r = $this->interpretador->interpretar(
            '{"resumo":"x","pontos":[{"tipo":"urgentissimo","texto":"a"},{"tipo":"OK","texto":"b"},{"texto":"c"}]}',
        );

        self::assertSame(['info', 'ok', 'info'], array_column($r->pontos, 'tipo'));
    }

    #[TestDox('mais de 5 pontos → fica com os 5 primeiros')]
    public function testMaximoDeCincoPontos(): void
    {
        $pontos = [];
        for ($i = 1; $i <= 8; ++$i) {
            $pontos[] = ['tipo' => 'info', 'texto' => 'ponto ' . $i];
        }

        $r = $this->interpretador->interpretar((string) json_encode(['resumo' => 'x', 'pontos' => $pontos]));

        self::assertCount(5, $r->pontos);
        self::assertSame('ponto 5', $r->pontos[4]['texto']);
    }

    #[TestDox('travessão e meia-risca viram vírgula (regra semTraco do Designer) no resumo, nos pontos e no quem')]
    public function testTravessaoRemovido(): void
    {
        $r = $this->interpretador->interpretar(
            '{"resumo":"Sentença publicada — prazo corre","pontos":[{"tipo":"prazo","texto":"15 dias – a conferir"}],"quem":"Equipe - cível"}',
        );

        self::assertSame('Sentença publicada, prazo corre', $r->resumo);
        self::assertSame('15 dias, a conferir', $r->pontos[0]['texto']);
        self::assertSame('Equipe, cível', $r->quemAge);
        self::assertStringNotContainsString('—', $r->resumo);
    }

    #[TestDox('ponto sem texto é descartado, não vira chip vazio')]
    public function testPontoSemTextoEhDescartado(): void
    {
        $r = $this->interpretador->interpretar('{"resumo":"x","pontos":[{"tipo":"prazo","texto":"   "},{"tipo":"ok","texto":"tudo certo"}]}');

        self::assertSame([['tipo' => 'ok', 'texto' => 'tudo certo']], $r->pontos);
    }

    #[TestDox('texto sem JSON → RespostaInvalidaException com o texto bruto preservado')]
    public function testSemJsonLancaComTextoBruto(): void
    {
        try {
            $this->interpretador->interpretar('Não consigo analisar isto.');
            self::fail('deveria ter lançado');
        } catch (RespostaInvalidaException $e) {
            self::assertSame('Não consigo analisar isto.', $e->textoBruto);
            self::assertStringContainsString('resposta inválida', $e->getMessage());
        }
    }

    #[TestDox('JSON malformado → RespostaInvalidaException')]
    public function testJsonMalformadoLanca(): void
    {
        $this->expectException(RespostaInvalidaException::class);

        $this->interpretador->interpretar('{"resumo": "sem fechar o objeto"');
    }

    #[TestDox('JSON válido mas sem resumo → RespostaInvalidaException (o modelo não disse o que aconteceu)')]
    public function testSemResumoLanca(): void
    {
        try {
            $this->interpretador->interpretar('{"pontos":[{"tipo":"info","texto":"a"}],"quem":"x"}');
            self::fail('deveria ter lançado');
        } catch (RespostaInvalidaException $e) {
            self::assertStringContainsString('sem resumo', $e->getMessage());
        }
    }

    #[TestDox('quem age é cortado no tamanho da coluna (120)')]
    public function testQuemAgeTruncado(): void
    {
        $longo = str_repeat('a', 200);
        $r = $this->interpretador->interpretar((string) json_encode(['resumo' => 'x', 'quem' => $longo]));

        self::assertSame(120, mb_strlen((string) $r->quemAge));
    }
}
