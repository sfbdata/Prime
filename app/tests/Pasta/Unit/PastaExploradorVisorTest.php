<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L10 da aba Documentos: o visor em tela cheia (DOC-47..50; dc L299-329 e `visorVals`
 * L4634-4673). Teste de FOLHA (precedente: PastaExploradorContratoJsTest, PastaExploradorInteracaoTest):
 * não há harness de navegador na suíte, então o que se trava é o texto das peças — que o
 * conteúdo passa pelo VisualizadorDocumento (sandbox), que o visor percorre a lista VISÍVEL,
 * os limites do zoom, a impressão só de PDF/imagem, o teclado, o trap de foco e a ausência da
 * área de soltar (S-7). Aparência e impressão de verdade são smoke do dono.
 */
#[CoversNothing]
final class PastaExploradorVisorTest extends TestCase
{
    private const VISOR      = __DIR__ . '/../../../public/js/pasta-explorador-visor.js';
    private const EXPLORADOR = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const CSS        = __DIR__ . '/../../../public/css/pasta-explorador.css';
    private const TWIG       = __DIR__ . '/../../../templates/pasta/_documentos_explorador.html.twig';

    private function ler(string $arquivo): string
    {
        $c = file_get_contents($arquivo);
        self::assertIsString($c, $arquivo);

        return $c;
    }

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $js, string $nome): string
    {
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('o conteúdo vai pelo VisualizadorDocumento.abrir dentro de #pexVisorAlvo; fechar chama limpar() (aborta o download)')]
    public function testConteudoPassaPeloVisualizador(): void
    {
        $js = $this->ler(self::VISOR);

        self::assertMatchesRegularExpression(
            '/window\.VisualizadorDocumento\.abrir\(\{\s*url: a\.viewUrl,\s*nome: a\.nome,\s*mime: a\.mime \|\| \'\',\s*urlDownload: a\.downloadUrl,\s*conteudo: el\.alvo,\s*\}\);/',
            $this->funcao($js, 'renderizar'),
            'o visor não desenha o arquivo: quem desenha (e põe DOCX/planilha em iframe sandbox) é o módulo único'
        );
        self::assertStringContainsString("alvo:     document.getElementById('pexVisorAlvo'),", $js);
        self::assertStringContainsString('window.VisualizadorDocumento.limpar(el.alvo);', $this->funcao($js, 'fechar'));
        // O tipo (zoom e impressão) é o MESMO que decide o render — nada de segunda tabela de MIME.
        self::assertStringContainsString('window.VisualizadorDocumento.tipoDe(a.mime, a.nome)', $js);
    }

    #[TestDox('nada de innerHTML/insertAdjacentHTML/document.write no visor; nome e cliente entram por textContent')]
    public function testSemInjecaoDeHtml(): void
    {
        $js = $this->ler(self::VISOR);

        self::assertDoesNotMatchRegularExpression('/\.(inner|outer)HTML\b/', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringNotContainsString('document.write', $js, 'nem no iframe da impressão: o documento é montado por DOM');
        self::assertStringContainsString('el.nome.textContent = a.nome;', $js);
        self::assertStringContainsString("el.meta.textContent = [a.tipo, a.tamanho, a.data].filter(Boolean).concat([pastaTxt]).join(' · ');", $js);
    }

    #[TestDox('sem storage novo: o visor não grava nada em localStorage/sessionStorage')]
    public function testSemStorage(): void
    {
        $js = $this->ler(self::VISOR);

        self::assertStringNotContainsString('localStorage', $js);
        self::assertStringNotContainsString('sessionStorage', $js);
    }

    #[TestDox('o explorador abre o VISOR antes do modal, com os ARQUIVOS da lista visível (ordem, filtro e busca de agora); sem visor, o #previewDocModal de sempre')]
    public function testGanchoNoExplorador(): void
    {
        $js = $this->ler(self::EXPLORADOR);

        $abrirPreview = $this->funcao($js, 'abrirPreview');
        self::assertMatchesRegularExpression('/function abrirPreview\(gatilho\) \{\s*if \(abrirNoVisor\(gatilho\)\) return;/', $abrirPreview, 'o visor vem PRIMEIRO');
        self::assertStringContainsString('bootstrap.Modal.getOrCreateInstance(modal).show(gatilho);', $abrirPreview, 'o modal continua como queda');

        $gancho = $this->funcao($js, 'abrirNoVisor');
        self::assertStringContainsString("if (!window.PexVisor || typeof window.PexVisor.abrir !== 'function') return false;", $gancho);
        // A lista do visor é a que ESTÁ NA TELA (itensRenderizados = itensVisiveis()), só arquivos.
        self::assertStringContainsString("itensRenderizados.filter(function (it) { return it.tipo === 'arquivo'; })", $gancho);
        // O arquivo é achado pelo `data-url` do MESMO gatilho que o modal lê.
        self::assertStringContainsString('gatilho.dataset.url', $gancho);
        self::assertStringContainsString('downloadUrl: a.downloadUrl', $gancho);
        // Fechar: a linha do arquivo que estava na tela fica selecionada e a lista recebe o foco.
        self::assertStringContainsString('if (linha) selecionar(chave);', $gancho);
        self::assertStringContainsString('el.lista.focus({ preventScroll: true });', $gancho);
    }

    #[TestDox('anterior/próximo param nas pontas (sem dar a volta) e o contador é "n de N"')]
    public function testNavegacaoEContador(): void
    {
        $js = $this->ler(self::VISOR);

        self::assertStringContainsString('const novo = Math.max(0, Math.min(lista.length - 1, indice + delta));', $this->funcao($js, 'ir'));
        $render = $this->funcao($js, 'renderizar');
        self::assertStringContainsString("el.posicao.textContent = (indice + 1) + ' de ' + lista.length;", $render);
        self::assertStringContainsString('el.anterior.disabled = indice === 0;', $render);
        self::assertStringContainsString('el.proximo.disabled = indice === lista.length - 1;', $render);
    }

    #[TestDox('zoom de 10 em 10, de 50% a 200% (DOC-49), por escala do contêiner; sem zoom para áudio, vídeo, ZIP e "outro"')]
    public function testZoom(): void
    {
        $js = $this->ler(self::VISOR);

        self::assertStringContainsString('const ZOOM_MIN = 50, ZOOM_MAX = 200, ZOOM_PASSO = 10;', $js);
        self::assertStringContainsString("const TIPOS_COM_ZOOM = ['pdf', 'imagem', 'docx', 'planilha', 'texto', 'odt', 'pptx', 'rtf', 'eml'];", $js);
        self::assertStringContainsString("el.alvo.style.setProperty('--pex-visor-zoom', String(zoom / 100));", $this->funcao($js, 'aplicarZoom'));

        $css = $this->ler(self::CSS);
        self::assertMatchesRegularExpression('/\.pex-visor-alvo \{[^}]*zoom: var\(--pex-visor-zoom\);/s', $css, 'a escala é do contêiner, não de cada renderizador');
        self::assertMatchesRegularExpression('/\.pex-visor-alvo \{[^}]*margin: 0 auto;/s', $css, 'centralizar por justify-content cortaria a esquerda acima de 100%');
    }

    #[TestDox('imprimir só para PDF e imagem, por iframe OCULTO; nos demais o botão fica desabilitado com o motivo no title')]
    public function testImpressao(): void
    {
        $js = $this->ler(self::VISOR);

        self::assertStringContainsString("const TIPOS_IMPRIMIVEIS = ['pdf', 'imagem'];", $js);
        $render = $this->funcao($js, 'renderizar');
        self::assertStringContainsString("el.imprimir.setAttribute('aria-disabled', imprimivel ? 'false' : 'true');", $render);
        self::assertStringContainsString("el.imprimir.title = imprimivel ? 'Imprimir' : MOTIVO_SEM_IMPRESSAO;", $render);
        self::assertStringContainsString('PDF e imagem', $js);

        $imprimir = $this->funcao($js, 'imprimir');
        self::assertStringContainsString("if (TIPOS_IMPRIMIVEIS.indexOf(tipo) === -1) { mostrarAviso(MOTIVO_SEM_IMPRESSAO); return; }", $imprimir);
        self::assertStringContainsString("fr.className = 'pex-visor-impressao';", $imprimir);
        self::assertStringContainsString('fr.src = a.viewUrl;', $imprimir, 'PDF: o próprio arquivo, impresso pelo leitor do navegador');
        self::assertStringContainsString('w.print();', $imprimir);
        self::assertStringNotContainsString('window.print()', $js, 'nunca a página inteira');
        // Fora da tela com tamanho REAL: num 0×0/visibility:hidden o leitor de PDF do Chrome pode não montar.
        self::assertMatchesRegularExpression('/\.pex-visor-impressao \{[^}]*left: -10000px;[^}]*width: 800px; height: 600px;/', $this->ler(self::CSS));
        self::assertDoesNotMatchRegularExpression('/\.pex-visor-impressao \{[^}]*(visibility: hidden|width: 0)/', $this->ler(self::CSS));
        // Um de cada vez, mas a trava não prende: solta em até 3 s sem afterprint, e o clique no meio avisa.
        self::assertStringContainsString("const MSG_PREPARANDO = 'Preparando impressão…';", $js);
        self::assertStringContainsString('if (imprimindo) { mostrarAviso(MSG_PREPARANDO); return; }', $imprimir);
        self::assertStringContainsString('trava = setTimeout(function () { imprimindo = false; }, 3000);', $imprimir);
        self::assertMatchesRegularExpression('/liberarEm3s\(\);\s*w\.print\(\);/', $imprimir, 'o prazo recomeça quando o print() é chamado');
    }

    #[TestDox('as peças .vd-* moram SÓ no visualizador-documento.css, com o escopo ampliado ao visor e alturas por variável cujo padrão é o valor de sempre do modal')]
    public function testCssDoVisualizadorCompartilhado(): void
    {
        $vd = $this->ler(__DIR__ . '/../../../public/css/visualizador-documento.css');

        self::assertStringContainsString(':is(#previewDocModal, .pex-visor) .vd-quadro {', $vd);
        self::assertDoesNotMatchRegularExpression('/^#previewDocModal /m', $vd, 'toda regra passa pelo :is() ampliado');
        // O modal antigo fica idêntico: o fallback do var() é o número de antes.
        foreach ([
            'height: var(--vd-altura, 75vh);',
            'height: calc(var(--vd-altura, 75vh) - 40px);',
            'max-height: var(--vd-max, 75vh);',
            'max-height: var(--vd-max-email, 65vh);',
            'height: var(--vd-altura-email, 65vh);',
            'max-height: var(--vd-max-zip, 70vh);',
        ] as $altura) {
            self::assertStringContainsString($altura, $vd);
        }
        self::assertDoesNotMatchRegularExpression('/:\s*(calc\()?\d+vh/', $vd, 'nenhuma altura fixa sobrou fora das variáveis');

        $css = $this->ler(self::CSS);
        self::assertDoesNotMatchRegularExpression('/\.vd-[a-z-]+[^{};]*\{/', $css, 'pasta-explorador.css não redefine nenhuma regra .vd-*');
        self::assertMatchesRegularExpression('/\.pex-visor-alvo \{\s*--vd-altura:\s+var\(--pex-visor-quadro-h\);/', $css, 'o visor só troca as variáveis');
    }

    #[TestDox('navegador sem a propriedade CSS zoom: o grupo de zoom some e a largura proporcional não é aplicada')]
    public function testZoomSemSuporte(): void
    {
        $css = $this->ler(self::CSS);
        self::assertMatchesRegularExpression('/@supports not \(zoom: 1\) \{\s*\.pex-visor-zoom \{ display: none !important; \}\s*\.pex-visor-alvo \{ width: 100%; \}/', $css);

        $js = $this->ler(self::VISOR);
        self::assertStringContainsString("window.CSS.supports('zoom', '1')", $js);
        self::assertStringContainsString('el.zoom.hidden = !ZOOM_SUPORTADO || TIPOS_COM_ZOOM.indexOf(tipo) === -1;', $js);
        self::assertStringContainsString('if (el.zoom.hidden) return;', $this->funcao($js, 'mudarZoom'), '+/− pelo teclado também respeitam');
    }

    #[TestDox('teclado do dc: Esc fecha, ← → percorrem, + e − dão zoom; Ctrl/Cmd/Alt ficam com o navegador')]
    public function testTeclado(): void
    {
        $js = $this->ler(self::VISOR);

        $ini = strpos($js, "visor.addEventListener('keydown', function (e) {");
        self::assertNotFalse($ini);
        $handler = substr($js, $ini, (int) strpos($js, "\n    });", $ini) - $ini);

        self::assertStringContainsString("if (k === 'Escape') { e.preventDefault(); fechar(); return; }", $handler);
        self::assertStringContainsString("if (k === 'ArrowLeft') { e.preventDefault(); ir(-1); return; }", $handler);
        self::assertStringContainsString("if (k === 'ArrowRight') { e.preventDefault(); ir(1); return; }", $handler);
        self::assertStringContainsString("if (k === '+' || k === '=') { e.preventDefault(); mudarZoom(ZOOM_PASSO); return; }", $handler);
        self::assertStringContainsString("if (k === '-' || k === '_') { e.preventDefault(); mudarZoom(-ZOOM_PASSO); }", $handler);
        $guarda = strpos($handler, 'if (e.ctrlKey || e.metaKey || e.altKey) return;');
        self::assertNotFalse($guarda);
        self::assertLessThan(strpos($handler, "k === 'ArrowLeft'"), $guarda, 'Ctrl/Cmd com + e − é o zoom do navegador');
    }

    #[TestDox('trap de foco: Tab dá a volta dentro do visor e foco que escapa (de dentro do iframe, por exemplo) volta para ele')]
    public function testTrapDeFoco(): void
    {
        $js = $this->ler(self::VISOR);

        self::assertStringContainsString("if (k === 'Tab') {", $js);
        self::assertStringContainsString("if (e.shiftKey && (ativo === primeiro || ativo === visor)) { e.preventDefault(); ultimo.focus(); }", $js);
        self::assertStringContainsString("else if (!e.shiftKey && ativo === ultimo) { e.preventDefault(); primeiro.focus(); }", $js);
        self::assertMatchesRegularExpression("/document\.addEventListener\('focusin', function \(e\) \{\s*if \(!aberto\(\) \|\| visor\.contains\(e\.target\)\) return;\s*visor\.focus/", $js);
    }

    #[TestDox('o visor sai do explorador para o <body>: as teclas dele não viram atalho da lista (Backspace, Del, setas)')]
    public function testVisorVaiParaOBody(): void
    {
        $js = $this->ler(self::VISOR);

        self::assertStringContainsString('document.body.appendChild(visor);', $js);
        self::assertLessThan(
            strpos($js, "visor.addEventListener('keydown'"),
            strpos($js, 'document.body.appendChild(visor);'),
            'move antes de ligar qualquer evento'
        );
    }

    #[TestDox('S-7: nenhuma área de "soltar arquivo no visor" (DOC-51) — nada de blob local; soltar é só ignorado')]
    public function testSemAreaDeSoltar(): void
    {
        $js   = $this->ler(self::VISOR);
        $twig = $this->ler(self::TWIG);

        self::assertStringNotContainsString('createObjectURL', $js);
        self::assertStringNotContainsString('dataTransfer.files', $js);
        self::assertStringNotContainsString('enviarArquivoComProgresso', $js, 'soltar no visor NÃO vira upload sem a decisão de S-7');
        self::assertStringContainsString("el.area.addEventListener('drop', function (e) { e.preventDefault(); });", $js);
        self::assertStringNotContainsString('Arraste um arquivo', $twig);
    }

    #[TestDox('o partial carrega o visor com defer; os tokens do visor têm o par do tema escuro e o visor fica acima dos modais do Bootstrap')]
    public function testCargaETema(): void
    {
        $twig = $this->ler(self::TWIG);
        self::assertSame(1, substr_count($twig, "<script src=\"{{ asset('js/pasta-explorador-visor.js') }}\" defer></script>"));
        self::assertStringContainsString("data-pasta-cliente=\"{{ visorCliente ? visorCliente.nomeExibicao : '' }}\"", $twig, 'sem cliente vinculado, nada é inventado');

        $css = $this->ler(self::CSS);
        self::assertMatchesRegularExpression('/\.pex-visor \{[^}]*--pex-visor-fundo:\s+#525659;[^}]*--pex-visor-cab-bg:\s+#0a7aad;/s', $css, 'cores do dc (DOC-47)');
        self::assertMatchesRegularExpression('/\[data-bs-theme="dark"\] \.pex-visor \{[^}]*--pex-visor-fundo:[^}]*--pex-visor-cab-bg:/s', $css);
        self::assertMatchesRegularExpression('/\.pex-visor \{[^}]*z-index: 1060;/s', $css);
        self::assertStringContainsString('.pex-visor[hidden] { display: none; }', $css, 'sem isto o display:flex venceria o atributo hidden');
        self::assertMatchesRegularExpression('/@media \(max-width: 575\.98px\) \{\s*\.pex-visor-cab/', $css, '375px');
    }
}
