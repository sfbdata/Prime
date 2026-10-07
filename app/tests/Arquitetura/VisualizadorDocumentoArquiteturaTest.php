<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trava o desenho do visualizador de documentos (public/js/visualizador-documento.js):
 *
 *  - um módulo só — as telas não voltam a ter a própria cópia do preview;
 *  - o HTML gerado por mammoth/SheetJS vai para iframe `sandbox=""` com CSP, nunca para o DOM
 *    da página (o arquivo é de cliente: um DOCX/XLSX é entrada hostil);
 *  - as bibliotecas são auto-hospedadas, com a licença ao lado;
 *  - ODT/PPTX/ODP/RTF/EML/ZIP: roteados pelo tipoDe, cada um com teto de bytes; o texto extraído
 *    vira nó com textContent; o HTML de e-mail só entra pelo criarIframe(montarSrcdoc(...));
 *    o ZIP é lido com DecompressionStream nativo, com teto do que se descompacta, e o índice
 *    ZIP64 (EOCD64 locator/record + extra 0x0001) é entendido sem afrouxar os tetos.
 *
 * Regex não executa JS: isto prova que as peças estão lá, não que o navegador as usa. O
 * comportamento na tela é smoke do dono.
 */
#[CoversNothing]
final class VisualizadorDocumentoArquiteturaTest extends TestCase
{
    private const MODULO = 'public/js/visualizador-documento.js';

