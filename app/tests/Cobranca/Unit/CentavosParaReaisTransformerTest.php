<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Form\DataTransformer\CentavosParaReaisTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Conversão dinheiro CENTAVOS(int) ⇄ reais pt-BR(string). Dinheiro nunca vira float no domínio;
 * a entrada malformada deve falhar como erro de campo (TransformationFailedException), não passar
 * como valor errado.
 */
#[CoversClass(CentavosParaReaisTransformer::class)]
final class CentavosParaReaisTransformerTest extends TestCase
{
    private CentavosParaReaisTransformer $sut;

    protected function setUp(): void
    {
        $this->sut = new CentavosParaReaisTransformer();
    }

    #[TestDox('centavos int → reais pt-BR com milhar e decimal')]
    public function testTransformFormataReais(): void
    {
        self::assertSame('1.234,56', $this->sut->transform(123456));
        self::assertSame('0,00', $this->sut->transform(0));
        self::assertSame('9,90', $this->sut->transform(990));
        self::assertSame('1.000.000,00', $this->sut->transform(100000000));
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
        $this->sut->transform(12.34);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function entradasValidas(): array
    {
        return [
            'pt-BR com milhar' => ['1.234,56', 123456],
            'pt-BR sem milhar' => ['1234,56', 123456],
            'inteiro puro' => ['1234', 123400],
            'milhar sem decimal' => ['1.234', 123400],
            'milhares encadeados' => ['1.234.567', 123456700],
            'US decimal ponto' => ['1234.56', 123456],
            'com espaços' => ['  10,00  ', 1000],
            'arredonda meio centavo' => ['12,355', 1236],
            'só centavos' => ['0,99', 99],
        ];
    }

    #[DataProvider('entradasValidas')]
    #[TestDox('reais digitados → centavos int')]
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

    #[TestDox('ida e volta preserva o valor')]
    public function testIdaEVolta(): void
    {
        foreach ([0, 1, 99, 100, 123456, 100000000] as $centavos) {
            self::assertSame($centavos, $this->sut->reverseTransform($this->sut->transform($centavos)), "falhou em {$centavos}");
        }
    }
}
