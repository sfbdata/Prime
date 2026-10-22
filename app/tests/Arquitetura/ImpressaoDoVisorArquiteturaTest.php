<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * DOC-108: o visor da aba Documentos imprime o conteúdo JÁ desenhado (DOCX, planilha, texto,
 * ODT, PPTX, ODP, RTF, EML, lista do ZIP) com a tarja do desenho — dc L4659 e
 * `bj-visualizar.js` `imprimir`/`capturar` (L95-102).
 *
 * O conteúdo é de cliente (entrada hostil), então o que se trava aqui é o isolamento:
 *  - o iframe de impressão é `sandbox` SEM `allow-scripts`, e o documento dele nasce com a CSP do
 *    visualizador, montado só de constantes;
 *  - o conteúdo entra por DOM (`importNode` da cópia inerte que o visualizador entrega), nunca
 *    por string; a tarja (que leva o nome do arquivo) entra por `textContent`;
 *  - o visualizador entrega a cópia relendo os srcdoc que ele próprio montou e limpando de novo,
 *    sem atribuir innerHTML nem srcdoc fora do `criarIframe`.
 *
 * Regex não executa JS: a impressão de verdade é smoke do dono.
 */
#[CoversNothing]
final class ImpressaoDoVisorArquiteturaTest extends TestCase
{
    private const VISOR        = 'public/js/pasta-explorador-visor.js';
    private const VISUALIZADOR = 'public/js/visualizador-documento.js';
    private const CATALOGO     = __DIR__ . '/../../translations/messages+intl-icu.pt_BR.yaml';

