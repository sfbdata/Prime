<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Form\DataTransformer\TaxaBpParaTextoTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Conversão de taxa BASIS POINTS(int) ⇄ percentual pt-BR(string). Taxa é insumo de dinheiro: no
 * domínio ela é inteira (100 bp = 1%) e a entrada malformada deve falhar como erro de campo
 * (TransformationFailedException), não passar como valor errado — uma taxa lida errado vira
 * encargo errado em toda a carteira.
 */
#[CoversClass(TaxaBpParaTextoTransformer::class)]
final class TaxaBpParaTextoTransformerTest extends TestCase
{
    private TaxaBpParaTextoTransformer $sut;

    protected function setUp(): void
    {
        $this->sut = new TaxaBpParaTextoTransformer();
    }

    #[TestDox('bp int → percentual pt-BR com vírgula decimal')]
    public function testTransformFormataPercentual(): void
    {
        self::assertSame('1,00', $this->sut->transform(100));
        self::assertSame('2,50', $this->sut->transform(250));
        self::assertSame('20,00', $this->sut->transform(2000));
        self::assertSame('0,00', $this->sut->transform(0));
        self::assertSame('0,01', $this->sut->transform(1));
        self::assertSame('15,00', $this->sut->transform(1500));
        // Teto de sanidade do DTO (1000%) formatado com separador de milhar.
        self::assertSame('1.000,00', $this->sut->transform(100000));
    }

    #[TestDox('null e string vazia viram campo em branco')]
    public function testTransformVazio(): void
    {
        self::assertSame('', $this->sut->transform(null));
        self::assertSame('', $this->sut->transform(''));
    }

    #[TestDox('valor não-int no modelo → TransformationFailedException')]
    public function testTransformRejeitaNaoInt(): void
    {
        $this->expectException(TransformationFailedException::class);
        $this->sut->transform(1.5);
    }

    #[TestDox('string no modelo → TransformationFailedException')]
    public function testTransformRejeitaString(): void
    {
        $this->expectException(TransformationFailedException::class);
        $this->sut->transform('100');
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function entradasValidas(): array
    {
        return [
            'um por cento com vírgula' => ['1,00', 100],
            'meio ponto com vírgula' => ['2,5', 250],
            'inteiro puro sem decimal' => ['20', 2000],
            'zero' => ['0', 0],
            'zero com decimais' => ['0,00', 0],
            'US decimal com ponto' => ['1.50', 150],
            'centésimo de por cento' => ['0,01', 1],
            'com espaços em volta' => ['  2,00  ', 200],
            'milhar pt-BR' => ['1.000,00', 100000],
            'milhar sem decimal' => ['1.000', 100000],
            'arredonda a terceira casa' => ['1,005', 101],
            'quinze por cento' => ['15', 1500],
        ];
    }

    /**
     * Terceira casa decimal: HALF-UP exato, por aritmética inteira. Antes a conversão passava por
     * `round((float) $x * 100)` e o resultado dependia do pré-arredondamento interno do `round()` do
     * PHP — para "1,005" o produto em float vale 100,49999999999998578…, ou seja, o valor certo (101)
     * só saía por causa de uma heurística não-contratual que o PHP 8.4 reescreveu. Taxa que muda de
     * valor conforme a versão do PHP é perda silenciosa de dinheiro; estes casos prendem a regra.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function tresCasasDecimais(): array
    {
        return [
            // Exatamente meio bp → sobe (half-up), nunca desce.
            'meio bp sobe: 1,005' => ['1,005', 101],
            'meio bp sobe: 0,005' => ['0,005', 1],
            'meio bp sobe: 2,555' => ['2,555', 256],
            // Abaixo de meio bp → desce.
            'abaixo de meio desce: 1,004' => ['1,004', 100],
            'abaixo de meio desce: 0,004' => ['0,004', 0],
            // Quarta casa não muda a decisão tomada na terceira.
            'quarta casa não reabre a decisão: 1,0049' => ['1,0049', 100],
            'quarta casa não reabre a decisão: 1,0051' => ['1,0051', 101],
            // Arredondamento que sobe de "casa" inteira.
            'sobe para o inteiro seguinte: 10,999' => ['10,999', 1100],
            // Três zeros decimais não inventam bp nenhum.
            'três zeros continuam exatos: 1,000' => ['1,000', 100],
        ];
    }

    #[DataProvider('tresCasasDecimais')]
    #[TestDox('terceira casa decimal arredonda half-up sem passar por float')]
    public function testReverseTransformTresCasas(string $entrada, int $esperado): void
    {
        self::assertSame($esperado, $this->sut->reverseTransform($entrada));
    }

    #[TestDox('notação científica não é percentual válido')]
    public function testReverseTransformRejeitaNotacaoCientifica(): void
    {
        $this->expectException(TransformationFailedException::class);
        $this->sut->reverseTransform('1e3');
    }

    #[TestDox('número absurdamente longo falha em vez de estourar o int em silêncio')]
    public function testReverseTransformRejeitaOverflow(): void
    {
        $this->expectException(TransformationFailedException::class);
        $this->sut->reverseTransform('99999999999999999999');
    }

    #[DataProvider('entradasValidas')]
    #[TestDox('percentual digitado → bp int')]
    public function testReverseTransform(string $entrada, int $esperado): void
    {
        self::assertSame($esperado, $this->sut->reverseTransform($entrada));
    }

    #[TestDox('vazio → null')]
    public function testReverseTransformVazio(): void
    {
        self::assertNull($this->sut->reverseTransform(''));
        self::assertNull($this->sut->reverseTransform('   '));
        self::assertNull($this->sut->reverseTransform(null));
    }

    #[TestDox('texto não numérico → TransformationFailedException')]
    public function testReverseTransformRejeitaTexto(): void
    {
        $this->expectException(TransformationFailedException::class);
        $this->sut->reverseTransform('abc');
    }

    #[TestDox('percentual com sufixo → TransformationFailedException')]
    public function testReverseTransformRejeitaSufixo(): void
    {
        $this->expectException(TransformationFailedException::class);
        $this->sut->reverseTransform('1,00%');
    }

    #[TestDox('valor não-string na view → TransformationFailedException')]
    public function testReverseTransformRejeitaNaoString(): void
    {
        $this->expectException(TransformationFailedException::class);
        $this->sut->reverseTransform(100);
    }

    #[TestDox('ida e volta preserva a taxa em bp')]
    public function testIdaEVolta(): void
    {
        foreach ([0, 1, 100, 150, 200, 250, 1500, 2000, 100000] as $bp) {
            self::assertSame($bp, $this->sut->reverseTransform($this->sut->transform($bp)), "falhou em {$bp}");
        }
    }
}