    private static function raiz(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function modulo(): string
    {
        $conteudo = file_get_contents(self::raiz() . '/' . self::MODULO);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    #[TestDox('o HTML gerado vai para iframe sandbox="" (sem allow-scripts / allow-same-origin) via srcdoc')]
    public function testHtmlGeradoVaiParaIframeSandbox(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("setAttribute('sandbox', '')", $js);
        self::assertStringContainsString('.srcdoc = ', $js);
        self::assertStringNotContainsString('allow-scripts', $js);
        self::assertStringNotContainsString('allow-same-origin', $js);
    }

    #[TestDox('o srcdoc abre com a CSP que só deixa imagem data: e estilo inline')]
    public function testSrcdocTemCsp(): void
    {
        $js = self::modulo();

        self::assertStringContainsString(
            "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
            $js
        );
        self::assertStringContainsString('<meta http-equiv="Content-Security-Policy"', $js);
    }

    #[TestDox('o módulo nunca ATRIBUI innerHTML/outerHTML nem usa insertAdjacentHTML/document.write')]
    public function testNuncaInjetaHtmlNoDom(): void
    {
        $js = self::modulo();

        self::assertDoesNotMatchRegularExpression('/\.(inner|outer)HTML\s*\+?=(?!=)/', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringNotContainsString('document.write', $js);
    }

    #[TestDox('as bibliotecas vêm de /js/vendor (auto-hospedadas), nunca de CDN')]
    public function testBibliotecasSaoAutoHospedadas(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("'mammoth/mammoth.browser.min.js'", $js);
        self::assertStringContainsString("'xlsx/xlsx.full.min.js'", $js);
        self::assertDoesNotMatchRegularExpression('#https?://#', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js) ?? '');
    }

    #[TestDox('DOCX e planilha desenham pelo criarIframe (sandbox), nunca direto na página')]
    public function testDocxEPlanilhaPassamPeloIframe(): void
    {
        $js = self::modulo();

        foreach (['renderDocx', 'renderPlanilha'] as $funcao) {
            self::assertMatchesRegularExpression(
                '/async function ' . $funcao . '\(.*?criarIframe\(montarSrcdoc\(/s',
                self::corpoDaFuncao($js, $funcao),
                $funcao . ' tem de passar o HTML gerado pelo criarIframe'
            );
        }
    }

    #[TestDox('fechar o modal aborta o download: hide.bs.modal chama limpar(), que dispara o AbortController')]
    public function testFecharModalAbortaODownload(): void
    {
        $js = self::modulo();

        self::assertStringContainsString('new AbortController()', $js);
        self::assertStringContainsString('signal: sinal', $js, 'o fetch recebe o sinal do AbortController');
        self::assertMatchesRegularExpression(
            "/addEventListener\('hide\.bs\.modal',\s*function\s*\(\)\s*\{\s*limpar\(conteudo\)/",
            $js
        );
        self::assertMatchesRegularExpression('/\.__vdAbort\.abort\(\)/', self::corpoDaFuncao($js, 'limpar'));
        self::assertStringContainsString("erro.name === 'AbortError'", $js, 'abort não vira mensagem de erro');
    }

    #[TestDox('o teto de bytes da planilha é 5 MB (XLSX.read descompacta tudo na thread principal)')]
    public function testTetoDaPlanilhaEhCincoMega(): void
    {
        $js = self::modulo();

        self::assertStringContainsString('LIMITE_BYTES_PLANILHA   = 5 * 1024 * 1024', $js);
        self::assertStringContainsString('baixar(o.url, LIMITE_BYTES_PLANILHA,', self::corpoDaFuncao($js, 'renderPlanilha'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function formatosSoTexto(): iterable
    {
        yield 'ODT'  => ['odt', 'renderOdt', 'LIMITE_BYTES_ODT'];
        yield 'PPTX' => ['pptx', 'renderPptx', 'LIMITE_BYTES_PPTX'];
        yield 'ODP'  => ['odp', 'renderOdp', 'LIMITE_BYTES_ODP'];
        yield 'RTF'  => ['rtf', 'renderRtf', 'LIMITE_BYTES_RTF'];
        yield 'EML'  => ['eml', 'renderEml', 'LIMITE_BYTES_EML'];
        yield 'ZIP'  => ['zip', 'renderZip', 'LIMITE_BYTES_ZIP'];
    }

    #[DataProvider('formatosSoTexto')]
    #[TestDox('cada formato novo é roteado pelo tipoDe para o seu render, que baixa com teto próprio de bytes')]
    public function testFormatoNovoRoteadoComTeto(string $tipo, string $render, string $teto): void
    {
        $js = self::modulo();

        self::assertStringContainsString("{ return '{$tipo}'; }", self::corpoDaFuncao($js, 'tipoDe'));
        self::assertMatchesRegularExpression('/^\s+' . $tipo . ': ' . $render . ',$/m', self::corpoDaFuncao($js, 'abrir'));
        self::assertMatchesRegularExpression('/const ' . $teto . '\s+= \d+ \* 1024 \* 1024;/', $js);
        self::assertStringContainsString('baixar(o.url, ' . $teto . ',', self::corpoDaFuncao($js, $render));
    }

    #[TestDox('text/rtf e ODT/PPTX com MIME application/zip não caem no texto nem no ZIP: a ordem do tipoDe decide')]
    public function testOrdemDoTipoDe(): void
    {
        $corpo = self::corpoDaFuncao(self::modulo(), 'tipoDe');

        $pos = static fn (string $t): int => (int) strpos($corpo, "return '{$t}';");
        self::assertLessThan($pos('texto'), $pos('rtf'), 'text/rtf começa com text/: o RTF tem de vir antes');
        self::assertLessThan($pos('zip'), $pos('odt'));
        self::assertLessThan($pos('zip'), $pos('pptx'));
        self::assertLessThan($pos('zip'), $pos('odp'), 'ODP com MIME application/zip não pode virar lista de ZIP');
        self::assertLessThan($pos('zip'), $pos('docx'));
        self::assertLessThan($pos('zip'), $pos('planilha'));
    }

    #[TestDox('todo montarSrcdoc() vai direto para criarIframe() — e o srcdoc só é atribuído lá')]
    public function testHtmlSoEntraPeloCriarIframe(): void
    {
        $js = self::modulo();

        $chamadas = substr_count($js, 'montarSrcdoc(') - substr_count($js, 'function montarSrcdoc(');
        self::assertGreaterThan(0, $chamadas);
        self::assertSame($chamadas, substr_count($js, 'criarIframe(montarSrcdoc('));
        self::assertSame(1, substr_count($js, '.srcdoc = '), 'srcdoc só no criarIframe');
        self::assertStringContainsString('.srcdoc = ', self::corpoDaFuncao($js, 'criarIframe'));
    }

    /** @return iterable<string, array{string}> */
    public static function rendersSemHtml(): iterable
    {
        yield 'ODT'  => ['renderOdt'];
        yield 'PPTX' => ['renderPptx'];
        yield 'ODP'  => ['renderOdp'];
        yield 'RTF'  => ['renderRtf'];
        yield 'ZIP'  => ['renderZip'];
        yield 'cartões de slide' => ['blocoSlides'];
        yield 'slides do ODP'    => ['slidesOdp'];
    }

    #[DataProvider('rendersSemHtml')]
    #[TestDox('ODT, PPTX, RTF e ZIP montam só nós com textContent: não geram HTML nem srcdoc')]
    public function testRenderSoTextoNaoGeraHtml(string $render): void
    {
        $corpo = self::corpoDaFuncao(self::modulo(), $render);

        self::assertStringNotContainsString('montarSrcdoc', $corpo);
        self::assertStringNotContainsString('neutralizar', $corpo);
        self::assertStringNotContainsString('srcdoc', $corpo);
        self::assertStringNotContainsString('HTML', $corpo);
    }

    #[TestDox('o HTML do e-mail só entra neutralizado e pelo iframe sandbox; o text/plain vai por textContent')]
    public function testHtmlDoEmailSoNoSandbox(): void
    {
        $corpo = self::corpoDaFuncao(self::modulo(), 'renderEml');

        self::assertStringContainsString('neutralizar(m.html', $corpo);
        self::assertStringContainsString('criarIframe(montarSrcdoc(corpo, CSS_EMAIL)', $corpo);
        self::assertStringContainsString('pre.textContent = ', $corpo);
    }

    #[TestDox('ODP: MIME e extensão próprios, content.xml com teto, senha recusada, notas fora, mesmo cartão do PPTX')]
    public function testOdpLidoComoOdtEPptx(): void
    {
        $js = self::modulo();
        $tipoDe = self::corpoDaFuncao($js, 'tipoDe');
        $render = self::corpoDaFuncao($js, 'renderOdp');

        self::assertStringContainsString("const MIMES_ODP  = ['application/vnd.oasis.opendocument.presentation'];", $js);
        self::assertStringContainsString("const EXT_ODP  = ['odp'];", $js);
        self::assertStringContainsString('if (MIMES_ODP.includes(m) || EXT_ODP.includes(ext))', $tipoDe);
        self::assertStringContainsString('extrairEntrada(arq.bytes, conteudo, LIMITE_BYTES_XML_ZIP)', $render);
        self::assertStringContainsString('await recusarOdfComSenha(arq.bytes, entradas)', $render);
        self::assertStringContainsString('slidesOdp(doc, LIMITE_SLIDES, LIMITE_CARACTERES)', $render);
        self::assertStringContainsString('trocar(alvo, blocoSlides(r.slides, r.total, r.cortado))', $render);
        self::assertStringContainsString('trocar(alvo, blocoSlides(slides, ordem.length))', self::corpoDaFuncao($js, 'renderPptx'));
        self::assertStringContainsString('await recusarOdfComSenha(arq.bytes, entradas)', self::corpoDaFuncao($js, 'renderOdt'));

        $slides = self::corpoDaFuncao($js, 'slidesOdp');
        self::assertStringContainsString("getElementsByTagNameNS(NS_ODF_DRAW, 'page')", $slides, 'um slide por draw:page');
        self::assertStringContainsString('i < limiteSlides', $slides);
        self::assertStringContainsString('const resta = limiteCaracteres - caracteres;', $slides);
        self::assertStringContainsString('dentroDeNotasOdp(', $slides, 'notas do apresentador ficam de fora, como no PPTX');
        self::assertStringContainsString("p.localName === 'notes'", self::corpoDaFuncao($js, 'dentroDeNotasOdp'));
        self::assertStringContainsString("getAttributeNS(NS_ODF_PRES, 'visibility') !== 'hidden'", self::corpoDaFuncao($js, 'estilosOcultosOdp'));
        self::assertStringContainsString('/encryption-data/.test(m)', self::corpoDaFuncao($js, 'recusarOdfComSenha'));
    }

    #[TestDox('ODP: o teto de caracteres corta DENTRO do slide (linha cortada com reticências) e a tela avisa')]
    public function testOdpCortaTextoDentroDoSlide(): void
    {
        $js     = self::modulo();
        $slides = self::corpoDaFuncao($js, 'slidesOdp');

        // O teto é conferido a cada linha, não só entre um slide e outro: um content.xml de até
        // 20 MB cabe num slide só, e a checagem entre slides deixaria esse slide passar inteiro.
        self::assertStringContainsString('const resta = limiteCaracteres - caracteres;', $slides);
        self::assertStringContainsString('if (texto.length > resta) {', $slides);
        self::assertStringContainsString("linhas.push(cortarTexto(texto, resta) + '\\u2026')", $slides);
        self::assertStringContainsString('i < limiteSlides && !cortado', $slides, 'depois do corte não lê mais slide nenhum');
        self::assertStringContainsString('if (cortado && !linhas.length) { break; }', $slides, 'slide sem nada que coube não vira cartão "sem texto"');
        self::assertStringContainsString('return { slides: slides, total: paginas.length, cortado: cortado };', $slides);

        self::assertStringContainsString('corte.slice(0, -1)', self::corpoDaFuncao($js, 'cortarTexto'), 'não deixa meio par substituto no fim');

        $bloco = self::corpoDaFuncao($js, 'blocoSlides');
        self::assertStringContainsString('function blocoSlides(slides, total, textoCortado)', $bloco);
        self::assertStringContainsString("(textoCortado ? ' O texto é longo: só o começo aparece aqui.' : '')", $bloco);
        self::assertStringContainsString("(cortou || textoCortado ? ' Baixe para ver tudo.' : '')", $bloco);
    }

    #[TestDox('ZIP64: lerZip lê o EOCD64 (locator 0x07064b50 → registro 0x06064b50) e o extra 0x0001, sem recusar o formato')]
    public function testZip64LidoPeloEocd64(): void
    {
        $js = self::modulo();
        $lerZip = self::corpoDaFuncao($js, 'lerZip');
        $eocd64 = self::corpoDaFuncao($js, 'lerEocd64');
        $extra = self::corpoDaFuncao($js, 'aplicarExtraZip64');

        self::assertStringNotContainsString('formato ZIP64, que a pré-visualização não lê', $js, 'ZIP64 não é mais recusado');
        self::assertStringContainsString('const z64 = lerEocd64(bytes, eocd, invalido);', $lerZip);
        self::assertStringContainsString('const loc = eocd - 20;', $eocd64, 'locator: 20 bytes antes do EOCD');
        self::assertStringContainsString('lerU32(bytes, loc) !== 0x07064b50', $eocd64);
        self::assertStringContainsString('lerU32(bytes, reg) !== 0x06064b50', $eocd64);
        self::assertStringContainsString('throw falha(invalido)', $eocd64, 'locator com registro ilegível é corrompido, não volta ao EOCD de 16 bits');
        foreach (['total:    lerU64(bytes, reg + 32)', 'tamCd:    lerU64(bytes, reg + 40)', 'inicioCd: lerU64(bytes, reg + 48)'] as $campo) {
            self::assertStringContainsString($campo, $eocd64);
        }
        self::assertStringContainsString('if (id === 0x0001)', $extra);
        self::assertStringContainsString("['tamanho', 'comprimido', 'offsetLocal']", $extra, 'ordem do extra ZIP64 é a da especificação');
        self::assertStringContainsString('if (entrada[campo] !== 0xffffffff) { return; }', $extra, 'só os campos saturados vêm no extra');
        self::assertStringContainsString('aplicarExtraZip64(bytes.subarray(p + 46 + lNome, p + 46 + lNome + lExtra), entrada)', $lerZip);
        self::assertStringContainsString('if (alto > 0x1fffff) { return Infinity; }', self::corpoDaFuncao($js, 'lerU64'), 'acima de 2^53 não vira número errado');
        self::assertStringContainsString('if (total > tamCd / 46) { throw falha(invalido); }', $lerZip, 'total de entradas mentiroso não laça');
        self::assertStringContainsString('if (!(inicioCd + tamCd <= fimCd)) { throw falha(invalido); }', $lerZip);
    }

    #[TestDox('ZIP64 não afrouxa os tetos: 30 MB baixados, 2000 entradas exibidas, 20 MB descompactados por XML')]
    public function testZip64MantemOsTetos(): void
    {
        $js = self::modulo();

        self::assertStringContainsString('const LIMITE_BYTES_ZIP        = 30 * 1024 * 1024;', $js);
        self::assertStringContainsString('const LIMITE_ENTRADAS_ZIP     = 2000;', $js);
        self::assertStringContainsString('const LIMITE_BYTES_XML_ZIP    = 20 * 1024 * 1024;', $js);
        self::assertStringContainsString('entradas.slice(0, LIMITE_ENTRADAS_ZIP)', self::corpoDaFuncao($js, 'renderZip'));
    }

    #[TestDox('o ZIP é lido sem biblioteca: DecompressionStream nativo, com teto de bytes descompactados (zip bomb)')]
    public function testZipNativoComTetoDeDescompactacao(): void
    {
        $js = self::modulo();
        $inflar = self::corpoDaFuncao($js, 'inflar');

        self::assertStringContainsString("new DecompressionStream('deflate-raw')", $inflar);
        self::assertStringContainsString('if (total > teto)', $inflar);
        self::assertStringContainsString('leitor.cancel()', $inflar);
        self::assertStringContainsString('if (dados.length > teto)', self::corpoDaFuncao($js, 'extrairEntrada'), 'entrada sem compressão também respeita o teto');
        self::assertStringNotContainsStringIgnoringCase('jszip', $js);
        self::assertStringContainsString('extrairEntrada(arq.bytes, conteudo, LIMITE_BYTES_XML_ZIP)', self::corpoDaFuncao($js, 'renderOdt'));
        self::assertStringNotContainsString('extrairEntrada', self::corpoDaFuncao($js, 'renderZip'), 'o ZIP só é listado, nunca extraído');
    }

    #[TestDox('XML de dentro do ZIP é lido por DOMParser em documento separado (application/xml)')]
    public function testXmlPorDomParserSeparado(): void
    {
        $lerXml = self::corpoDaFuncao(self::modulo(), 'lerXml');

        self::assertStringContainsString("new DOMParser().parseFromString(", $lerXml);
        self::assertStringContainsString("'application/xml'", $lerXml);
        self::assertStringContainsString("getElementsByTagName('parsererror')", $lerXml);
    }

    #[TestDox('falha com mensagem própria (senha, corrompido, grande demais) chega à tela')]
    public function testFalhaComMensagemChegaATela(): void
    {
        $abrir = self::corpoDaFuncao(self::modulo(), 'abrir');

        self::assertStringContainsString('erro.vdMensagem', $abrir);
    }

    /** Do `function <nome>(` até a próxima declaração de função no mesmo nível (4 espaços). */
    private static function corpoDaFuncao(string $js, string $nome): string
    {
        $ok = preg_match('/^    (?:async )?function ' . $nome . '\(.*?(?=^    (?:async )?function |\z)/ms', $js, $m);
        self::assertSame(1, $ok, "função {$nome} não encontrada");

        return $m[0];
    }

    /** @return iterable<string, array{string}> */
    public static function arquivosVendor(): iterable
    {
        yield 'mammoth (js)'      => ['public/js/vendor/mammoth/mammoth.browser.min.js'];
        yield 'mammoth (licença)' => ['public/js/vendor/mammoth/LICENSE'];
        yield 'mammoth (versão)'  => ['public/js/vendor/mammoth/VERSION'];
        yield 'SheetJS (js)'      => ['public/js/vendor/xlsx/xlsx.full.min.js'];
        yield 'SheetJS (licença)' => ['public/js/vendor/xlsx/LICENSE'];
    }

    #[DataProvider('arquivosVendor')]
    #[TestDox('cada arquivo auto-hospedado (biblioteca e licença) existe e não está vazio')]
    public function testArquivoVendorExiste(string $arquivo): void
    {
        $caminho = self::raiz() . '/' . $arquivo;

        self::assertFileExists($caminho);
        self::assertGreaterThan(0, filesize($caminho));
    }

    /** @return iterable<string, array{string}> */
    public static function telasComPreview(): iterable
    {
        yield 'pasta'   => ['templates/pasta/show.html.twig'];
        yield 'cliente' => ['templates/cliente/show.html.twig'];
        yield 'tarefa'  => ['templates/tarefa/show.html.twig'];
    }

    #[DataProvider('telasComPreview')]
    #[TestDox('as telas de pasta, cliente e tarefa não têm mais cópia própria do preview — delegam ao módulo')]
    public function testTelaNaoTemCopiaDoPreview(string $template): void
    {
        $twig = file_get_contents(self::raiz() . '/' . $template);
        self::assertIsString($twig);

        self::assertStringContainsString('VisualizadorDocumento.ligarModal(', $twig);
        self::assertStringNotContainsString('previewConteudo.innerHTML', $twig);
        self::assertStringNotContainsString("addEventListener('show.bs.modal'", self::trechoDoPreview($twig));
    }

    /** As 40 linhas em volta da chamada ao módulo — onde a cópia antiga morava. */
    private static function trechoDoPreview(string $twig): string
    {
        $pos = strpos($twig, 'VisualizadorDocumento.ligarModal(');
        self::assertNotFalse($pos);

        return substr($twig, max(0, $pos - 1500), 3000);
    }
}
