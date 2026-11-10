<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\CarimboVisivel;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Service\CarimboVisivel;
use App\AssinaturaEletronica\Service\CarimboVisivelAcrescimo;
use App\AssinaturaEletronica\Service\CarimboVisivelAssinatura;
use App\AssinaturaEletronica\Service\CarimboVisivelIndice;
use App\AssinaturaEletronica\Service\CarimboVisivelResultado;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;
use App\AssinaturaEletronica\Service\LocalizadorDaUltimaPagina;
use App\Entity\Tenant\Tenant;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CarimboVisivel::class)]
#[CoversClass(CarimboVisivelAcrescimo::class)]
#[CoversClass(CarimboVisivelAssinatura::class)]
#[CoversClass(CarimboVisivelIndice::class)]
#[CoversClass(CarimboVisivelResultado::class)]
final class CarimboVisivelTest extends TestCase
{
    private const CODIGO = 'BJA-7K2M-9QXD-4HTR';
    /** As duas linhas do carimbo de uma assinatura, em Latin-1, como vão no PDF. */
    private const LINHA_DA_FULANA = "Assinatura eletr\xF4nica BlueJus: Fulana de Tal, CPF ***.982.***-**, em 09/11/2026, 14:05:03";
    private const LINHA_DO_CODIGO = "C\xF3digo BJA-7K2M-9QXD-4HTR. Confira em bluejus.com.br.";

    private string $fusoAnterior;

