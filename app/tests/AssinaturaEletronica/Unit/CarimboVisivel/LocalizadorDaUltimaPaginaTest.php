<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\CarimboVisivel;

use App\AssinaturaEletronica\Service\CarimboVisivelPagina;
use App\AssinaturaEletronica\Service\LocalizadorDaUltimaPagina;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalizadorDaUltimaPagina::class)]
#[CoversClass(CarimboVisivelPagina::class)]
final class LocalizadorDaUltimaPaginaTest extends TestCase
{
    private const CATALOGO = '<</Type/Catalog/Pages 2 0 R>>';

    private static function localizar(string $pdf): ?CarimboVisivelPagina
    {
        return (new LocalizadorDaUltimaPagina())->localizar($pdf, 1, 0);
    }

    #[TestDox('página única: o objeto, o corpo, a caixa e sem /Annots')]
    public function testPaginaUnica(): void
    {
        $pagina = self::localizar(OficinaDePdfAssinado::pdfSemAssinatura());

        self::assertNotNull($pagina);
        self::assertSame([3, 0], [$pagina->numero, $pagina->geracao]);
        self::assertSame('<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>', $pagina->corpo);
        self::assertSame([0.0, 0.0, 595.0, 842.0], $pagina->caixa);
        self::assertNull($pagina->trechoDasAnotacoes);
        self::assertNull($pagina->anotacoesDiretas);
        self::assertNull($pagina->referenciaDasAnotacoes);
        self::assertNull($pagina->vetorDasAnotacoes);
    }

