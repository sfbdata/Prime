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
        yield 'DOC'  => ['doc', 'renderDoc', 'LIMITE_BYTES_DOC'];
        yield 'TIFF' => ['tiff', 'renderTiff', 'LIMITE_BYTES_TIFF'];
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

    #[TestDox('DOC-114: P7S/P7M viram "assinatura" — aviso do desenho (bj-visualizar.js L84) por textContent, sem baixar o envelope e sem prometer validação que o sistema não faz')]
    public function testAssinaturaDigitalComAvisoDoDesenho(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("const EXT_ASSINATURA = ['p7s', 'p7m'];", $js);
        self::assertStringContainsString("{ return 'assinatura'; }", self::corpoDaFuncao($js, 'tipoDe'));
        self::assertStringContainsString("if (tipo === 'assinatura') { alvo.appendChild(blocoAssinatura(o.nome, o.urlDownload, o.tamanho)); return; }", self::corpoDaFuncao($js, 'abrir'));

        $bloco = self::corpoDaFuncao($js, 'blocoAssinatura');
        // DOC-141: "(P7S, 12 KB)" como o desenho (bj-visualizar.js:87) quando quem abre passa o tamanho; "(P7S)" sem ele.
        self::assertStringContainsString("'Envelope de assinatura digital (' + ext + (tam ? ', ' + tam : '') + '). Para conferir a assinatura, use o validador oficial do ITI.'", $bloco);
        self::assertStringContainsString("const tam = typeof tamanho === 'number' ? (tamanho > 0 ? formatarTamanho(tamanho) : '') : String(tamanho == null ? '' : tamanho).trim();", $bloco);
        self::assertStringContainsString('bi-patch-check-fill', $bloco);
        self::assertStringNotContainsString('No sistema', $bloco, 'o sistema não abre o envelope nem mostra o certificado');
        self::assertStringNotContainsString('innerHTML', $bloco);
        self::assertStringNotContainsString('baixar(', $bloco, 'nada a ler: o envelope não é baixado para a tela');
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
        yield 'DOC'              => ['renderDoc'];
        yield 'texto do DOC'     => ['textoDoDoc'];
        yield 'controles do DOC' => ['limparTextoDoc'];
        yield 'leitor OLE2'      => ['lerCfb'];
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

    #[TestDox('DOC-108b: a limpeza do HTML gerado tira também poster e background (URL que a CSP era a única a barrar)')]
    public function testLimparArvoreTiraPosterEBackground(): void
    {
        $limpar = self::corpoDaFuncao(self::modulo(), 'limparArvore');

        self::assertStringContainsString("const nome = a.name.toLowerCase();", $limpar, 'compara o nome em minúsculas: POSTER/Background não escapam');
        self::assertMatchesRegularExpression(
            "/if \\(nome\\.startsWith\\('on'\\)[^{]*\\|\\| nome === 'poster' \\|\\| nome === 'background'\\) \\{\\s*n\\.removeAttribute\\(a\\.name\\);/s",
            $limpar,
            'poster e background caem no mesmo removeAttribute de href/srcset/on*'
        );
        foreach (['href', 'srcset', 'action', 'formaction'] as $atributo) {
            self::assertStringContainsString("nome === '{$atributo}'", $limpar, "{$atributo} segue removido");
        }
    }

    #[TestDox('DOC-111: .doc/.dot (application/msword) mostram só o texto, com a faixa do desenho dentro da folha e sem prometer conversão no servidor')]
    public function testDocAntigoMostraSoOTextoComAFaixa(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("const MIMES_DOC    = ['application/msword'];", $js);
        self::assertStringContainsString("const EXT_DOC      = ['doc', 'dot'];", $js);
        self::assertStringContainsString('if (MIMES_DOC.includes(m) || EXT_DOC.includes(ext))', self::corpoDaFuncao($js, 'tipoDe'));
        self::assertStringContainsString('const LIMITE_BYTES_DOC        = 15 * 1024 * 1024;', $js);

        $render = self::corpoDaFuncao($js, 'renderDoc');
        self::assertStringContainsString('baixar(o.url, LIMITE_BYTES_DOC, false, o.sinal)', $render, 'o .doc inteiro ou nada: o índice OLE2 pode estar no fim');
        self::assertStringContainsString('textoDoDoc(arq.bytes, LIMITE_CARACTERES)', $render);
        self::assertStringContainsString("'Formato antigo do Word (.doc): mostrando só o texto.'", $render, 'texto da faixa do desenho (bj-visualizar.js L68)');
        self::assertStringContainsString('background:#fff7e6;color:#7a4b00;border-radius:4px;padding:8px 12px;margin-bottom:16px;font-size:12px', $render, 'as cores e medidas da faixa do desenho');
        self::assertStringContainsString('fl.folha.appendChild(faixa);', $render, 'a faixa fica DENTRO da folha, como no desenho');
        self::assertStringContainsString("el('div', 'vd-folha-pre', r.texto)", $render, 'o texto entra por textContent');
        self::assertStringNotContainsString('servidor', $render, 'o sistema não converte o .doc: a 2ª frase do desenho ficaria falsa');
        self::assertStringNotContainsString('innerHTML', $render);
    }

    #[TestDox('DOC-111: o texto vem da tabela de peças do Word 97 (OLE2 → WordDocument → Clx), com guarda de laço, senha e Word 6/95 recusados')]
    public function testDocLidoPelaTabelaDePecas(): void
    {
        $js = self::modulo();

        $cfb = self::corpoDaFuncao($js, 'lerCfb');
        self::assertStringContainsString('lerU32(bytes, 0) !== 0xe011cfd0 || lerU32(bytes, 4) !== 0xe11ab1a1', $cfb, 'assinatura D0 CF 11 E0 A1 B1 1A E1');
        self::assertStringContainsString('if (s >= tabela.length || lista.length >= teto) { throw falha(invalido); }', $cfb, 'cadeia de setores com laço vira "corrompido", não trava a aba');
        self::assertStringContainsString('if (k >= totalSetores) { throw falha(invalido); }', $cfb, 'a cadeia do DIFAT também tem guarda');
        self::assertStringContainsString('if (p + n > origem.length) { throw falha(invalido); }', $cfb);
        self::assertStringContainsString('if (e.tamanhoAlto !== 0 || e.tamanho > bytes.length) { throw falha(invalido); }', $cfb, 'stream maior que o arquivo não aloca');
        self::assertStringContainsString('if (e.tamanho < corte) {', $cfb, 'stream pequeno sai do mini stream');

        $doc = self::corpoDaFuncao($js, 'textoDoDoc');
        self::assertStringContainsString("cfb.stream('WordDocument')", $doc);
        self::assertStringContainsString('lerU16(wd, 0) !== 0xa5ec', $doc, 'wIdent do FIB');
        self::assertStringContainsString("if (flags & 0x0100) { throw falha('O documento está protegido por senha. Baixe para abrir.'); }", $doc);
        self::assertStringContainsString('if (lerU16(wd, 2) < 0xc1) {', $doc, 'Word 6/95 tem outro FIB: recusa em vez de ler errado');
        self::assertStringContainsString("cfb.stream(flags & 0x0200 ? '1Table' : '0Table')", $doc, 'fWhichTblStm escolhe o stream da tabela');
        self::assertStringContainsString('const fcClx = lerU32(wd, rgFc + 33 * 8);', $doc);
        self::assertStringContainsString('if (cpIni >= ccpText) { break; }', $doc, 'só o corpo: nota, cabeçalho e caixa de texto ficam de fora');
        self::assertStringContainsString('if (total + qtd > limite) { qtd = limite - total; cortado = true; }', $doc);
        self::assertStringContainsString('(fcBruto & 0x40000000) !== 0', $doc, 'fCompressed: CP1252 com 1 byte; senão UTF-16LE');
        self::assertStringContainsString("new TextDecoder('windows-1252')", $doc);
        self::assertStringContainsString("new TextDecoder('utf-16le')", $doc);
        self::assertStringContainsString('if (!Number.isInteger(ini) || fimPeca > wd.length) { throw falha(invalido); }', $doc);
        self::assertStringNotContainsString('DecompressionStream', $doc, 'o .doc não comprime o texto: nada a descompactar');

        $controles = self::corpoDaFuncao($js, 'limparTextoDoc');
        self::assertStringContainsString('if (campos.indexOf(true) !== -1) { continue; }', $controles, 'do campo fica só o resultado, nunca o código');
        self::assertStringContainsString('if (c < 0x20) { continue; }', $controles);
    }

    #[TestDox('DOC-112: UTIF e pako auto-hospedados em vendor/utif, com a versão e o sha256 de cada arquivo registrados no VERSION')]
    public function testUtifAutoHospedadoComVersao(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("pako:    { src: BASE_VENDOR + 'utif/pako_inflate.min.js',       global: 'pako' },", $js);
        self::assertStringContainsString("utif:    { src: BASE_VENDOR + 'utif/UTIF.js',                   global: 'UTIF' },", $js);

        $versao = (string) file_get_contents(self::raiz() . '/public/js/vendor/utif/VERSION');
        self::assertStringContainsString('utif 3.1.0', $versao);
        self::assertStringContainsString('pako 1.0.11', $versao);
        self::assertStringContainsString('sha256 (UTIF.js): ' . hash_file('sha256', self::raiz() . '/public/js/vendor/utif/UTIF.js'), $versao, 'o UTIF.js é o do pacote, sem alteração');
        self::assertStringContainsString('sha256 (pako_inflate.min.js): ' . hash_file('sha256', self::raiz() . '/public/js/vendor/utif/pako_inflate.min.js'), $versao);
        self::assertStringContainsString('MIT', (string) file_get_contents(self::raiz() . '/public/js/vendor/utif/LICENSE'));
        self::assertStringContainsString('MIT', (string) file_get_contents(self::raiz() . '/public/js/vendor/utif/LICENSE-pako'));
    }

    #[TestDox('DOC-112: TIFF vem antes de image/* no tipoDe (o <img> do Chrome e do Firefox não abre TIFF); um PNG chamado .tif segue imagem')]
    public function testTiffAntesDaImagem(): void
    {
        $js     = self::modulo();
        $tipoDe = self::corpoDaFuncao($js, 'tipoDe');

        self::assertStringContainsString("const MIMES_TIFF = ['image/tiff', 'image/tiff-fx', 'image/x-tiff'];", $js);
        self::assertStringContainsString("const EXT_TIFF   = ['tif', 'tiff'];", $js);
        self::assertStringContainsString("if (MIMES_TIFF.includes(m) || (EXT_TIFF.includes(ext) && !m.startsWith('image/'))) { return 'tiff'; }", $tipoDe);
        self::assertLessThan((int) strpos($tipoDe, "return 'imagem';"), (int) strpos($tipoDe, "return 'tiff';"));
    }

    #[TestDox('DOC-112: cada página do TIFF vira um canvas (RGBA por putImageData), com tetos de páginas, pixels e bytes; nenhum HTML')]
    public function testTiffEmCanvasComTetos(): void
    {
        $js     = self::modulo();
        $render = self::corpoDaFuncao($js, 'renderTiff');
        $pagina = self::corpoDaFuncao($js, 'paginaTiffEmCanvas');

        self::assertStringContainsString('carregarUtif()', $render);
        self::assertStringContainsString('baixar(o.url, LIMITE_BYTES_TIFF, false, o.sinal)', $render, 'o TIFF inteiro ou nada: o índice das páginas pode estar no fim');
        self::assertMatchesRegularExpression('/conferirTiff\(arq\.bytes, invalido\);\s*let ifds;\s*try \{ ifds = UTIF\.decode\(buf\); \}/', $render, 'o índice é conferido ANTES do UTIF.decode');
        self::assertMatchesRegularExpression('/conferirPaginaTiff\(d, w, h, arq\.bytes\.length, invalido\);.*?orcamentoInflateTiff = bytesPagina \+ 1024 \* 1024;.*?UTIF\.decodeImage\(buf, d\);/s', $render, 'estrutura e orçamento do inflate antes do decodeImage');
        self::assertStringContainsString('i < total && i < LIMITE_PAGINAS_TIFF', $render);
        self::assertStringContainsString('w > LIMITE_LADO_TIFF || h > LIMITE_LADO_TIFF || w * h > LIMITE_PIXELS_PAGINA_TIFF', $render);
        self::assertStringContainsString('if (bytesPagina > LIMITE_BYTES_PAGINA_TIFF) { break; }', $render);
        self::assertStringContainsString("'Aparecem as ' + desenhadas + ' primeiras de ' + total + ' páginas. Baixe para ver tudo.'", $render);
        self::assertStringContainsString("lista.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:14px';", $render, 'as páginas uma embaixo da outra, com 14 px (bj-visualizar.js L66)');
        self::assertStringContainsString('await new Promise(function (ok) { setTimeout(ok, 0); });', $render, 'cede a thread entre uma página e outra');
        foreach (['innerHTML', 'srcdoc', 'montarSrcdoc', 'neutralizar'] as $proibido) {
            self::assertStringNotContainsString($proibido, $render . $pagina);
        }

        self::assertStringContainsString("document.createElement('canvas')", $pagina);
        self::assertStringContainsString('putImageData(new ImageData(new Uint8ClampedArray(rgba.buffer, rgba.byteOffset, w * h * 4), w, h), 0, 0)', $pagina);
        self::assertStringContainsString('const escala = Math.min(1, LARGURA_MAX_TIFF / w);', $pagina, 'página grande é reduzida para a tela');
        self::assertStringContainsString("c.style.cssText = 'display:block;max-width:100%;height:auto;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.35)';", $pagina, 'página branca com a sombra do desenho');
        self::assertStringContainsString("c.title = 'Página ' + numero;", $pagina);
    }

    #[TestDox('DOC-112: o índice do TIFF é conferido antes do UTIF (IFD em laço, campo maior que o arquivo, BigTIFF), e a página também (tiras e blocos)')]
    public function testIndiceDoTiffConferido(): void
    {
        $js = self::modulo();

        $indice = self::corpoDaFuncao($js, 'conferirTiff');
        self::assertStringContainsString('if (vistos.has(off) || vistos.size >= LIMITE_IFDS_TIFF || profundidade > 8) { throw falha(invalido); }', $indice, 'IFD que aponta para si mesmo laçaria o UTIF.decode');
        self::assertStringContainsString('if (valor + tamanho > bytes.length) { throw falha(invalido); }', $indice, 'campo que declara 4 bilhões de valores travaria a aba');
        self::assertStringContainsString('if (u16(2) !== 42)', $indice);
        self::assertStringContainsString('(tag === 330 || tag === 34665)', $indice, 'sub-IFDs e EXIF, que o UTIF também percorre');
        self::assertStringContainsString('do { proximo = ifd(proximo, 0); } while (proximo !== 0);', $indice);

        $pagina = self::corpoDaFuncao($js, 'conferirPaginaTiff');
        self::assertStringContainsString('if (!bcnt || bcnt.length !== soff.length) { throw falha(invalido); }', $pagina, 'contagem trocada laça o decodificador de fax');
        self::assertStringContainsString('soff[i] + bcnt[i] > tamanhoArquivo', $pagina);
        self::assertStringContainsString('if (soff.length < Math.ceil(w / tw) * Math.ceil(h / th)) { throw falha(invalido); }', $pagina);
        self::assertStringContainsString('if (!(rps >= 1) || soff.length > Math.ceil(h / rps)) { throw falha(invalido); }', $pagina);

        self::assertStringContainsString('if (!(bits > 0) || bits > 64)', self::corpoDaFuncao($js, 'bytesDaPaginaTiff'));
    }

    #[TestDox('DOC-112: o inflate que o UTIF usa tem teto (tira Deflate não infla para GB) e o pako original volta ao global depois de carregar o UTIF')]
    public function testInflateDoTiffComTeto(): void
    {
        $js = self::modulo();

        $envelope = self::corpoDaFuncao($js, 'pakoComTeto');
        self::assertStringContainsString('if (total > orcamentoInflateTiff) { throw falha(MSG_TIFF_GRANDE); }', $envelope);
        self::assertStringContainsString('inf.push(dados, true);', $envelope);

        $carregar = self::corpoDaFuncao($js, 'carregarUtif');
        self::assertMatchesRegularExpression("/window\.pako = pakoComTeto\(pako\);\s*return carregarBiblioteca\('utif'\)\.finally\(function \(\) \{ window\.pako = pako; \}\);/", $carregar, 'o UTIF guarda o self.pako do momento em que carrega');
        self::assertMatchesRegularExpression("/carregarBiblioteca\('pako'\)\.then\(/", $carregar, 'o pako carrega ANTES do UTIF');
    }

    #[TestDox('DOC-112: na impressão, o canvas (que o clone deixa em branco) vira <img> com o PNG da página, uma por folha')]
    public function testImpressaoDoTiffTrocaCanvasPorImagem(): void
    {
        $copiar = self::corpoDaFuncao(self::modulo(), 'paraImpressao');

        self::assertStringContainsString("const telas = conteudo.querySelectorAll('canvas');", $copiar);
        self::assertStringContainsString("img.src = telas[i].toDataURL('image/png');", $copiar, 'data: — a CSP da impressão só deixa imagem data:');
        self::assertStringContainsString("img.style.cssText = 'width:100%;display:block;margin:0 0 6mm;page-break-after:always';", $copiar, 'como o capturar do desenho (bj-visualizar.js L102)');
        self::assertStringContainsString('cv.replaceWith(img);', $copiar);
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
        yield 'UTIF (js)'         => ['public/js/vendor/utif/UTIF.js'];
        yield 'UTIF (licença)'    => ['public/js/vendor/utif/LICENSE'];
        yield 'UTIF (versão)'     => ['public/js/vendor/utif/VERSION'];
        yield 'pako (js)'         => ['public/js/vendor/utif/pako_inflate.min.js'];
        yield 'pako (licença)'    => ['public/js/vendor/utif/LICENSE-pako'];
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
