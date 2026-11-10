<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\CarimboVisivel;

use App\AssinaturaEletronica\Service\CarimboVisivelIndice;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
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
        yield 'objeto só no /ObjStm, /XRef sem /Index e atualização com /Size menor' => [self::recursosSoNoFluxo(), 6, 8];
        yield 'o maior que ainda cabe no limite de números' => [str_replace('/Size 5', '/Size 8388603', $classico), 8_388_603, 8_388_603];
    }

    /**
     * O caso da revisão: /Size 8 no fluxo /XRef sem /Index, o 7 (os /Resources da página) só no /ObjStm 4, e uma
     * atualização incremental com /Size 6.
     */
    public static function recursosSoNoFluxo(): string
    {
        $pdf = MontadorDePdfDoCarimbo::comFluxoDeXref([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources 7 0 R>>',
        ], [7 => '<</ProcSet[/PDF]>>'], 4);

        return OficinaDePdfAssinado::acrescimoSemAssinatura($pdf, [1 => '<</Type/Catalog/Pages 2 0 R>>'], 6);
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

    /** @return iterable<string, array{string}> */
    public static function acimaDoLimite(): iterable
    {
        $classico = MontadorDePdfDoCarimbo::classico(self::OBJETOS);

        yield 'cabeçalho falso de 999999999 dentro de um texto' => [MontadorDePdfDoCarimbo::classico(self::OBJETOS + [5 => "<</T(exemplo:\n999999999 0 obj)>>"])];
        yield 'cabeçalho com mais de 9 algarismos' => [MontadorDePdfDoCarimbo::classico(self::OBJETOS + [5 => "(texto\n1234567890 0 obj)"])];
        yield '/Size que deixa o /Size novo um acima do limite' => [str_replace('/Size 5', '/Size 8388604', $classico)];
        yield '/Size de um trailer antigo acima do limite' => [OficinaDePdfAssinado::acrescimoSemAssinatura(str_replace('/Size 5', '/Size 9000000', $classico), [1 => self::OBJETOS[1]], 5)];
        yield 'pares de /Index acima do teto de cabeçalhos' => [MontadorDePdfDoCarimbo::classico(self::OBJETOS + [5 => '<</Type/XRef/Index[' . str_repeat('0 1 ', LimitesDaConferencia::CABECALHOS_MAXIMOS_DO_ARQUIVO + 1) . ']/Length 0>>' . "\nstream\n\nendstream"])];
        yield 'faixa do /Index acima do limite' => [MontadorDePdfDoCarimbo::classico(self::OBJETOS + [5 => '<</Type/XRef/Index[8388600 4]/Length 0>>' . "\nstream\n\nendstream"])];
    }

    #[DataProvider('acimaDoLimite')]
    #[TestDox('objetos do carimbo além do limite do PDF 1.7 ($_dataName): estrutura não reconhecida')]
    public function testAcimaDoLimite(string $pdf): void
    {
        self::assertNull(CarimboVisivelIndice::ler($pdf));
    }

    #[TestDox('startxref: vale o último seguido de número, como o último casamento do protótipo')]
    public function testUltimoStartxref(): void
    {
        $classico = MontadorDePdfDoCarimbo::classico(self::OBJETOS);
        preg_match('/startxref\n(\d+)/', $classico, $xref);

        self::assertSame((int) $xref[1], CarimboVisivelIndice::ler($classico . "startxref\n%%EOF\n")?->anterior);
        $comTexto = MontadorDePdfDoCarimbo::classico(self::OBJETOS + [5 => '(startxref 1)']);
        preg_match('/startxref\n(\d+)/', $comTexto, $xrefDoTexto);
        self::assertSame((int) $xrefDoTexto[1], CarimboVisivelIndice::ler($comTexto)?->anterior, 'um `startxref` antes não conta');
        self::assertNull(CarimboVisivelIndice::ler($classico . "startxref\n9999999999\n%%EOF\n"), 'mais de 9 algarismos');
    }
}
