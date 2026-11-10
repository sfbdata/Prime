<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\AnalisadorDeAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\IndiceDoPdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LeitorDeSintaxeDoPdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Análise do acréscimo de cada assinatura (M27): o signatário assina o acréscimo que ele mesmo escreveu, então a
 * assinatura íntegra não basta para dizer que o conteúdo é o mesmo.
 */
#[CoversClass(AnalisadorDeAcrescimo::class)]
#[CoversClass(IndiceDoPdf::class)]
#[CoversClass(LeitorDeSintaxeDoPdf::class)]
#[CoversClass(ConferenciaDePdf::class)]
final class AnalisadorDeAcrescimoTest extends TestCase
{
    private const PAGINA = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]';
    private const WIDGET = '<</Type/Annot/Subtype/Widget/FT/Sig/T(Assinatura2)/V 7 0 R/Rect[0 0 0 0]/F 132/P 3 0 R';

    private ConferenciaDePdf $motor;

    protected function setUp(): void
    {
        $this->motor = new ConferenciaDePdf();
    }

    #[TestDox('acréscimo que só traz a assinatura: íntegro, e a primeira assinatura (sem versão anterior) não tem acréscimo')]
    public function testAcrescimoQueSoTrazAAssinatura(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::segunda($primeira);

        $resultado = $this->motor->conferir($segunda, $primeira);

        self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao);
        [$a, $b] = $resultado->assinaturas;
        self::assertSame(ConferenciaDoAcrescimo::NaoSeAplica, $a->acrescimo);
        self::assertNull($a->motivoDoAcrescimo);
        self::assertSame(ConferenciaDoAcrescimo::SoAssinatura, $b->acrescimo);
        self::assertSame('O acréscimo da assinatura 2 só traz o que a assinatura precisa', $b->motivoDoAcrescimo);
    }

    #[TestDox('o signatário redefine a página 3 com um /Contents novo no próprio acréscimo: não conferido, nunca íntegro')]
    public function testPaginaRedefinidaNoAcrescimo(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::segunda($primeira, [
            3 => self::PAGINA . '/Annots[4 0 R 6 0 R]/Contents 9 0 R>>',
            9 => self::fluxo('BT /F1 48 Tf 100 700 Td (PAGO) Tj ET'),
        ]);

        $resultado = $this->motor->conferir($segunda, $primeira);

        self::assertSame(ConferenciaDoPrefixo::Confere, $resultado->prefixo);
        self::assertSame(VereditoDaAssinatura::Integra, $resultado->assinaturas[1]->veredito, 'a assinatura em si confere');
        self::assertSame(ConferenciaDoAcrescimo::MudaConteudo, $resultado->assinaturas[1]->acrescimo);
        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame('O acréscimo da assinatura 2 muda o objeto 3, uma página (/Contents)', $resultado->motivo);
        self::assertSame($resultado->motivo, $resultado->assinaturas[1]->motivoDoAcrescimo);
    }

    /** @return iterable<string, array{array<int, string|null>, string}> */
    public static function mudancas(): iterable
    {
        yield 'tira uma anotação da página' => [
            [3 => self::PAGINA . '/Annots[6 0 R]>>'],
            'muda o objeto 3, uma página (/Annots)',
        ];
        yield 'põe uma anotação nova na página' => [
            [3 => self::PAGINA . '/Annots[4 0 R 6 0 R 8 0 R]>>', 8 => '<</Type/Annot/Subtype/FreeText/Rect[0 0 200 50]/Contents(PAGO)/DA(/Helv 12 Tf 0 g)>>'],
            'muda o objeto 3, uma página (/Annots)',
        ];
        yield 'cria uma anotação solta' => [
            [8 => '<</Subtype/FreeText/Rect[0 0 200 50]/Contents(PAGO)>>'],
            'cria o objeto 8, uma anotação',
        ];
        yield 'campo de assinatura com ação' => [
            [6 => self::WIDGET . '/AA<</K 8 0 R>>>>', 8 => '<</S/JavaScript/JS(this.print())>>'],
            'cria o objeto 6, um campo de assinatura (com ação)',
        ];
        yield 'cria uma ação solta' => [
            [8 => '<</S/JavaScript/JS(this.print())>>'],
            'cria o objeto 8, uma ação',
        ];
        yield 'cria uma página' => [
            [8 => self::PAGINA . '/Contents 9 0 R>>'],
            'cria o objeto 8, uma página',
        ];
        yield 'cria uma árvore de páginas' => [
            [8 => '<</Type/Pages/Kids[3 0 R]/Count 1>>'],
            'cria o objeto 8, a árvore de páginas',
        ];
        yield 'cria um catálogo' => [
            [8 => '<</Type/Catalog/Pages 2 0 R>>'],
            'cria o objeto 8, o catálogo do documento',
        ];
        yield 'troca o /V do campo de assinatura de antes' => [
            [4 => '<</Type/Annot/Subtype/Widget/FT/Sig/T(Assinatura1)/V 7 0 R/Rect[0 0 0 0]/F 132/P 3 0 R>>'],
            'muda o objeto 4, um campo de assinatura (/V)',
        ];
        yield 'muda a árvore de páginas' => [
            [2 => '<</Type/Pages/Kids[3 0 R]/Count 1/Rotate 90>>'],
            'muda o objeto 2, a árvore de páginas (/Rotate)',
        ];
        yield 'põe uma ação ao abrir no catálogo' => [
            [1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 6 0 R]/SigFlags 3>>/OpenAction 8 0 R>>', 8 => '<</S/URI/URI(https://exemplo.com)>>'],
            'muda o objeto 1, o catálogo do documento (/OpenAction)',
        ];
        yield 'muda o formulário além do que a assinatura precisa' => [
            [1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 6 0 R]/SigFlags 3/NeedAppearances true>>>>'],
            'muda o objeto 1, o catálogo do documento (/AcroForm)',
        ];
        yield 'troca a assinatura de antes' => [
            [5 => '<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/ETSI.CAdES.detached/Name(Outro)>>'],
            'muda o objeto 5, uma assinatura (/ByteRange, /Contents, /M, /Name)',
        ];
        yield 'campo de assinatura de antes ganha ação' => [
            [4 => '<</Type/Annot/Subtype/Widget/FT/Sig/T(Assinatura1)/V 5 0 R/Rect[0 0 0 0]/F 132/P 3 0 R/AA<</K 8 0 R>>>>'],
            'muda o objeto 4, um campo de assinatura (/AA)',
        ];
        yield 'página redefinida dentro de um fluxo de objetos do acréscimo' => [
            [8 => self::fluxoDeObjetos([3 => self::PAGINA . '/Annots[4 0 R 6 0 R]/Contents 9 0 R>>'])],
            'muda o objeto 3, uma página (/Contents)',
        ];
        yield 'objeto de antes vira fluxo' => [
            [2 => self::fluxo('abc')],
            'muda o objeto 2, a árvore de páginas',
        ];
    }

    /** @param array<int, string|null> $trocas */
    #[DataProvider('mudancas')]
    #[TestDox('acréscimo que $_dataName: o conteúdo não é conferido e o motivo nomeia o objeto')]
    public function testMudancaNoConteudo(array $trocas, string $motivo): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::segunda($primeira, $trocas);

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame(ConferenciaDoAcrescimo::MudaConteudo, $resultado->assinaturas[1]->acrescimo);
        self::assertSame('O acréscimo da assinatura 2 ' . $motivo, $resultado->motivo);
    }

    #[TestDox('campo de assinatura de antes: mudar a posição com o mesmo /V e preencher o /V do campo vazio são aceitos')]
    public function testCampoDeAssinaturaDeAntesAceito(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $mesmoV = self::segunda($primeira, [4 => '<</Type/Annot/Subtype/Widget/FT/Sig/T(Assinatura1)/V 5 0 R/Rect[10 10 90 40]/F 132/P 3 0 R>>']);

        $vazio = '<</Type/Annot/Subtype/Widget/FT/Sig/T(Vazio)/Rect[0 0 0 0]/F 132/P 3 0 R';
        $catalogo = '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 8 0 R]/SigFlags 3>>>>';
        $pagina = self::PAGINA . '/Annots[4 0 R 8 0 R]>>';
        $comCampoVazio = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoSemAssinatura(OficinaDePdfAssinado::pdfParaAssinar(), [1 => $catalogo, 3 => $pagina, 8 => $vazio . '>>']),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('rsa')),
        );
        $preenchido = self::assinarAcrescimo($comCampoVazio, [9 => null, 1 => $catalogo, 3 => $pagina, 8 => $vazio . '/V 10 0 R>>'], widget: 9);

        foreach (['mesmo /V' => $mesmoV, 'campo vazio preenchido' => $preenchido] as $caso => $pdf) {
            $resultado = $this->motor->conferir($pdf);
            self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao, $caso . ': ' . $resultado->motivo);
            self::assertSame(ConferenciaDoAcrescimo::SoAssinatura, $resultado->assinaturas[1]->acrescimo, $caso);
        }
    }

    /** @return iterable<string, array{int, int, ?string}> */
    public static function entradasEmFluxoDeObjetos(): iterable
    {
        yield 'para o fluxo de objetos do acréscimo que tem o objeto' => [9, 8, null];
        yield 'para o fluxo de objetos do acréscimo que não tem o objeto' => [11, 8, 'o índice do acréscimo aponta o objeto 11 para onde ele não está'];
        yield 'para um fluxo de objetos que não existe' => [9, 99, 'o índice do acréscimo aponta o objeto 9 para onde ele não está'];
    }

    #[DataProvider('entradasEmFluxoDeObjetos')]
    #[TestDox('entrada tipo 2 do fluxo de índice $_dataName')]
    public function testEntradaDeIndiceEmFluxoDeObjetos(int $numero, int $fluxo, ?string $razao): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::segunda($primeira, [
            8 => self::fluxoDeObjetos([9 => '<</Nada(aqui)>>']),
            10 => self::corpoDoFluxoDeIndice($numero, "\x02" . pack('N', $fluxo) . pack('n', 0)),
        ]);

        $resultado = $this->motor->conferir($segunda);

        if ($razao === null) {
            self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao, $resultado->motivo);
            self::assertSame(ConferenciaDoAcrescimo::SoAssinatura, $resultado->assinaturas[1]->acrescimo);

            return;
        }
        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame(ConferenciaDoAcrescimo::NaoConferido, $resultado->assinaturas[1]->acrescimo);
        self::assertSame('O acréscimo da assinatura 2 não foi conferido: ' . $razao, $resultado->motivo);
    }

    #[TestDox('entrada tipo 2 do fluxo de índice para o fluxo de objetos de antes que tem a versão mais nova: aceita')]
    public function testEntradaDeIndiceParaFluxoDeObjetosDeAntes(): void
    {
        $editado = OficinaDePdfAssinado::acrescimoSemAssinatura(OficinaDePdfAssinado::pdfParaAssinar(), [8 => self::fluxoDeObjetos([9 => '<</Nada(antes)>>'])], 10);
        $primeira = OficinaDePdfAssinado::assinar($editado, OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('rsa')));
        $segunda = self::assinarAcrescimo($primeira, [
            1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 10 0 R]/SigFlags 3>>>>',
            3 => self::PAGINA . '/Annots[4 0 R 10 0 R]>>',
            12 => self::corpoDoFluxoDeIndice(9, "\x02" . pack('N', 8) . pack('n', 0)),
        ], widget: 10);

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao, $resultado->motivo);
        self::assertSame(ConferenciaDoAcrescimo::SoAssinatura, $resultado->assinaturas[1]->acrescimo);
    }

    #[TestDox('acréscimo de validação de longo prazo (DSS, certificado, fonte e aparência novos): íntegro')]
    public function testAcrescimoDeValidacaoDeLongoPrazo(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::segunda($primeira, [
            1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 6 0 R]/SigFlags 3/DR<</Font<</Helv 10 0 R>>>>/DA(/Helv 0 Tf 0 g)>>/DSS 8 0 R'
                . '/Extensions<</ESIC<</BaseVersion/1.7/ExtensionLevel 1>>>>>>',
            6 => self::WIDGET . '/AP<</N 11 0 R>>>>',
            8 => '<</Type/DSS/Certs[9 0 R]/VRI<</' . str_repeat('AB', 20) . '<</Cert[9 0 R]>>>>>>',
            9 => self::fluxo("\x30\x82\x01\x00"),
            10 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
            11 => '<</Type/XObject/Subtype/Form/BBox[0 0 10 10]/Resources<</Font<</Helv 10 0 R>>>>/Length 0>>' . "\nstream\n\nendstream",
        ]);

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao, (string) $resultado->assinaturas[1]->motivoDoAcrescimo);
        self::assertSame(ConferenciaDoAcrescimo::SoAssinatura, $resultado->assinaturas[1]->acrescimo);
    }

    #[TestDox('acréscimo que atualiza as informações (/Info) e os metadados XMP: íntegro; /Info com chave fora da norma não')]
    public function testInformacoesEMetadados(): void
    {
        $metadados = static fn (string $xml): string => '<</Type/Metadata/Subtype/XML/Length ' . \strlen($xml) . ">>\nstream\n" . $xml . "\nendstream";
        $editado = OficinaDePdfAssinado::acrescimoSemAssinatura(OficinaDePdfAssinado::pdfParaAssinar(), [
            8 => '<</Producer(Gerador)/ModDate(D:20261101120000Z)>>',
            9 => $metadados('<x:xmpmeta/>'),
        ]);
        $primeira = OficinaDePdfAssinado::assinar($editado, OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('rsa')));
        $catalogo = [1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 10 0 R]/SigFlags 3>>>>', 3 => self::PAGINA . '/Annots[4 0 R 10 0 R]>>'];
        $segunda = self::assinarAcrescimo($primeira, $catalogo + [
            8 => '<</Producer(Assinador)/ModDate(D:20261109120000Z)>>',
            9 => $metadados('<x:xmpmeta><novo/></x:xmpmeta>'),
        ], widget: 10);
        $comChaveNova = self::assinarAcrescimo($primeira, $catalogo + [8 => '<</Producer(Assinador)/Aviso(PAGO)>>'], widget: 10);

        $resultado = $this->motor->conferir($segunda);
        $outro = $this->motor->conferir($comChaveNova);

        self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao, (string) $resultado->assinaturas[1]->motivoDoAcrescimo);
        self::assertSame('O acréscimo da assinatura 2 muda o objeto 8, as informações do documento (/Aviso, /ModDate, /Producer)', $outro->motivo);
    }

    #[TestDox('primeira assinatura posta sobre a versão anterior informada: o acréscimo dela também é conferido')]
    public function testPrimeiraAssinaturaSobreAVersaoAnterior(): void
    {
        $original = OficinaDePdfAssinado::pdfSemAssinatura();
        $catalogo = [1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[6 0 R]/SigFlags 3>>>>'];
        $limpo = self::assinarAcrescimo($original, $catalogo + [3 => self::PAGINA . '/Annots[6 0 R]>>']);
        $mexido = self::assinarAcrescimo($original, $catalogo + [3 => self::PAGINA . '/Annots[6 0 R]/Rotate 180>>']);

        $integro = $this->motor->conferir($limpo, $original);
        $alterado = $this->motor->conferir($mexido, $original);
        $semVersaoAnterior = $this->motor->conferir($mexido);

        self::assertSame(SituacaoDaConferencia::Integro, $integro->situacao, (string) $integro->assinaturas[0]->motivoDoAcrescimo);
        self::assertSame(ConferenciaDoAcrescimo::SoAssinatura, $integro->assinaturas[0]->acrescimo);
        self::assertSame(SituacaoDaConferencia::NaoConferido, $alterado->situacao);
        self::assertSame('O acréscimo da assinatura 1 muda o objeto 3, uma página (/Rotate)', $alterado->motivo);
        self::assertSame(ConferenciaDoAcrescimo::NaoSeAplica, $semVersaoAnterior->assinaturas[0]->acrescimo, 'sem a versão anterior não há com o que comparar');
    }

    #[TestDox('fluxo de conteúdo de antes redefinido no acréscimo')]
    public function testFluxoDeConteudoRedefinido(): void
    {
        $paginaComConteudo = self::PAGINA . '/Annots[4 0 R]/Contents 8 0 R>>';
        $editado = OficinaDePdfAssinado::acrescimoSemAssinatura(OficinaDePdfAssinado::pdfParaAssinar(), [
            3 => $paginaComConteudo,
            8 => self::fluxo('BT (Contrato) Tj ET'),
        ]);
        $primeira = OficinaDePdfAssinado::assinar($editado, OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('rsa')));
        $segunda = self::assinarAcrescimo($primeira, [
            3 => self::PAGINA . '/Annots[4 0 R 9 0 R]/Contents 8 0 R>>',
            8 => self::fluxo('BT (Distrato) Tj ET'),
        ], widget: 9);

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame('O acréscimo da assinatura 2 muda o objeto 8, um fluxo de conteúdo', $resultado->motivo);
    }

    #[TestDox('o índice do acréscimo volta a página para a versão de antes da última edição')]
    public function testIndiceQueVoltaParaVersaoAntiga(): void
    {
        $original = OficinaDePdfAssinado::pdfParaAssinar();
        $giro = self::PAGINA . '/Annots[4 0 R]/Rotate 90>>';
        $primeira = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoSemAssinatura($original, [3 => $giro]),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('rsa')),
        );
        $segunda = self::assinarAcrescimo(
            $primeira,
            [3 => self::PAGINA . '/Annots[4 0 R 6 0 R]/Rotate 90>>'],
            entradasNoIndice: [3 => (int) strpos($original, "\n3 0 obj") + 1],
        );

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame('O acréscimo da assinatura 2 volta o objeto 3 para uma versão anterior', $resultado->motivo);
    }

    /** @return iterable<string, array{array<int, int|string>, string}> */
    public static function indicesQueApagam(): iterable
    {
        yield 'na tabela xref' => [[4 => '0000000000 00001 f '], ''];
        yield 'no fluxo de índice' => [[], self::fluxoDeIndice(4, "\x00\x00\x00\x00\x00\x00\x01")];
    }

    /** @param array<int, int|string> $entradas */
    #[DataProvider('indicesQueApagam')]
    #[TestDox('o índice do acréscimo apaga um objeto de antes ($_dataName)')]
    public function testIndiceQueApagaObjeto(array $entradas, string $antesDoIndice): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::assinarAcrescimo($primeira, entradasNoIndice: $entradas, antesDoIndice: $antesDoIndice);

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame('O acréscimo da assinatura 2 apaga o objeto 4, um campo de assinatura', $resultado->motivo);
    }

    #[TestDox('o índice aponta o objeto 3 para o meio do cabeçalho "13 0 obj" (truque do sufixo): não conferido')]
    public function testIndiceQueApontaParaOMeioDeUmCabecalho(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $acrescimo = OficinaDePdfAssinado::acrescimoParaAssinar($primeira, trocas: [3 => null, 13 => '<</Nada(aqui)>>'], entradasNoIndice: [3 => 0]);
        $sufixo = (int) strrpos($acrescimo, "\n13 0 obj") + 2;
        $acrescimo = str_replace("\n3 1\n0000000000 00000 n ", "\n3 1\n" . \sprintf('%010d', $sufixo) . ' 00000 n ', $acrescimo);
        $segunda = OficinaDePdfAssinado::assinar($acrescimo, OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')));

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame(ConferenciaDoAcrescimo::NaoConferido, $resultado->assinaturas[1]->acrescimo);
        self::assertSame('O acréscimo da assinatura 2 não foi conferido: o índice do acréscimo aponta o objeto 3 para onde ele não está', $resultado->motivo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function acrescimosQueNaoDaParaLer(): iterable
    {
        yield 'cabeçalho com número quebrado' => ["3.0 0 obj\n<<>>\nendobj\n", 'um cabeçalho de objeto não dá para ler'];
        yield 'cabeçalho com sinal' => ["+3 0 obj\n<<>>\nendobj\n", 'um cabeçalho de objeto não dá para ler'];
        yield 'objeto que não fecha' => ["8 0 obj\n<</Type/Annot\n", 'não foi possível ler o objeto 8'];
        yield 'geração diferente de zero' => ["8 1 obj\n<<>>\nendobj\n", 'o objeto 8 tem geração diferente de zero'];
        yield 'fluxo de objetos que não dá para ler' => ["8 0 obj\n<</Type/ObjStm/N 1/First 4/Filter/LZWDecode/Length 3>>\nstream\nabc\nendstream\nendobj\n", 'não foi possível ler o fluxo de objetos 8'];
    }

    #[DataProvider('acrescimosQueNaoDaParaLer')]
    #[TestDox('acréscimo com $_dataName: não conferido, nunca íntegro nem ilegível')]
    public function testAcrescimoQueNaoDaParaLer(string $antesDoIndice, string $razao): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::assinarAcrescimo($primeira, antesDoIndice: $antesDoIndice);

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame('O acréscimo da assinatura 2 não foi conferido: ' . $razao, $resultado->motivo);
    }

    #[TestDox('número abaixo do /Size de antes que não tinha objeto: não conferido')]
    public function testNumeroReservadoSemVersaoAnterior(): void
    {
        $editado = OficinaDePdfAssinado::acrescimoSemAssinatura(OficinaDePdfAssinado::pdfParaAssinar(), [2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>'], 50);
        $primeira = OficinaDePdfAssinado::assinar($editado, OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('rsa')));
        $segunda = self::assinarAcrescimo($primeira);

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame('O acréscimo da assinatura 2 não foi conferido: não foi achada a versão anterior do objeto 6', $resultado->motivo);
    }

    #[TestDox('acréscimo que troca a raiz do documento')]
    public function testRaizTrocada(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::assinarAcrescimo($primeira, antesDoIndice: "trailer\n<</Size 8/Root 2 0 R>>\n");

        $resultado = $this->motor->conferir($segunda);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame('O acréscimo da assinatura 2 troca a raiz do documento (/Root)', $resultado->motivo);
    }

    #[TestDox('três assinaturas: cada acréscimo é conferido contra a assinatura de antes')]
    public function testTresAssinaturas(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = self::segunda($primeira);
        $terceira = self::assinarAcrescimo($segunda, [
            1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 6 0 R 8 0 R]/SigFlags 3>>>>',
            3 => self::PAGINA . '/Annots[4 0 R 6 0 R 8 0 R]>>',
        ], widget: 8);

        $resultado = $this->motor->conferir($terceira);

        self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao, (string) $resultado->assinaturas[2]->motivoDoAcrescimo);
        self::assertSame(
            [ConferenciaDoAcrescimo::NaoSeAplica, ConferenciaDoAcrescimo::SoAssinatura, ConferenciaDoAcrescimo::SoAssinatura],
            array_map(static fn ($x) => $x->acrescimo, $resultado->assinaturas),
        );
    }

    /** @param array<int, string|null> $trocas */
    private static function segunda(string $primeira, array $trocas = []): string
    {
        return self::assinarAcrescimo($primeira, $trocas);
    }

    /**
     * @param array<int, string|null> $trocas
     * @param array<int, int|string>  $entradasNoIndice
     */
    private static function assinarAcrescimo(string $anterior, array $trocas = [], array $entradasNoIndice = [], string $antesDoIndice = '', int $widget = 6): string
    {
        return OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($anterior, '', 'ETSI.CAdES.detached', $trocas, $entradasNoIndice, $antesDoIndice, $widget),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')),
        );
    }

    private static function fluxo(string $dados): string
    {
        return '<</Length ' . \strlen($dados) . ">>\nstream\n" . $dados . "\nendstream";
    }

    /** @param array<int, string> $objetos */
    private static function fluxoDeObjetos(array $objetos): string
    {
        $cabecalho = '';
        $corpo = '';
        foreach ($objetos as $numero => $objeto) {
            $cabecalho .= $numero . ' ' . \strlen($corpo) . ' ';
            $corpo .= $objeto . ' ';
        }
        $dados = $cabecalho . $corpo;

        return '<</Type/ObjStm/N ' . \count($objetos) . '/First ' . \strlen($cabecalho) . '/Length ' . \strlen($dados) . ">>\nstream\n" . $dados . "\nendstream";
    }

    /** Objeto 8 cru: fluxo de índice com uma subseção de um número e /W [1 4 2]. */
    private static function fluxoDeIndice(int $numero, string $entrada): string
    {
        return "8 0 obj\n" . self::corpoDoFluxoDeIndice($numero, $entrada) . "\nendobj\n";
    }

    /** Corpo de um fluxo de índice (/Type /XRef) com uma subseção de um número e /W [1 4 2]. */
    private static function corpoDoFluxoDeIndice(int $numero, string $entrada): string
    {
        return '<</Type/XRef/W[1 4 2]/Index[' . $numero . ' 1]/Size 13/Length ' . \strlen($entrada) . ">>\nstream\n" . $entrada . "\nendstream";
    }
}
