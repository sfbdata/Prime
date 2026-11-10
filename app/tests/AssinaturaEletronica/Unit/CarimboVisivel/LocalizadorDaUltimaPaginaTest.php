<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\CarimboVisivel;

use App\AssinaturaEletronica\Service\CarimboVisivelObjetos;
use App\AssinaturaEletronica\Service\CarimboVisivelPagina;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\Service\LocalizadorDaUltimaPagina;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalizadorDaUltimaPagina::class)]
#[CoversClass(CarimboVisivelPagina::class)]
#[CoversClass(CarimboVisivelObjetos::class)]
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
        // `[0 0 200 300` e `]` somam 13 bytes; o resto é espaço.
        yield 'texto no teto da caixa: lida' => ['/MediaBox[0 0 200 300' . str_repeat(' ', LocalizadorDaUltimaPagina::CAIXA_MAXIMA_EM_BYTES - 13) . ']', [0.0, 0.0, 200.0, 300.0]];
        yield 'texto um acima do teto da caixa: ilegível, carta' => ['/MediaBox[0 0 200 300' . str_repeat(' ', LocalizadorDaUltimaPagina::CAIXA_MAXIMA_EM_BYTES - 12) . ']', [0.0, 0.0, 612.0, 792.0]];
        yield 'número terminado em ponto' => ['/MediaBox[0. 0 200. 300.5]', [0.0, 0.0, 200.0, 300.5]];
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

    #[TestDox('teto de objetos: corrente de nós além do teto devolve null; dentro dele acha a página')]
    public function testTetoDeObjetos(): void
    {
        $corrente = static function (int $nos): string {
            $objetos = [1 => self::CATALOGO];
            for ($numero = 2; $numero < $nos + 2; ++$numero) {
                $objetos[$numero] = '<</Type/Pages/Kids[' . ($numero + 1) . ' 0 R]/Count 1>>';
            }
            $objetos[$nos + 2] = '<</Type/Page/MediaBox[0 0 100 100]>>';

            return MontadorDePdfDoCarimbo::classico($objetos);
        };

        self::assertSame(CarimboVisivelObjetos::OBJETOS_MAXIMOS, self::localizar($corrente(CarimboVisivelObjetos::OBJETOS_MAXIMOS - 2))?->numero);
        self::assertNull(self::localizar($corrente(CarimboVisivelObjetos::OBJETOS_MAXIMOS - 1)));
    }

    #[TestDox('comentário com << antes do dicionário da página: a abertura é o << depois dele')]
    public function testAberturaDoDicionarioDepoisDeComentario(): void
    {
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => "% a << b\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>",
        ]);
        $pagina = self::localizar($pdf);

        self::assertNotNull($pagina);
        self::assertSame(9, $pagina->aberturaDoDicionario);
        self::assertSame([0.0, 0.0, 595.0, 842.0], $pagina->caixa);
        self::assertSame(0, self::localizar(OficinaDePdfAssinado::pdfSemAssinatura())?->aberturaDoDicionario);
    }

    #[TestDox('sonda: nó vazio listado 63 vezes no /Kids é lido e analisado uma vez só')]
    public function testNoVazioRepetidoNoKids(): void
    {
        // 80 mil passos de análise (~82 MB do orçamento): analisado duas vezes, passaria do orçamento da procura.
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R' . str_repeat(' 4 0 R', 63) . ']/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>',
            4 => '<</Type/Pages/Kids[]/Count 0/Lixo[' . str_repeat('[]', 40_000) . ']>>',
        ]);

        [$pagina, $segundos] = self::medir(static fn (): ?CarimboVisivelPagina => self::localizar($pdf));

        self::assertSame(3, $pagina?->numero);
        self::assertLessThan(2.0, $segundos);
    }

    /** @return iterable<string, array{int}> */
    public static function catalogosGrandes(): iterable
    {
        yield '3 MB' => [3 * 1024 * 1024];
        yield '10 MB' => [10 * 1024 * 1024];
    }

    #[DataProvider('catalogosGrandes')]
    #[TestDox('sonda: catálogo de $_dataName com entradas pequenas recusa pelo teto do corpo, sem analisar')]
    public function testCatalogoGrandeComEntradasPequenas(int $tamanho): void
    {
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R' . MontadorDePdfDoCarimbo::entradasDistintas(intdiv($tamanho, 7)) . '>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>',
        ]);

        [$pagina, $segundos, $memoria] = self::medir(static fn (): ?CarimboVisivelPagina => self::localizar($pdf));

        self::assertNull($pagina);
        self::assertLessThan(2.0, $segundos);
        self::assertLessThan(3 * \strlen($pdf), $memoria);
    }

    #[TestDox('sonda: catálogo abaixo do teto do corpo com entradas pequenas recusa pela memória que as entradas guardam')]
    public function testCatalogoComEntradasPequenas(): void
    {
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R' . MontadorDePdfDoCarimbo::entradasDistintas(140_000) . '>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>',
        ]);

        [$pagina, $segundos, $memoria] = self::medir(static fn (): ?CarimboVisivelPagina => self::localizar($pdf));

        self::assertLessThan(CarimboVisivelObjetos::CORPO_MAXIMO_EM_BYTES, \strlen($pdf));
        self::assertNull($pagina);
        self::assertLessThan(2.0, $segundos);
        // Sem o teto, as 140 mil entradas ocupariam ~48 MB.
        self::assertLessThan(CarimboVisivelObjetos::GUARDADO_MAXIMO_EM_BYTES + 4 * 1024 * 1024, $memoria);
    }

    /** @return iterable<string, array{int}> */
    public static function gruposVazios(): iterable
    {
        yield '900 KB (passos da análise)' => [450_000];
        yield '15 MB (teto do corpo)' => [7_500_000];
    }

    #[DataProvider('gruposVazios')]
    #[TestDox('sonda: catálogo com `[]` repetido de $_dataName recusa rápido')]
    public function testCatalogoComGruposVazios(int $grupos): void
    {
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R/X[' . str_repeat('[]', $grupos) . ']>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>',
        ]);

        [$pagina, $segundos, $memoria] = self::medir(static fn (): ?CarimboVisivelPagina => self::localizar($pdf));

        self::assertNull($pagina);
        self::assertLessThan(2.0, $segundos);
        self::assertLessThan(3 * \strlen($pdf), $memoria);
    }

    #[TestDox('sonda: 9.990 /ObjStm com /N 10000 e cabeçalho vazio recusam pelo teto de entradas, antes de percorrer o /N')]
    public function testFluxosComNAltoECabecalhoVazio(): void
    {
        $objetos = [1 => self::CATALOGO];
        for ($numero = 3; $numero < 9_993; ++$numero) {
            $objetos[$numero] = "<</Type/ObjStm/N 10000/First 0/Length 0>>\nstream\n\nendstream";
        }
        $pdf = MontadorDePdfDoCarimbo::classico($objetos);

        [$pagina, $segundos, $memoria] = self::medir(static fn (): ?CarimboVisivelPagina => self::localizar($pdf));

        self::assertNull($pagina);
        self::assertLessThan(2.0, $segundos);
        self::assertLessThan(3 * \strlen($pdf), $memoria);
    }

    #[TestDox('sonda: nove /ObjStm-isca de 10 MB inflados depois dos objetos de verdade recusam sem inflar todas')]
    public function testFluxosDeObjetosIsca(): void
    {
        $objetos = [
            1 => self::CATALOGO,
            3 => MontadorDePdfDoCarimbo::fluxoDeObjetos([
                2 => '<</Type/Pages/Kids[4 0 R]/Count 1>>',
                4 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>',
            ]),
        ];
        // Cada isca infla a quase 10 MB, abaixo do teto de um fluxo do motor: só o orçamento da procura as barra.
        $isca = MontadorDePdfDoCarimbo::fluxoDeObjetos([99 => '<<>>'], LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES - 64);
        for ($numero = 5; $numero < 14; ++$numero) {
            $objetos[$numero] = $isca;
        }
        $pdf = MontadorDePdfDoCarimbo::classico($objetos);

        [$pagina, $segundos, $memoria] = self::medir(static fn (): ?CarimboVisivelPagina => self::localizar($pdf));

        self::assertLessThan(200 * 1024, \strlen($pdf));
        self::assertNull($pagina);
        self::assertLessThan(2.0, $segundos);
        self::assertLessThan(CarimboVisivelObjetos::GUARDADO_MAXIMO_EM_BYTES + 8 * 1024 * 1024, $memoria);
        unset($objetos[5], $objetos[6], $objetos[7], $objetos[8], $objetos[9], $objetos[10], $objetos[11], $objetos[12]);
        self::assertSame(4, self::localizar(MontadorDePdfDoCarimbo::classico($objetos))?->numero, 'com uma isca só, cabe');
    }

    #[TestDox('sonda: /Kids de 1,5 milhão de referências recusa pelo teto do corpo, sem montar a lista')]
    public function testKidsGigante(): void
    {
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[' . str_repeat('3 0 R ', 1_500_000) . ']/Count 1500000>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>',
        ]);

        [$pagina, $segundos, $memoria] = self::medir(static fn (): ?CarimboVisivelPagina => self::localizar($pdf));

        self::assertNull($pagina);
        self::assertLessThan(2.0, $segundos);
        // A lista de 1,5 milhão de pares passaria de 700 MB.
        self::assertLessThan(\strlen($pdf), $memoria);
    }

    #[TestDox('teto de filhos do /Kids: no teto acha a página; uma referência a mais devolve null')]
    public function testTetoDeFilhos(): void
    {
        $kids = static fn (int $filhos): string => MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[' . str_repeat('3 0 R ', $filhos) . ']/Count ' . $filhos . '>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>',
        ]);

        self::assertSame(3, self::localizar($kids(LocalizadorDaUltimaPagina::FILHOS_MAXIMOS))?->numero);
        self::assertNull(self::localizar($kids(LocalizadorDaUltimaPagina::FILHOS_MAXIMOS + 1)));
    }

    /**
     * O resultado, os segundos e o pico de memória acima do que já estava em uso.
     *
     * @param \Closure(): ?CarimboVisivelPagina $procura
     *
     * @return array{?CarimboVisivelPagina, float, int}
     */
    private static function medir(\Closure $procura): array
    {
        $antes = memory_get_usage();
        memory_reset_peak_usage();
        $inicio = hrtime(true);
        $pagina = $procura();

        return [$pagina, (hrtime(true) - $inicio) / 1e9, memory_get_peak_usage() - $antes];
    }

    #[TestDox('sonda: /MediaBox e /CropBox com um milhão de algarismos são ilegíveis pelo teto da caixa, sem passar pela expressão')]
    public function testCaixaComMilhaoDeAlgarismos(): void
    {
        $vetor = '[' . str_repeat('1', 1_000_000) . ' 1 1 1 x]';
        $pdf = MontadorDePdfDoCarimbo::classico([
            1 => self::CATALOGO,
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox 4 0 R/CropBox 5 0 R>>',
            4 => $vetor,
            5 => $vetor,
        ]);

        [$pagina, $segundos, $memoria] = self::medir(static fn (): ?CarimboVisivelPagina => self::localizar($pdf));

        self::assertSame(LocalizadorDaUltimaPagina::CAIXA_CARTA, $pagina?->caixa);
        self::assertLessThan(0.5, $segundos);
        self::assertLessThan(3 * \strlen($pdf), $memoria);
    }

    #[TestDox('falha do PCRE (limite de retrocesso) devolve null: nunca vira caixa ilegível no tamanho carta')]
    public function testFalhaDaExpressao(): void
    {
        $pdf = OficinaDePdfAssinado::pdfSemAssinatura();
        $limite = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $pagina = self::localizar($pdf);
        } finally {
            ini_set('pcre.backtrack_limit', $limite);
        }

        self::assertNull($pagina);
        self::assertNotNull(self::localizar($pdf));
    }
}
