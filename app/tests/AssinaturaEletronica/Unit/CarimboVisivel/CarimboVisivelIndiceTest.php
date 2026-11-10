<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\CarimboVisivel;

use App\AssinaturaEletronica\Service\CarimboVisivelIndice;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CarimboVisivelIndice::class)]
final class CarimboVisivelIndiceTest extends TestCase
{
    private const OBJETOS = [
        1 => '<</Type/Catalog/Pages 2 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/Contents 4 0 R>>',
        4 => "<</Length 0>>\nstream\n\nendstream",
    ];

    /** @return iterable<string, array{string, int, int}> */
    public static function primeirosLivres(): iterable
    {
        $classico = MontadorDePdfDoCarimbo::classico(self::OBJETOS);

        yield '/Size certo' => [$classico, 5, 5];
        yield '/Size menor que os objetos (1 a 4 com /Size 4)' => [str_replace('/Size 5', '/Size 4', $classico), 4, 5];
        yield '/Size maior que os objetos' => [str_replace('/Size 5', '/Size 12', $classico), 12, 12];
        yield 'faixa do /Index de um fluxo /XRef além do /Size' => [
            MontadorDePdfDoCarimbo::classico(self::OBJETOS + [5 => '<</Type/XRef/Size 3/W[1 4 2]/Index[0 3 40 2]/Length 0>>' . "\nstream\n\nendstream"]),
            6,
            42,
        ];
        yield 'cabeçalho com mais de 9 algarismos não conta' => [
            MontadorDePdfDoCarimbo::classico(self::OBJETOS + [5 => "(texto\n1234567890 0 obj)"]),
            6,
            6,
        ];
    }

    #[DataProvider('primeirosLivres')]
    #[TestDox('primeiro número livre: $_dataName')]
    public function testPrimeiroLivre(string $pdf, int $tamanho, int $primeiroLivre): void
    {
        $indice = CarimboVisivelIndice::ler($pdf);

        self::assertNotNull($indice);
        self::assertSame($tamanho, $indice->tamanho);
        self::assertSame($primeiroLivre, $indice->primeiroLivre);
    }

    #[TestDox('cabeçalhos acima do teto do motor: estrutura não reconhecida')]
    public function testCabecalhosDemais(): void
    {
        $pdf = MontadorDePdfDoCarimbo::classico(self::OBJETOS + [5 => '(' . str_repeat("\n1 0 obj", LimitesDaConferencia::CABECALHOS_MAXIMOS_DO_ARQUIVO) . ')']);

        self::assertNull(CarimboVisivelIndice::ler($pdf));
    }
}