    private static function ler(string $arquivo): string
    {
        $conteudo = file_get_contents(\dirname(__DIR__, 2) . '/' . $arquivo);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    /** Do `function <nome>(` até a próxima declaração no mesmo nível (4 espaços). */
    private static function funcao(string $js, string $nome): string
    {
        $ok = preg_match('/^    (?:async )?function ' . $nome . '\(.*?(?=^    (?:async )?function |^    \/\/ -{3,}|\z)/ms', $js, $m);
        self::assertSame(1, $ok, "função {$nome} não encontrada");

        return $m[0];
    }

    #[TestDox('o iframe de impressão do conteúdo é sandbox SEM allow-scripts (allow-same-origin só para o visor preencher por DOM, allow-modals para o print)')]
    public function testIframeDeImpressaoESandboxSemScripts(): void
    {
        $js = self::ler(self::VISOR);

        self::assertStringContainsString("const SANDBOX_IMPRESSAO = 'allow-same-origin allow-modals';", $js);
        self::assertStringNotContainsString('allow-scripts', $js);
        self::assertStringNotContainsString('allow-popups', $js);
        self::assertStringNotContainsString('allow-top-navigation', $js);

        $imprimir = self::funcao($js, 'imprimir');
        $sandbox  = strpos($imprimir, "fr.setAttribute('sandbox', SANDBOX_IMPRESSAO);");
        $srcdoc   = strpos($imprimir, 'fr.srcdoc = documentoDeImpressao(renderizado.css);');
        $anexa    = strpos($imprimir, 'document.body.appendChild(fr);', (int) $srcdoc);
        self::assertNotFalse($sandbox);
        self::assertNotFalse($srcdoc);
        self::assertNotFalse($anexa);
        self::assertLessThan($srcdoc, $sandbox, 'o sandbox é posto ANTES de o documento existir');
        self::assertLessThan($anexa, $srcdoc, 'e antes de o iframe entrar na página');
    }

    #[TestDox('o documento de impressão nasce com a CSP restritiva do visualizador e só de constantes (nenhum dado do arquivo em string)')]
    public function testDocumentoDeImpressaoTemCspESoConstantes(): void
    {
        $visor = self::ler(self::VISOR);
        $csp   = "default-src 'none'; img-src data:; style-src 'unsafe-inline'";

        self::assertStringContainsString('const CSP_IMPRESSAO = "' . $csp . '";', $visor);
        self::assertStringContainsString('const CSP_SRCDOC = "' . $csp . '";', self::ler(self::VISUALIZADOR), 'a MESMA CSP do srcdoc do visualizador');

        $doc = self::funcao($visor, 'documentoDeImpressao');
        self::assertStringContainsString('<meta http-equiv="Content-Security-Policy" content="\' + CSP_IMPRESSAO + \'">', $doc);
        // Só entram CSP_IMPRESSAO, o css (constante do visualizador) e CSS_TARJA: nada de nome, mime ou conteúdo.
        preg_match_all('/\+ ([A-Za-z_.]+)(?= \+)/', $doc, $m);
        self::assertSame(['CSP_IMPRESSAO', 'css', 'CSS_TARJA'], array_values(array_unique($m[1])));
        self::assertStringContainsString('<body></body>', $doc, 'o corpo nasce vazio');

        $vis = self::ler(self::VISUALIZADOR);
        self::assertStringContainsString('return { nos: nos, css: CSS_IMPRESSAO, csp: CSP_SRCDOC };', self::funcao($vis, 'paraImpressao'));
        self::assertMatchesRegularExpression('/const CSS_IMPRESSAO = \'[^\']*\'(\s*\+ \'[^\']*\')*;/', $vis, 'o css é constante literal');
    }

    #[TestDox('a tarja é a do desenho ("BlueJus · Pasta N · nome · impresso em …") e entra por textContent')]
    public function testTarjaDoDesenhoPorTextContent(): void
    {
        $js     = self::ler(self::VISOR);
        $tarja  = self::funcao($js, 'tarjaDeImpressao');

        self::assertStringContainsString("cab.textContent = BJ_T.t('pasta_documentos.js.visor_tarja', { pasta: visor.dataset.pastaRotulo || '', nome: a.nome, quando: new Date().toLocaleString('pt-BR') });", $tarja);
        self::assertSame('BlueJus · Pasta {pasta} · {nome} · impresso em {quando}', Yaml::parseFile(self::CATALOGO)['pasta_documentos']['js']['visor_tarja']);
        self::assertStringContainsString("const CSS_TARJA = '.cab{font-size:9pt;color:#6b8494;border-bottom:1px solid #c9d6de;padding-bottom:4px;margin-bottom:10px}';", $js);

        $imprimir = self::funcao($js, 'imprimir');
        self::assertSame(2, substr_count($imprimir, 'tarjaDeImpressao('), 'a mesma tarja no conteúdo e na imagem');
    }

    #[TestDox('o conteúdo entra no iframe por importNode da cópia inerte; nada de innerHTML, outerHTML, insertAdjacentHTML ou document.write')]
    public function testConteudoEntraPorDom(): void
    {
        $js = self::ler(self::VISOR);

        self::assertStringContainsString('dc.body.appendChild(dc.importNode(renderizado.nos, true));', self::funcao($js, 'imprimir'));
        self::assertStringContainsString('window.VisualizadorDocumento.paraImpressao(el.alvo)', self::funcao($js, 'imprimir'));
        self::assertDoesNotMatchRegularExpression('/\.(inner|outer)HTML\b/', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringNotContainsString('document.write', $js);
        self::assertSame(1, substr_count($js, '.srcdoc = '), 'o único srcdoc do visor é o documento de impressão');
    }

    #[TestDox('o visualizador entrega cópia inerte: clona para documento separado, troca cada iframe pelo srcdoc relido e LIMPO, tira botões, mídia e ícones')]
    public function testVisualizadorEntregaCopiaInerteELimpa(): void
    {
        $js     = self::ler(self::VISUALIZADOR);
        $copiar = self::funcao($js, 'paraImpressao');

        self::assertStringContainsString("document.implementation.createHTMLDocument('')", $copiar);
        self::assertStringContainsString('inerte.importNode(conteudo, true)', $copiar);
        self::assertStringContainsString("new DOMParser().parseFromString(original.getAttribute('srcdoc') || '', 'text/html');", $copiar);
        self::assertStringContainsString('limparArvore(lido);', $copiar, 'o HTML relido passa de novo pela limpeza do neutralizar');
        self::assertStringContainsString("copia.querySelectorAll('button,input,select,textarea,audio,video,.vd-abas,i.bi')", $copiar);
        self::assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|srcdoc\s*=/', $copiar);

        // A limpeza é UMA só, a do neutralizar.
        self::assertStringContainsString('limparArvore(doc);', self::funcao($js, 'neutralizar'));
        self::assertStringContainsString("'script,style,iframe,frame,object,embed,link,meta,base,form,svg,math'", self::funcao($js, 'limparArvore'));

        // Só o que terminou de desenhar, e não é o bloco de "não disponível", pode ser impresso.
        self::assertStringContainsString("if (vivo() && !alvo.querySelector('[data-vd-sem-conteudo]')) { alvo.__vdImprimivel = token; }", self::funcao($js, 'abrir'));
        self::assertStringContainsString('conteudo.__vdImprimivel !== conteudo.__vdToken', $copiar, 'abrir outro arquivo (novo token) invalida a cópia');
        self::assertStringContainsString("div.setAttribute('data-vd-sem-conteudo', '');", self::funcao($js, 'blocoNaoDisponivel'));
        self::assertStringContainsString('paraImpressao: paraImpressao,', $js);
    }

    #[TestDox('C6-DOC-02: imagem que virou aviso no visor (HEIC que o navegador não abre) não vai para a impressão; o botão passa a indisponível')]
    public function testImagemSemConteudoNaoImprime(): void
    {
        $imprimir = self::funcao(self::ler(self::VISOR), 'imprimir');

        $guarda = strpos($imprimir, "if (tipo === 'imagem' && el.alvo.querySelector('[data-vd-sem-conteudo]')) {");
        self::assertNotFalse($guarda);
        self::assertLessThan(strpos($imprimir, 'if (imprimindo)'), $guarda, 'antes de montar o iframe');
        self::assertStringContainsString("el.imprimir.setAttribute('aria-disabled', 'true');", $imprimir);
    }
}
