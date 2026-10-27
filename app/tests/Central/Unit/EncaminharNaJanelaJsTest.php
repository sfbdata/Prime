<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Encaminhar via Chat I.A" na janela (spec `encaminhar-via-chat-ia.md` §4.1): a API `window.BJCentral` do lançador,
 * os eventos no document, a tela "Despachar via Chat I.A" (BC:6218-6238) e o chip "Pasta N" (BC:6041), lidos no
 * texto dos scripts servidos. A aparência é do Playwright comparativo e do smoke do dono.
 */
final class EncaminharNaJanelaJsTest extends TestCase
{
    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/' . $arquivo);
    }

    /** O trecho do script entre dois marcadores (inclusive o primeiro), para olhar só o código novo. */
    private static function trecho(string $js, string $de, string $ate): string
    {
        $inicio = strpos($js, $de);
        self::assertNotFalse($inicio, $de);
        $fim = strpos($js, $ate, $inicio);
        self::assertNotFalse($fim, $ate);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('central-flutuante.js: window.BJCentral com abrir, despachar e conversar, só dentro do lançador, e os eventos no document')]
    public function testApiDaJanela(): void
    {
        $js = self::js('central-flutuante.js');
        $api = self::trecho($js, '// ── API da janela para o resto do sistema', "btnMinimizar.addEventListener('click', minimizar);");

        self::assertStringContainsString("window.BJCentral = {\n            abrir: abrirSeFechada,\n            despachar: despacharItem,\n            conversar: conversarCom\n        };", $api);
        self::assertStringContainsString("document.addEventListener('bj-central:despachar', function (ev) { despacharItem(ev.detail); });", $api);
        self::assertStringContainsString("document.addEventListener('bj-central:conversar', function (ev) { conversarCom(ev.detail && ev.detail.userId); });", $api);
        self::assertStringContainsString("if (!ui.aberto) { abrir(); }", $api, 'abrir() é o mesmo da janela, sem trocar de conversa');
        self::assertStringContainsString("central.dispatchEvent(new CustomEvent('bj-central:janela-despachar'", $api);
        self::assertStringContainsString("central.dispatchEvent(new CustomEvent('bj-central:janela-conversar'", $api);

        // Definido dentro de iniciar(), depois da guarda de que o lançador e a janela existem: fora dele não há API.
        $guarda = strpos($js, 'if (!janela || !central) { return; }');
        self::assertNotFalse($guarda);
        self::assertGreaterThan($guarda, strpos($js, 'window.BJCentral = {'));
        self::assertSame(1, substr_count($js, 'window.BJCentral ='), 'um global só, atribuído uma vez');
        self::assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML/', $api);
    }

    #[TestDox('central.js: recebe os pedidos do lançador e reaproveita iniciarConversaCom, calado para si mesmo ou colega de fora')]
    public function testLigacaoComOLancador(): void
    {
        $js = self::js('central.js');

        self::assertStringContainsString("raiz.addEventListener('bj-central:janela-despachar', function (ev) { if (!estado.semSessao) { abrirDespacho(ev.detail); } });", $js);
        self::assertStringContainsString("raiz.addEventListener('bj-central:janela-conversar', function (ev) { conversarCom(ev.detail && ev.detail.userId); });", $js);
        self::assertStringContainsString("String(id) === String(raiz.dataset.euId || '')", $js, 'si mesmo: só abre');
        self::assertStringContainsString('iniciarConversaCom({ id: id }, false, true);', $js);
        self::assertStringContainsString('if (!calado) { avisar(e.message); }', $js);
        self::assertStringNotContainsString('window.BJCentral', $js, 'o global é do lançador');
    }

    #[TestDox('Tela de despacho: textos literais do desenho, ícones por tipo, rotas do encaminhar e sem innerHTML')]
    public function testTelaDeDespacho(): void
    {
        $js = self::js('central.js');
        $tela = self::trecho($js, '// ── Despachar via Chat I.A (telaDespacho', 'function voltar() {');

        foreach ([
            "'Despachar via Chat I.A'",
            "'Cancelar'",
            "'Instrução (opcional). Ex.: favor concluir até sexta.'",
            "'Para quem?'",
            "avisar('Despachado para ' + (r.destinoNome || dest.nome) + '.');",
            "pedir(urls.encaminhar, { metodo: 'POST', corpo: corpo })",
            'pedir(urls.encaminharItem + doItem)',
            'pedir(urls.encaminharDestinos + consulta)',
            "b.disabled = true;\n                b.title = dest.motivo || '';",
            "mostrarTela('despacho');",
        ] as $esperado) {
            self::assertStringContainsString($esperado, $tela);
        }
        self::assertStringContainsString("var PRIORIDADES = [['normal', 'Normal'], ['urgente', 'Urgente'], ['para_hoje', 'Para hoje']];", $js);
        self::assertStringContainsString("var ICONE_DO_DESPACHO = { meta: 'bi-check2-square', documento: 'bi-file-earmark-text', push: 'bi-broadcast', registro: 'bi-journal-text', pasta: 'bi-folder2', processo: 'bi-bank' };", $js);
        self::assertStringContainsString("var TIPO_DO_DESENHO = { registro_dados: 'registro', registro_detalhes: 'registro', registro_financeiro: 'registro', secao: 'pasta', prazo: 'meta', nota_tecnica: 'push' };", $js);
        self::assertStringContainsString('var INSTRUCAO_MAXIMA = 1000;', $js);
        self::assertStringContainsString("el.telaDespacho.hidden = nome !== 'despacho';", $js);
        self::assertStringContainsString("chip.appendChild(icone('bi-folder2-open'));", $tela, 'chip "Pasta N" (BC:6041)');
        self::assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML|document\.write/', $tela);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $tela, 'sem travessão nos textos novos');
    }

    #[TestDox('Chip "Pasta N": sai da referência do servidor (despacho.pastaId/nup), é um link para a pasta com id inteiro')]
    public function testChipPelaReferencia(): void
    {
        $js = self::js('central.js');
        $chip = self::trecho($js, 'function chipDaPasta(despacho) {', 'function voltar() {');

        self::assertStringContainsString("var chip = chipDaPasta(m.despacho);\n                pintarTexto(corpoBolha, chip ? semALinhaDaPasta(m.conteudo, m.despacho) : m.conteudo);", $js);
        self::assertStringContainsString("var id = despacho ? Number(despacho.pastaId) : NaN;\n            if (!Number.isInteger(id) || id <= 0) { return null; }", $chip);
        self::assertStringContainsString("var rotulo = 'Pasta ' + (nup !== null ? nup : String(id));", $chip);
        self::assertStringContainsString("var chip = criar('a', 'bj-central-chip-pasta');\n            chip.href = urls.pastaBase + String(id);", $chip);
        self::assertStringContainsString("pastaBase: raiz.dataset.urlPastaBase", $js);
        // A linha "Pasta NUP" do texto sai só por comparação exata com a da referência.
        self::assertStringContainsString("if (corte === -1 || bloco.slice(corte + 1) !== linha) { return texto; }", $chip);
        self::assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML|document\.write/', $chip);
    }

    #[TestDox('Nada do chip sai do texto por regex: sem pastaDoDespacho, sem os padrões de "Despacho:" e de "Pasta "')]
    public function testSemExtracaoPorRegex(): void
    {
        $js = self::js('central.js');

        foreach (['pastaDoDespacho', 'COMECO_DO_DESPACHO', 'LINHA_DA_PASTA', 'Despacho: /', '/^Pasta '] as $resto) {
            self::assertStringNotContainsString($resto, $js);
        }
        $chip = self::trecho($js, 'function chipDaPasta(despacho) {', 'function voltar() {');
        self::assertDoesNotMatchRegularExpression('#\.(test|match|exec|replace)\(\s*/|new RegExp#', $chip, 'chip e linha da pasta sem regex');
    }

    #[TestDox('ocultoPorPermissao: balão como "Mensagem excluída" (BC:6039), citação e prévia só o texto, sem chip')]
    public function testOcultoPorPermissao(): void
    {
        $js = self::js('central.js');
        $css = self::css();

        self::assertStringContainsString("if (m.ocultoPorPermissao) {", $js);
        self::assertStringContainsString("var icOculto = icone('bi-slash-circle');\n                icOculto.classList.add('bj-central-oculto-ico');\n                oculto.appendChild(icOculto);\n                oculto.appendChild(document.createTextNode(m.conteudo));", $js);
        self::assertStringContainsString("corpoBolha.appendChild(oculto);\n            } else {\n                var chip = chipDaPasta(m.despacho);", $js, 'oculto não tem chip');
        self::assertStringContainsString("(m.respostaA.ocultoPorPermissao ? ' bj-central-oculto-trecho' : ''), m.respostaA.trecho)", $js);
        self::assertStringContainsString("(c.ultima && c.ultima.ocultoPorPermissao ? ' bj-central-oculto-trecho' : ''), previa)", $js);
        self::assertMatchesRegularExpression('/\.bj-central-oculto \{\s+font-style: italic;\s+opacity: \.7;\s+\}/', $css);
        self::assertMatchesRegularExpression('/\.bj-central-oculto-ico \{\s+margin-right: 5px;\s+\}/', $css);
        self::assertMatchesRegularExpression('/\.bj-central-oculto-trecho \{\s+font-style: italic;\s+\}/', $css);
    }

    #[TestDox('Menu da mensagem oculta: sem Responder e sem Copiar; Mencionar continua; sem itens, sem menu')]
    public function testMenuDaMensagemOculta(): void
    {
        $menu = self::trecho(self::js('central.js'), 'function menuDaMensagem(', 'function falhaDeAcao(');

        self::assertStringContainsString('var oculta = !!m.ocultoPorPermissao;', $menu);
        self::assertStringContainsString("if (podeEscrever() && !oculta && !comIa) {\n                itens.push({ icone: 'bi-reply', rotulo: 'Responder'", $menu);
        self::assertStringContainsString("if (!oculta) {\n                itens.push({ icone: 'bi-clipboard', rotulo: 'Copiar'", $menu);
        self::assertStringContainsString("var autor = !m.minha && !comIa && podeEscrever() ? mencionavel(m.autorId) : null;", $menu, 'Mencionar não depende do oculto');
        self::assertStringContainsString('if (!itens.length) { return; }', $menu);
    }

    #[TestDox('Chip na destacada abre aba nova (noopener) e a Central fica; Ctrl/Cmd/Shift/Alt e outro botão seguem o navegador')]
    public function testCliqueDoChip(): void
    {
        $chip = self::trecho(self::js('central.js'), 'function chipDaPasta(despacho) {', 'function semALinhaDaPasta(');

        self::assertStringContainsString('if (ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }', $chip);
        self::assertStringContainsString("if (vista() !== window) { ev.preventDefault(); window.open(chip.href, '_blank', 'noopener'); }", $chip);
        self::assertStringNotContainsString('location.assign', $chip, 'navegar a origem fecharia a Central destacada');
    }

    #[TestDox('Tema escuro: chip dos outros com par próprio legível (7,5:1); o claro e o balão próprio não mudam')]
    public function testChipNoTemaEscuro(): void
    {
        $css = self::css();

        self::assertStringContainsString("--bjc-chip-pasta-fundo: #1b4a63;\n    --bjc-chip-pasta-texto: #cfe8f6;", $css);
        foreach (['', ':hover', ':focus'] as $estado) {
            self::assertStringContainsString('html[data-bs-theme="dark"] .bj-central .bj-central-msg:not(.bj-central-msg--minha) .bj-central-chip-pasta' . $estado, $css);
        }
        self::assertMatchesRegularExpression('/\n\.bj-central-chip-pasta \{[^}]*background: var\(--bjc-selecionado\);\s+color: var\(--bjc-petroleo\);/', $css, 'claro: o do BC:6041');
    }

    private static function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/css/central.css');
    }
}