    protected function setUp(): void
    {
        $this->fusoAnterior = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->fusoAnterior);
    }

    private static function assinatura(string $nome = 'Fulana de Tal'): CarimboVisivelAssinatura
    {
        return new CarimboVisivelAssinatura($nome, '529.982.247-25', new \DateTimeImmutable('2026-11-09T17:05:03Z'));
    }

    /** @param list<CarimboVisivelAssinatura>|null $assinaturas */
    private static function carimbar(string $pdf, ?array $assinaturas = null): CarimboVisivelResultado
    {
        return (new CarimboVisivel())->carimbar($pdf, $assinaturas ?? [self::assinatura()], self::CODIGO);
    }

    /** @param list<CarimboVisivelAssinatura>|null $assinaturas */
    private static function carimbado(string $pdf, ?array $assinaturas = null): string
    {
        $resultado = self::carimbar($pdf, $assinaturas);
        self::assertTrue($resultado->foiCarimbado(), (string) $resultado->recusa);
        self::assertNull($resultado->recusa);

        return (string) $resultado->pdf;
    }

    #[TestDox('linha da assinatura: nome, CPF com só o 4º ao 6º dígito e a data como o toLocaleString pt-BR')]
    public function testLinhaDaAssinatura(): void
    {
        self::assertSame(
            'Assinatura eletrônica BlueJus: Fulana de Tal, CPF ***.982.***-**, em 09/11/2026, 14:05:03',
            self::assinatura()->linha(),
        );
        self::assertSame(
            'Assinatura eletrônica BlueJus: Sem CPF, CPF ***..***-**, em 09/11/2026, 14:05:03',
            (new CarimboVisivelAssinatura('Sem CPF', null, new \DateTimeImmutable('2026-11-09T17:05:03Z')))->linha(),
            'sem CPF, como o String(x.cpfAss || "") do desenho',
        );
    }

    #[TestDox('linhas: até 6 assinaturas e a do código por último')]
    public function testLinhas(): void
    {
        $assinaturas = array_map(static fn (int $i): CarimboVisivelAssinatura => self::assinatura('Pessoa ' . $i), range(1, 7));
        $linhas = CarimboVisivel::linhas($assinaturas, self::CODIGO);

        self::assertCount(7, $linhas);
        self::assertStringStartsWith('Assinatura eletrônica BlueJus: Pessoa 6, ', $linhas[5]);
        self::assertSame('Código BJA-7K2M-9QXD-4HTR. Confira em bluejus.com.br.', $linhas[6]);
        self::assertSame(['Código X. Confira em bluejus.com.br.'], CarimboVisivel::linhas([], 'X'));
    }

    #[TestDox('dos signatários: só quem assinou com rubrica, com o nome e o CPF digitados')]
    public function testDosSignatarios(): void
    {
        $documento = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable('2026-11-09 09:00:00'));
        $agora = new \DateTimeImmutable('2026-11-09T17:05:03Z');
        $assinou = new Signatario($documento, 0, 'Ana do Convite', 'ana@x.com', '11144477735', 'Parte', NivelDeAssinatura::Simples);
        $assinou->registrarAssinatura('Ana Digitada', '529.982.247-25', 'rubrica/ana.png', 'email', null, null, $agora);
        $semRubrica = new Signatario($documento, 1, 'Bia', null, null, 'Parte', NivelDeAssinatura::Simples);
        $semRubrica->registrarAssinatura('Bia', '111.444.777-35', '', 'email', null, null, $agora);
        $recusou = new Signatario($documento, 2, 'Caio', null, null, 'Parte', NivelDeAssinatura::Simples);
        $recusou->registrarRecusa('não', null, null);
        $pendente = new Signatario($documento, 3, 'Duda', null, null, 'Parte', NivelDeAssinatura::Simples);

        $assinaturas = CarimboVisivelAssinatura::dosSignatarios([$assinou, $semRubrica, $recusou, $pendente]);

        self::assertCount(1, $assinaturas);
        self::assertSame('Ana Digitada', $assinaturas[0]->nome);
        self::assertSame('529.982.247-25', $assinaturas[0]->cpf);
        self::assertSame($agora->getTimestamp(), $assinaturas[0]->assinadaEm->getTimestamp());
    }

    #[TestDox('sem assinatura BlueJus: o texto do vazio, sem "Use o original."')]
    public function testSemAssinaturaBlueJus(): void
    {
        $resultado = self::carimbar(OficinaDePdfAssinado::pdfAssinado(), []);

        self::assertFalse($resultado->foiCarimbado());
        self::assertNull($resultado->pdf);
        self::assertSame('Ainda não há assinatura eletrônica feita pelo BlueJus para carimbar.', $resultado->recusa);
        self::assertStringEndsNotWith('Use o original.', (string) $resultado->recusa);
    }

    #[TestDox('xref clássica: acréscimo incremental com anotação, aparência, fonte, página e trailer com /Prev')]
    public function testXrefClassica(): void
    {
        $original = OficinaDePdfAssinado::pdfAssinadoComVinculo();
        $carimbado = self::carimbado($original);
        $acrescimo = substr($carimbado, \strlen($original));

        self::assertStringStartsWith($original, $carimbado);
        self::assertStringContainsString(
            "6 0 obj\n<</Type/Annot/Subtype/Stamp/Name/BlueJus/Rect[20 10 400 42]/F 4/P 3 0 R/Contents("
            . self::LINHA_DA_FULANA . ' ' . self::LINHA_DO_CODIGO . ")/AP<</N 7 0 R>>>>\nendobj\n",
            $acrescimo,
        );
        self::assertStringContainsString("8 0 obj\n<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>\nendobj\n", $acrescimo);
        self::assertStringContainsString("3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots [4 0 R 6 0 R]>>\nendobj\n", $acrescimo);
        preg_match_all('/startxref\s+(\d+)/', $original, $xref);
        self::assertStringContainsString("trailer\n<</Size 9/Root 1 0 R  /Prev " . end($xref[1]) . ">>\nstartxref\n", $acrescimo);
        self::assertStringEndsWith("\n%%EOF\n", $carimbado);
        self::assertSame([3, 6, 7, 8], self::conferirIndice($carimbado, $acrescimo));
        self::assertPreservado($original, $carimbado, 1);
    }

    #[TestDox('fluxo /XRef: a página sai do /ObjStm para um objeto solto e o índice novo é um fluxo /XRef')]
    public function testFluxoDeXref(): void
    {
        $original = MontadorDePdfDoCarimbo::assinadoComFluxoDeXref();
        $carimbado = self::carimbado($original);
        $acrescimo = substr($carimbado, \strlen($original));

        self::assertStringStartsWith($original, $carimbado);
        self::assertStringContainsString("3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots [4 0 R 8 0 R]>>\nendobj\n", $acrescimo);
        preg_match_all('/startxref\s+(\d+)/', $original, $xref);
        self::assertStringContainsString(
            "11 0 obj\n<</Type/XRef/Size 12/W[1 4 2]/Index[3 1 8 1 9 1 10 1 11 1]/Root 1 0 R  /Prev " . end($xref[1]) . '/Length 35>>',
            $acrescimo,
        );
        self::assertStringNotContainsString('trailer', $acrescimo);
        self::assertSame([3, 8, 9, 10, 11], self::conferirIndice($carimbado, $acrescimo));
        self::assertPreservado($original, $carimbado, 1);
        self::assertSame('4 0 R 8 0 R', (new LocalizadorDaUltimaPagina())->localizar($carimbado, 1, 0)?->anotacoesDiretas, 'o leitor acha a página nova');
    }

    #[TestDox('original que não termina em quebra de linha: o acréscimo começa com uma')]
    public function testOriginalSemQuebraNoFim(): void
    {
        $original = rtrim(OficinaDePdfAssinado::pdfSemAssinatura(), "\n");
        $carimbado = self::carimbado($original);
        $acrescimo = substr($carimbado, \strlen($original));

        self::assertStringStartsWith("\n4 0 obj\n<</Type/Annot/Subtype/Stamp/Name/BlueJus/", $acrescimo);
        self::assertSame([3, 4, 5, 6], self::conferirIndice($carimbado, $acrescimo));
    }

    #[TestDox('/Info e /ID do trailer passam para o trailer novo, com o texto do arquivo')]
    public function testInfoEId(): void
    {
        $original = OficinaDePdfAssinado::pdfSemAssinatura('/Info 2 0 R/ID [<0A0B><0C0D>]');
        $acrescimo = substr(self::carimbado($original), \strlen($original));

        self::assertMatchesRegularExpression('/<<\/Size 7\/Root 1 0 R \/Info 2 0 R \/ID \[<0A0B><0C0D>\]\/Prev \d+>>/', $acrescimo);
    }

    #[TestDox('aparência: geometria e cores do protótipo, Helvetica 7.6 e uma linha a cada 10 pontos')]
    public function testAparencia(): void
    {
        $original = OficinaDePdfAssinado::pdfAssinado();
        $acrescimo = substr(self::carimbado($original), \strlen($original));
        $fluxo = 'q 0.95 0.98 1 rg 0 0 380 32 re f 0.04 0.44 0.63 RG 0.7 w 0.4 0.4 379.2 31.2 re S BT /F1 7.6 Tf 0.05 0.2 0.31 rg 6 21 Td ('
            . self::LINHA_DA_FULANA . ') Tj 0 -10 Td (' . self::LINHA_DO_CODIGO . ') Tj ET Q';

        self::assertStringContainsString(
            "7 0 obj\n<</Type/XObject/Subtype/Form/BBox[0 0 380 32]/Resources<</Font<</F1 8 0 R>>>>/Length " . \strlen($fluxo)
            . ">>\nstream\n" . $fluxo . "\nendstream\nendobj\n",
            $acrescimo,
        );
    }

    #[TestDox('página estreita e caixa deslocada: largura da caixa - 40 e canto em (x0 + 20, y0 + 10)')]
    public function testPaginaEstreita(): void
    {
        $original = MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1/MediaBox[10 20 310.5 420]>>',
            3 => '<</Type/Page/Parent 2 0 R>>',
        ]);
        $acrescimo = substr(self::carimbado($original), \strlen($original));

        self::assertStringContainsString('/Rect[30 30 290.5 62]', $acrescimo);
        self::assertStringContainsString('/BBox[0 0 260.5 32]', $acrescimo);
        self::assertStringContainsString('q 0.95 0.98 1 rg 0 0 260.5 32 re f 0.04 0.44 0.63 RG 0.7 w 0.4 0.4 259.7 31.2 re S ', $acrescimo);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function areasVisiveis(): iterable
    {
        yield 'CropBox menor e deslocado' => ['', '/MediaBox[0 0 595 842]/CropBox[50 60 345.5 500]', '/Rect[70 70 325.5 102]', '/BBox[0 0 255.5 32]'];
        yield 'CropBox herdado do nó' => ['/CropBox[100 200 595 842]', '/MediaBox[0 0 595 842]', '/Rect[120 210 500 242]', '/BBox[0 0 380 32]'];
        yield 'CropBox maior que o MediaBox, recortado' => ['', '/MediaBox[0 0 300 400]/CropBox[-50 -50 1000 1000]', '/Rect[20 10 280 42]', '/BBox[0 0 260 32]'];
    }

    #[DataProvider('areasVisiveis')]
    #[TestDox('área visível: $_dataName (canto em cropX + 20, cropY + 10; largura min(380, largura do corte - 40))')]
    public function testAreaVisivel(string $no, string $pagina, string $retangulo, string $caixa): void
    {
        $original = MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1' . $no . '>>',
            3 => '<</Type/Page/Parent 2 0 R' . $pagina . '>>',
        ]);
        $acrescimo = substr(self::carimbado($original), \strlen($original));

        self::assertStringContainsString($retangulo, $acrescimo);
        self::assertStringContainsString($caixa, $acrescimo);
    }

    #[TestDox('sete assinaturas: o carimbo leva seis e o código (altura 12 + 10 x 7)')]
    public function testSeteAssinaturas(): void
    {
        $assinaturas = array_map(static fn (int $i): CarimboVisivelAssinatura => self::assinatura('Pessoa ' . $i), range(1, 7));
        $original = OficinaDePdfAssinado::pdfAssinado();
        $acrescimo = substr(self::carimbado($original, $assinaturas), \strlen($original));

        self::assertStringContainsString('/Rect[20 10 400 92]', $acrescimo);
        self::assertStringContainsString('/BBox[0 0 380 82]', $acrescimo);
        self::assertSame(7, substr_count($acrescimo, ') Tj'));
        self::assertStringContainsString('Pessoa 6, ', $acrescimo);
        self::assertStringNotContainsString('Pessoa 7', $acrescimo);
        self::assertStringContainsString('6 71 Td (', $acrescimo);
        self::assertStringContainsString('(' . self::LINHA_DO_CODIGO . ') Tj ET Q', $acrescimo);
    }

    #[TestDox('texto: fora de Latin-1 vira "?" (dois fora do plano básico) e \\ ( ) são escapados')]
    public function testTextoDoPdf(): void
    {
        $original = OficinaDePdfAssinado::pdfSemAssinatura();
        $acrescimo = substr(self::carimbado($original, [self::assinatura("Ana (filha) \\ \t Łucja 😀 Conceição")]), \strlen($original));

        self::assertStringContainsString("BlueJus: Ana \\(filha\\) \\\\ ? ?ucja ?? Concei\xE7\xE3o, CPF", $acrescimo);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function anotacoes(): iterable
    {
        yield 'sem /Annots: entra logo depois do <<' => [
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>',
            '<</Annots [5 0 R]/Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>',
            null,
        ];
        yield 'sem /Annots, com << num comentário antes: entra depois do << que abre o dicionário' => [
            "% a << b\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>",
            "% a << b\n<</Annots [5 0 R]/Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>",
            null,
        ];
        yield 'vetor direto: ganha a referência no fim' => [
            '<</Type/Page/Parent 2 0 R/Annots[ 9 0 R ]/MediaBox[0 0 595 842]>>',
            '<</Type/Page/Parent 2 0 R/Annots [ 9 0 R  5 0 R]/MediaBox[0 0 595 842]>>',
            null,
        ];
        yield 'vetor indireto: a página vai igual e o vetor ganha a referência' => [
            '<</Type/Page/Parent 2 0 R/Annots 4 0 R/MediaBox[0 0 595 842]>>',
            '<</Type/Page/Parent 2 0 R/Annots 4 0 R/MediaBox[0 0 595 842]>>',
            "4 0 obj\n[9 0 R 5 0 R]\nendobj\n",
        ];
    }

    #[DataProvider('anotacoes')]
    #[TestDox('/Annots $_dataName')]
    public function testAnotacoes(string $pagina, string $paginaNova, ?string $vetorNovo): void
    {
        $original = MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => $pagina,
            4 => '[9 0 R]',
        ]);
        $acrescimo = substr(self::carimbado($original), \strlen($original));

        self::assertStringContainsString("3 0 obj\n" . $paginaNova . "\nendobj\n", $acrescimo);
        if ($vetorNovo !== null) {
            self::assertStringContainsString($vetorNovo, $acrescimo);
        }
        self::assertSame($vetorNovo === null ? [3, 5, 6, 7] : [3, 4, 5, 6, 7], self::conferirIndice($original . $acrescimo, $acrescimo));
    }

    #[TestDox('/Size menor que os objetos em uso (1 a 4 com /Size 4): o carimbo começa no 5 e o /Size novo segue')]
    public function testSizeMenorQueOsObjetos(): void
    {
        $original = str_replace('/Size 5', '/Size 4', MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R>>',
            4 => "<</Length 0>>\nstream\n\nendstream",
        ]));
        self::assertStringContainsString('/Size 4/', $original);
        $carimbado = self::carimbado($original);
        $acrescimo = substr($carimbado, \strlen($original));

        self::assertStringContainsString("5 0 obj\n<</Type/Annot/Subtype/Stamp", $acrescimo);
        self::assertStringContainsString("3 0 obj\n<</Annots [5 0 R]/Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R>>\nendobj\n", $acrescimo);
        self::assertStringNotContainsString('4 0 obj', $acrescimo);
        self::assertStringContainsString("trailer\n<</Size 8/Root 1 0 R", $acrescimo);
        self::assertSame([3, 5, 6, 7], self::conferirIndice($carimbado, $acrescimo));
        self::assertPreservado($original, $carimbado, 0);
    }

    #[TestDox('objeto só no /ObjStm, /XRef sem /Index e atualização com /Size menor: o carimbo começa no 8, sem tocar no 7')]
    public function testObjetoSoNoFluxoComSizeMenor(): void
    {
        $original = CarimboVisivelIndiceTest::recursosSoNoFluxo();
        $carimbado = self::carimbado($original);
        $acrescimo = substr($carimbado, \strlen($original));

        self::assertStringContainsString("8 0 obj\n<</Type/Annot/Subtype/Stamp", $acrescimo);
        self::assertStringNotContainsString('7 0 obj', $acrescimo);
        self::assertStringContainsString("trailer\n<</Size 11/Root 1 0 R", $acrescimo);
        self::assertSame([3, 8, 9, 10], self::conferirIndice($carimbado, $acrescimo));
        self::assertPreservado($original, $carimbado, 0);
    }

    #[TestDox('carimba PDF sem assinatura digital e com DocMDP 3 (anotação permitida)')]
    public function testCarimbaSemAssinaturaDigitalEComDocMdp3(): void
    {
        $semAssinatura = OficinaDePdfAssinado::pdfSemAssinatura();
        $docMdp3 = OficinaDePdfAssinado::pdfAssinado('rsa', \sprintf(OficinaDePdfAssinado::DOCMDP, 3));

        self::assertPreservado($semAssinatura, self::carimbado($semAssinatura), 0);
        self::assertPreservado($docMdp3, self::carimbado($docMdp3), 1);
    }

    /** @return iterable<string, array{\Closure(): string, string}> */
    public static function recusas(): iterable
    {
        $semPaginas = static fn (): string => MontadorDePdfDoCarimbo::classico([1 => '<</Type/Catalog>>']);
        $entradasDemais = static fn (): string => MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R' . MontadorDePdfDoCarimbo::entradasDistintas(140_000) . '>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>',
        ]);
        $kidsDemais = static fn (): string => MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[' . str_repeat('3 0 R ', LocalizadorDaUltimaPagina::FILHOS_MAXIMOS + 1) . ']/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>',
        ]);
        $semVetor = static fn (): string => MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/Annots 9 0 R>>',
        ]);
        $outroValor = static fn (): string => MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/Annots 4 0 R>>',
            4 => '<</Nao/EVetor>>',
        ]);

        yield 'cifrado' => [static fn (): string => OficinaDePdfAssinado::pdfSemAssinatura('/Encrypt 9 0 R'), 'Arquivo protegido por senha.'];
        yield 'DocMDP 1' => [static fn (): string => OficinaDePdfAssinado::pdfAssinado('rsa', \sprintf(OficinaDePdfAssinado::DOCMDP, 1)), 'O primeiro signatário bloqueou alterações neste PDF.'];
        yield 'DocMDP 2' => [static fn (): string => OficinaDePdfAssinado::pdfAssinado('rsa', \sprintf(OficinaDePdfAssinado::DOCMDP, 2)), 'O primeiro signatário bloqueou alterações neste PDF.'];
        yield 'assinatura alterada' => [static fn (): string => str_replace('MediaBox[0 0 595 842]', 'MediaBox[0 0 595 843]', OficinaDePdfAssinado::pdfAssinado()), 'O primeiro signatário bloqueou alterações neste PDF.'];
        yield 'não é PDF' => [static fn (): string => 'isto não é um PDF', 'Estrutura do PDF não reconhecida.'];
        yield 'sem startxref' => [static fn (): string => "%PDF-1.7\n1 0 obj\n<</Type/Catalog>>\nendobj\n", 'Estrutura do PDF não reconhecida.'];
        yield 'startxref fora do arquivo' => [static fn (): string => "%PDF-1.7\nstartxref\n999999\n%%EOF\n", 'Estrutura do PDF não reconhecida.'];
        yield 'trailer sem /Root' => [static fn (): string => str_replace('/Root 1 0 R', '', OficinaDePdfAssinado::pdfSemAssinatura()), 'Estrutura do PDF não reconhecida.'];
        yield 'fluxo /XRef sem /Size' => [static fn (): string => (string) preg_replace('/\/Size \d+/', '', MontadorDePdfDoCarimbo::comFluxoDeXref([1 => '<</Type/Catalog/Pages 2 0 R>>'])), 'Estrutura do PDF não reconhecida.'];
        yield 'cabeçalho falso de 999999999 num texto' => [static fn (): string => MontadorDePdfDoCarimbo::classico([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => "<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/T(exemplo:\n999999999 0 obj)>>",
        ]), 'Estrutura do PDF não reconhecida.'];
        yield 'catálogo sem /Pages' => [$semPaginas, 'Página não localizada no PDF.'];
        yield 'catálogo com entradas demais para a memória da procura' => [$entradasDemais, 'Página não localizada no PDF.'];
        yield '/Kids com referências demais' => [$kidsDemais, 'Página não localizada no PDF.'];
        yield '/Annots indireto ausente' => [$semVetor, 'Anotações não localizadas.'];
        yield '/Annots indireto que não é vetor' => [$outroValor, 'Anotações não localizadas.'];
    }

    /** @param \Closure(): string $pdf */
    #[DataProvider('recusas')]
    #[TestDox('recusa ($_dataName) com o texto do desenho')]
    public function testRecusa(\Closure $pdf, string $texto): void
    {
        $resultado = self::carimbar($pdf());

        self::assertFalse($resultado->foiCarimbado());
        self::assertNull($resultado->pdf);
        self::assertSame($texto, $resultado->recusa);
        self::assertStringEndsNotWith('Use o original.', (string) $resultado->recusa);
    }

    #[TestDox('recusa leva só o motivo: nenhum texto de recusa termina com "Use o original." (o JS da tela acrescenta)')]
    public function testRecusaSoComOMotivo(): void
    {
        $textos = array_filter((new \ReflectionClass(CarimboVisivel::class))->getConstants(), 'is_string');

        self::assertSame('Não foi possível carimbar.', CarimboVisivel::FALHA);
        self::assertCount(8, $textos);
        foreach ($textos as $nome => $texto) {
            self::assertStringEndsNotWith('Use o original.', $texto, $nome);
        }
    }

    #[TestDox('verificação final: carimbo que mudaria o número de assinaturas para o motor é recusado')]
    public function testVerificacaoFinal(): void
    {
        $resultado = self::carimbar(OficinaDePdfAssinado::pdfAssinado(), [self::assinatura('/ByteRange [0 1 2 3]')]);

        self::assertNull($resultado->pdf);
        self::assertSame('Verificação final não confirmou a preservação.', $resultado->recusa);
        self::assertStringEndsNotWith('Use o original.', (string) $resultado->recusa);
    }

    /**
     * Cada entrada do índice novo aponta para o cabeçalho `N G obj` do objeto, dentro do acréscimo. Devolve os números.
     *
     * @return list<int>
     */
    private static function conferirIndice(string $carimbado, string $acrescimo): array
    {
        $entradas = MontadorDePdfDoCarimbo::indiceDoAcrescimo($acrescimo);
        foreach ($entradas as $numero => [$geracao, $posicao]) {
            self::assertGreaterThanOrEqual(\strlen($carimbado) - \strlen($acrescimo), $posicao);
            self::assertStringStartsWith($numero . ' ' . $geracao . " obj\n", substr($carimbado, $posicao), 'entrada do objeto ' . $numero);
        }

        return array_keys($entradas);
    }

    private static function assertPreservado(string $original, string $carimbado, int $assinaturas): void
    {
        $antes = (new ConferenciaDePdf())->conferir($original);
        $depois = (new ConferenciaDePdf())->conferir($carimbado, $original);

        self::assertSame(ConferenciaDoPrefixo::Confere, $depois->prefixo);
        self::assertCount($assinaturas, $depois->assinaturas);
        foreach ($antes->assinaturas as $i => $assinatura) {
            self::assertSame(VereditoDaAssinatura::Integra, $assinatura->veredito);
            self::assertSame(VereditoDaAssinatura::Integra, $depois->assinaturas[$i]->veredito, 'assinatura ' . ($i + 1));
        }
    }
}