    #[TestDox('árvore aninhada: desce pelo último filho, pula o nó vazio (/Count 0) e herda o /MediaBox do pai')]
    public function testArvoreAninhada(): void
    {
        $pagina = self::localizar(MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R 4 0 R 8 0 R]/Count 3/MediaBox[0 0 612 792]>>',
            3 => '<</Type/Pages/Parent 2 0 R/Kids[5 0 R 6 0 R]/Count 2>>',
            4 => '<</Type/Pages/Parent 2 0 R/Kids[7 0 R]/Count 1/MediaBox[10 20 310 420]>>',
            5 => '<</Type/Page/Parent 3 0 R>>',
            6 => '<</Type/Page/Parent 3 0 R>>',
            7 => '<</Type/Page/Parent 4 0 R>>',
            8 => '<</Type/Pages/Parent 2 0 R/Kids[]/Count 0>>',
        ]));

        self::assertNotNull($pagina);
        self::assertSame(7, $pagina->numero);
        self::assertSame([10.0, 20.0, 310.0, 420.0], $pagina->caixa, 'o /MediaBox do nó mais próximo');
    }

    #[TestDox('/MediaBox herdado da raiz, através de um nó sem caixa; /Kids em objeto à parte')]
    public function testCaixaDaRaizEKidsIndireto(): void
    {
        $pagina = self::localizar(MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R]/Count 2/MediaBox[0 0 400 500]>>',
            3 => '<</Type/Pages/Parent 2 0 R/Kids 6 0 R/Count 2>>',
            4 => '<</Type/Page/Parent 3 0 R>>',
            5 => '<</Type/Page/Parent 3 0 R>>',
            6 => '[4 0 R 5 0 R]',
        ]));

        self::assertNotNull($pagina);
        self::assertSame(5, $pagina->numero);
        self::assertSame([0.0, 0.0, 400.0, 500.0], $pagina->caixa);
    }

    /** @return iterable<string, array{string, list<float>}> */
    public static function caixas(): iterable
    {
        yield 'sem /MediaBox em lugar nenhum: carta' => ['', [0.0, 0.0, 612.0, 792.0]];
        yield 'cantos trocados: normalizada' => ['/MediaBox[612 792 0 0]', [0.0, 0.0, 612.0, 792.0]];
        yield 'com fração e sinal' => ['/MediaBox[-10.5 .5 +200 300.25]', [-10.5, 0.5, 200.0, 300.25]];
        yield 'em objeto à parte' => ['/MediaBox 4 0 R', [0.0, 0.0, 200.0, 300.0]];
        yield 'sem área: carta' => ['/MediaBox[0 0 0 300]', [0.0, 0.0, 612.0, 792.0]];
        yield 'com três números: carta, sem procurar no pai' => ['/MediaBox[0 0 300]', [0.0, 0.0, 612.0, 792.0]];
    }

    /** @param list<float> $esperada */
    #[DataProvider('caixas')]
    #[TestDox('/MediaBox $_dataName')]
    public function testCaixa(string $caixa, array $esperada): void
    {
        $pai = $caixa === '/MediaBox[0 0 300]' ? '/MediaBox[0 0 100 100]' : '';
        $pagina = self::localizar(MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1' . $pai . '>>',
            3 => '<</Type/Page/Parent 2 0 R' . $caixa . '>>',
            4 => '[0 0 200 300]',
        ]));

        self::assertNotNull($pagina);
        self::assertSame($esperada, $pagina->caixa);
    }

    /** @return iterable<string, array{string, string, list<float>}> */
    public static function cortes(): iterable
    {
        yield 'menor e deslocado: vale o /CropBox' => ['', '/MediaBox[0 0 595 842]/CropBox[50 60 400 500]', [50.0, 60.0, 400.0, 500.0]];
        yield 'herdado do nó: recortado pelo /MediaBox da página' => ['/CropBox[100 200 700 900]', '/MediaBox[0 0 595 842]', [100.0, 200.0, 595.0, 842.0]];
        yield 'maior que o /MediaBox: fica recortado' => ['', '/MediaBox[0 0 300 400]/CropBox[-50 -50 1000 1000]', [0.0, 0.0, 300.0, 400.0]];
        yield 'em parte fora do /MediaBox: só a área em comum' => ['', '/MediaBox[0 0 300 400]/CropBox[100 150 1000 1000]', [100.0, 150.0, 300.0, 400.0]];
        yield 'sem área em comum: o /MediaBox' => ['', '/MediaBox[0 0 300 400]/CropBox[400 500 600 700]', [0.0, 0.0, 300.0, 400.0]];
        yield 'só encostando no /MediaBox: o /MediaBox' => ['', '/MediaBox[0 0 300 400]/CropBox[300 0 600 400]', [0.0, 0.0, 300.0, 400.0]];
        yield 'inválido: o /MediaBox, sem procurar no nó' => ['/CropBox[10 10 50 50]', '/MediaBox[0 0 300 400]/CropBox[1 2 3]', [0.0, 0.0, 300.0, 400.0]];
        yield 'sem /MediaBox: recortado pelo tamanho carta' => ['', '/CropBox[500 700 900 900]', [500.0, 700.0, 612.0, 792.0]];
    }

    /** @param list<float> $esperada */
    #[DataProvider('cortes')]
    #[TestDox('/CropBox $_dataName (page.view do pdf.js)')]
    public function testCorte(string $no, string $pagina, array $esperada): void
    {
        $localizada = self::localizar(MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1' . $no . '>>',
            3 => '<</Type/Page/Parent 2 0 R' . $pagina . '>>',
        ]));

        self::assertNotNull($localizada);
        self::assertSame($esperada, $localizada->caixa);
    }

    #[TestDox('/Annots direto: o miolo do vetor e o trecho "/Annots [...]" no corpo')]
    public function testAnotacoesDiretas(): void
    {
        $pagina = self::localizar(OficinaDePdfAssinado::pdfParaAssinar());

        self::assertNotNull($pagina);
        self::assertSame('4 0 R', $pagina->anotacoesDiretas);
        self::assertNotNull($pagina->trechoDasAnotacoes);
        [$inicio, $fim] = $pagina->trechoDasAnotacoes;
        self::assertSame('/Annots[4 0 R]', substr($pagina->corpo, $inicio, $fim - $inicio));
        self::assertNull($pagina->referenciaDasAnotacoes);
    }

    #[TestDox('/Annots indireto: a referência e o corpo do vetor; vetor ausente fica null')]
    public function testAnotacoesIndiretas(): void
    {
        $objetos = [
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/Annots 9 0 R/MediaBox[0 0 595 842]>>',
            9 => '[5 0 R 6 0 R]',
        ];
        $pagina = self::localizar(MontadorDePdfDoCarimbo::classico($objetos));
        unset($objetos[9]);
        $semVetor = self::localizar(MontadorDePdfDoCarimbo::classico($objetos));

        self::assertNotNull($pagina);
        self::assertSame([9, 0], $pagina->referenciaDasAnotacoes);
        self::assertSame('[5 0 R 6 0 R]', $pagina->vetorDasAnotacoes);
        self::assertNull($pagina->anotacoesDiretas);
        self::assertNotNull($semVetor);
        self::assertSame([9, 0], $semVetor->referenciaDasAnotacoes);
        self::assertNull($semVetor->vetorDasAnotacoes);
    }

    #[TestDox('/Annots com outro valor: só o trecho')]
    public function testAnotacoesComOutroValor(): void
    {
        $pagina = self::localizar(MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/Annots null>>',
        ]));

        self::assertNotNull($pagina);
        self::assertNotNull($pagina->trechoDasAnotacoes);
        self::assertNull($pagina->anotacoesDiretas);
        self::assertNull($pagina->referenciaDasAnotacoes);
    }

    #[TestDox('só o primeiro nível do dicionário: /MediaBox e /Annots de dentro de outro valor não contam')]
    public function testPrimeiroNivel(): void
    {
        $pagina = self::localizar(MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => "<</Type/Page/PieceInfo<</X<</Annots[8 0 R]/MediaBox[0 0 1 1]>>/T(a >> b \\) [ ] (c))/H<4142>>>"
                . "% comentário /Annots[7 0 R]\n/Parent 2 0 R/MediaBox [0 0 300 400] /Annots [ 5 0 R ]>>",
        ]));

        self::assertNotNull($pagina);
        self::assertSame([0.0, 0.0, 300.0, 400.0], $pagina->caixa);
        self::assertSame(' 5 0 R ', $pagina->anotacoesDiretas);
        self::assertNotNull($pagina->trechoDasAnotacoes);
        self::assertSame('/Annots [ 5 0 R ]', substr($pagina->corpo, $pagina->trechoDasAnotacoes[0], $pagina->trechoDasAnotacoes[1] - $pagina->trechoDasAnotacoes[0]));
    }

    #[TestDox('fluxo /XRef com a página dentro de /ObjStm comprimido')]
    public function testPaginaEmFluxoDeObjetos(): void
    {
        $pagina = self::localizar(MontadorDePdfDoCarimbo::assinadoComFluxoDeXref());

        self::assertNotNull($pagina);
        self::assertSame(3, $pagina->numero);
        self::assertSame('<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[4 0 R]>>', $pagina->corpo);
    }

    #[TestDox('vale a versão mais nova da árvore, depois do acréscimo incremental')]
    public function testVersaoMaisNova(): void
    {
        $pdf = OficinaDePdfAssinado::acrescimoSemAssinatura(OficinaDePdfAssinado::pdfSemAssinatura(), [
            2 => '<</Type/Pages/Kids[3 0 R 4 0 R]/Count 2>>',
            4 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 300]>>',
        ]);

        self::assertSame(4, self::localizar($pdf)?->numero);
    }

    /** @return iterable<string, array{array<int, string>}> */
    public static function arvoresRuins(): iterable
    {
        yield 'catálogo sem /Pages' => [[1 => '<</Type/Catalog>>']];
        yield 'catálogo que não é dicionário' => [[1 => '[2 0 R]']];
        yield '/Pages aponta para objeto ausente' => [[1 => self::CATALOGO]];
        yield 'nó sem filhos' => [[1 => self::CATALOGO, 2 => '<</Type/Pages/Kids[]/Count 1>>']];
        yield 'só nós vazios' => [[1 => self::CATALOGO, 2 => '<</Type/Pages/Kids[3 0 R]/Count 0>>', 3 => '<</Type/Pages/Kids[]/Count 0>>']];
        yield 'ciclo na árvore' => [[1 => self::CATALOGO, 2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>', 3 => '<</Type/Pages/Kids[2 0 R]/Count 1>>']];
        yield 'dicionário sem fim' => [[1 => self::CATALOGO, 2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>', 3 => '<</Type/Page/T(sem fim']];
    }

    /** @param array<int, string> $objetos */
    #[DataProvider('arvoresRuins')]
    #[TestDox('árvore ruim ($_dataName) devolve null, sem exceção')]
    public function testArvoreRuim(array $objetos): void
    {
        self::assertNull(self::localizar(MontadorDePdfDoCarimbo::classico($objetos)));
    }

    #[TestDox('raiz ausente devolve null')]
    public function testRaizAusente(): void
    {
        self::assertNull((new LocalizadorDaUltimaPagina())->localizar(OficinaDePdfAssinado::pdfSemAssinatura(), 9, 0));
    }

    #[TestDox('teto de leituras: corrente de nós além do teto devolve null; dentro dele acha a página')]
    public function testTetoDeLeituras(): void
    {
        $corrente = static function (int $nos): string {
            $objetos = [1 => self::CATALOGO];
            for ($numero = 2; $numero < $nos + 2; ++$numero) {
                $objetos[$numero] = '<</Type/Pages/Kids[' . ($numero + 1) . ' 0 R]/Count 1>>';
            }
            $objetos[$nos + 2] = '<</Type/Page/MediaBox[0 0 100 100]>>';

            return MontadorDePdfDoCarimbo::classico($objetos);
        };

        self::assertSame(LocalizadorDaUltimaPagina::LEITURAS_MAXIMAS, self::localizar($corrente(LocalizadorDaUltimaPagina::LEITURAS_MAXIMAS - 2))?->numero);
        self::assertNull(self::localizar($corrente(LocalizadorDaUltimaPagina::LEITURAS_MAXIMAS)));
    }
}
