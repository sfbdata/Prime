<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L5 da aba Documentos: seleção, laço, teclado, menu de contexto, barra, toast, inline,
 * arraste da seleção e ações em lote — os contratos do `pasta-explorador.js`/`.css` que nenhum
 * teste de HTML vê, porque tudo isso nasce no JS a partir de eventos.
 *
 * Teste de FOLHA (precedente: PastaExploradorContratoJsTest, PastaExploradorModosTest). Não há
 * harness de navegador na suíte; o que dá para travar é o texto das guardas, dos endpoints e
 * dos números do desenho (02 - EXPEDIENTES 1.2.3) — o bastante para um refactor que troque o
 * token do lote, deixe o Del disparar dentro do checklist ou tire o Copiar/zip do L8 do lugar
 * ficar vermelho aqui em vez de em produção. O L8-UI em si é travado em PastaExploradorZipCopiarTest.
 */
final class PastaExploradorInteracaoTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const CSS = __DIR__ . '/../../../public/css/pasta-explorador.css';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    private function css(): string
    {
        return (string) file_get_contents(self::CSS);
    }

    /** O corpo de `function $nome(…) {` até o fechamento no mesmo recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js  = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('teclado: nenhum atalho dispara com foco em campo de texto, select, contenteditable, dentro do checklist, nem com o menu aberto; Enter/Espaço/Del/F2/setas/Ctrl+A só com o foco na lista ou numa linha')]
    public function testGuardasDoTeclado(): void
    {
        $js = $this->js();

        $handler = strpos($js, "raiz.addEventListener('keydown', function (e) {");
        self::assertNotFalse($handler);
        $guardaCampo     = strpos($js, "if (!naCaixa && (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable))) return;", $handler);
        $guardaChecklist = strpos($js, "if (e.target && e.target.closest && e.target.closest('#pexChecklist')) return;", $handler);
        $guardaMenu      = strpos($js, "if (el.menu && !el.menu.hidden && e.target.closest('#pexMenu')) { teclaNoMenu(e); return; }", $handler);
        $backspace       = strpos($js, "if ((k === 'Backspace' || (e.altKey && (k === 'ArrowLeft' || k === 'ArrowUp'))) && caminho.length) {", $handler);
        $noAlvo          = strpos($js, "const noAlvo = e.target === el.lista || !!(e.target.closest && e.target.closest('.pex-item'));", $handler);
        $escPopover      = strpos($js, 'if (popoverAberto()) return;', $handler);
        $soNaLista       = strpos($js, 'if (!noAlvo) return;', $handler);
        $primeiraAcao    = strpos($js, "if (ctrl && baixa === 'a')", $handler);
        foreach ([$guardaCampo, $guardaChecklist, $guardaMenu, $backspace, $noAlvo, $escPopover, $soNaLista, $primeiraAcao] as $pos) {
            self::assertNotFalse($pos);
        }
        self::assertLessThan($primeiraAcao, $guardaCampo, 'a guarda de campo vem antes de qualquer atalho');
        self::assertLessThan($primeiraAcao, $guardaChecklist, 'Del/F2/Ctrl+A no checklist são do checklist');
        self::assertLessThan($primeiraAcao, $guardaMenu);
        // Backspace/Alt+← sobem de qualquer ponto do explorador (como no L2) — ANTES da trava da lista.
        self::assertLessThan($noAlvo, $backspace);
        // Esc: com popover aberto, só o popover fecha (document); senão limpa — também da barra.
        self::assertLessThan($soNaLista, $escPopover);
        self::assertStringContainsString("if (selecao.size && (noAlvo || e.target.closest('#pexSelecao'))) { e.preventDefault(); limparSelecao(); }", $js);
        // Tudo o mais só com o foco na lista/linha: num botão da faixa, do Organizar, da barra ou
        // de um popover, Enter/Espaço/setas são do navegador.
        self::assertLessThan($primeiraAcao, $soNaLista);
        self::assertStringContainsString('function popoverAberto() { return popovers.some(function (p) { return !p.menu.hidden; }); }', $js);

        // Os atalhos do desenho (DOC-32); Ctrl+C (Copiar) desde o L8.
        foreach ([
            "if (ctrl && baixa === 'a') { e.preventDefault(); selecionarTudo(); return; }",
            "if (k === 'Delete') { if (sel.length) { e.preventDefault(); excluirItens(sel); } return; }",
            "if (k === 'F2') { if (sel.length === 1) { e.preventDefault(); iniciarRenomear(sel[0]); } return; }",
            "if (ctrl && baixa === 'x') { if (sel.length) { e.preventDefault(); recortar(sel); } return; }",
            "if (ctrl && baixa === 'c') { if (sel.length) { e.preventDefault(); copiar(sel); } return; }",
            "if (ctrl && baixa === 'v') { if (areaDeTransferencia) { e.preventDefault(); colarAqui(); } return; }",
            "if (/^Arrow(Up|Down|Left|Right)$/.test(k) || k === 'Home' || k === 'End') {",
        ] as $atalho) {
            self::assertStringContainsString($atalho, $js);
        }
        // Del sem seleção não faz nada — e o Delete do campo de busca não chega aqui.
        self::assertStringContainsString("if (k === 'Delete') { if (sel.length) {", $js);
    }

    #[TestDox('menu de contexto: na ordem do desenho (favorito do L6 depois de Copiar caminho; zip e Copiar do L8), SEM Desfazer no menu nem o Chat I.A (E)')]
    public function testItensDoMenuDeContexto(): void
    {
        $js    = $this->js();
        $corpo = $this->funcao('opcoesDoMenu');

        // Fundo (dc L4826-4834).
        $fundo = ["op('Nova pasta', 'bi-folder-plus', novaPastaInline)", "op('Colar', 'bi-clipboard', colarAqui, { atalho: 'Ctrl+V', desabilitado: !areaDeTransferencia })", "op('Selecionar tudo', 'bi-check2-all', selecionarTudo, { atalho: 'Ctrl+A' })", "['nome', 'Classificar por nome'], ['data', 'Classificar por data'], ['tamanho', 'Classificar por tamanho'], ['tipo', 'Classificar por tipo']", "op(painel ? 'Ocultar painel de detalhes' : 'Mostrar painel de detalhes'"];
        // Vários itens (dc L4797-4808): "Baixar como .zip (N)" abre; Copiar logo depois de Recortar (L8).
        // "Mover para…" (modal de destino) é função do sistema (§16.7) e mora aqui, não na barra.
        $multi = ["op('Baixar como .zip (' + sel.length + ')', 'bi-file-earmark-zip', function () { baixarZip(sel); })", "op('Copiar links'", "op('Recortar', 'bi-scissors', function () { recortar(sel); }, { atalho: 'Ctrl+X' })", 'opCopiar(sel),', "op('Mover para…', 'bi-folder-symlink', function () { escolherDestino(sel); })", "op('Copiar caminhos'", 'opFavorito(sel),', "op('Excluir ' + sel.length + ' itens', 'bi-trash3', function () { excluirItens(sel); }, { atalho: 'Del', perigo: true })", "op('Propriedades', 'bi-info-square', mostrarPainel)"];
        // Um item (dc L4809-4826), sem Chat; o favorito (L6, dc L4820) logo depois de Copiar caminho.
        // Pasta: "Baixar como .zip" no lugar do Baixar; arquivo: Copiar depois de Recortar (L8).
        $item = ["op('Abrir', ehPasta ? 'bi-folder2-open' : 'bi-box-arrow-up-right'", "op('Baixar como .zip', 'bi-download', function () { baixarZip([alvo]); })", "op('Visualizar', 'bi-eye', function () { abrirPreviewDe(a); }, { atalho: 'Espaço' })", "op('Baixar', 'bi-download'", "op('Copiar link', 'bi-link-45deg'", "op('Recortar', 'bi-scissors', function () { recortar([alvo]); }, { atalho: 'Ctrl+X' })", '.concat([opCopiar([alvo])])', "op('Colar', 'bi-clipboard', function () { colarEm(alvo.id); }, { atalho: 'Ctrl+V', desabilitado: !areaDeTransferencia })", "op('Mover para…', 'bi-folder-symlink', function () { escolherDestino([alvo]); })", "op('Copiar caminho', 'bi-signpost'", 'opFavorito([alvo]),', "op('Renomear', 'bi-input-cursor-text', function () { iniciarRenomear(alvo); }, { atalho: 'F2' })", "op('Editar…', 'bi-pencil'", "op('Excluir', 'bi-trash3', function () { excluirItens([alvo]); }, { atalho: 'Del', perigo: true })"];
        $pos = -1;
        foreach (array_merge($fundo, $multi, $item) as $trecho) {
            $p = strpos($corpo, $trecho, $pos + 1);
            self::assertNotFalse($p, "item fora da ordem do desenho ou ausente: {$trecho}");
            $pos = $p;
        }

        // Não renderizados: item E, o Desfazer (é do toast) e o "Compartilhar" do protótipo (S-12).
        foreach (["op('Encaminhar via Chat", "'Desfazer'", "op('Compartilhar"] as $proibido) {
            self::assertStringNotContainsString($proibido, $js, "{$proibido} é de outro lote");
        }
        // Botão direito num item fora da seleção: ele vira a seleção (dc L4759); dentro dela, o menu é o de vários.
        self::assertStringContainsString("if (alvo && !selecao.has(chaveDe(alvo))) selecionar(chaveDe(alvo));", $js);
        self::assertStringContainsString("const multi = !!alvo && sel.length > 1 && selecao.has(chaveDe(alvo));", $js);
        // Montado a partir do <template>, sem HTML em string; limitado à janela.
        self::assertStringContainsString("el.menuItemTpl.content.firstElementChild.cloneNode(true)", $js);
        self::assertStringContainsString("el.menu.style.left = Math.max(8, Math.min(x, window.innerWidth - W - 8)) + 'px';", $js);
        self::assertStringContainsString("el.menu.style.top = Math.max(8, Math.min(y, window.innerHeight - H - 8)) + 'px';", $js);
        // O ⋮ abre o MESMO menu, no lugar do botão, com aria-expanded; Tab (ou Esc) fecha o menu.
        self::assertStringContainsString("abrirMenu(r.left, r.bottom + 2, itemPorChave(chaveDoElemento(linha)));", $js);
        self::assertStringContainsString("if (menuAberto()) { menuBotao = btn; btn.setAttribute('aria-expanded', 'true'); }", $js);
        self::assertStringContainsString("if (b && b.isConnected) { b.setAttribute('aria-expanded', 'false'); b.focus({ preventScroll: true }); }", $js);
        self::assertStringContainsString("'aria-haspopup': 'menu', 'aria-expanded': 'false'", $js);
        self::assertStringContainsString("if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); fecharMenu(); return; }", $js);
    }

    #[TestDox('"Copiar link" (S-12): a URL interna de visualização, absoluta, pela Clipboard API com fallback')]
    public function testCopiarLinkInterno(): void
    {
        $js = $this->js();

        self::assertStringContainsString("function linkAbsoluto(a) { return new URL(a.viewUrl, window.location.href).href; }", $js);
        self::assertStringContainsString("navigator.clipboard.writeText(t).then(function () { return true; }, function () { return copiarPorExec(t); });", $js);
        self::assertStringContainsString("ok = document.execCommand('copy');", $js);
        self::assertStringContainsString("toast(arqs.length === 1 ? 'Link copiado' : 'Links copiados')", $js);
        self::assertStringNotContainsString('/pastas/', $js, 'a URL fictícia do protótipo não vem para o sistema');
        // "Copiar caminho" (DOC-38): "BlueJus › Pasta <nup> › Documentos › … › nome".
        self::assertStringContainsString("return 'BlueJus › Pasta ' + cfg.pastaRotulo + ' › ' + caminhoLegivel(localDe(it)) + ' › ' + it.nome;", $js);
    }

    #[TestDox('lote (D4): mover e excluir vão em JSON para mover-lote/excluir-lote com o token csrfLote; nada de POST de formulário por documento')]
    public function testAcoesEmLoteUsamOsEndpointsDoL4(): void
    {
        $js = $this->js();

        self::assertStringContainsString("urlMoverLote:        dados.urlMoverLote || '',", $js);
        self::assertStringContainsString("urlExcluirLote:      dados.urlExcluirLote || '',", $js);
        self::assertStringContainsString("csrfLote:            dados.csrfLote || '',", $js);
        self::assertStringContainsString("postJson(cfg.urlMoverLote, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes, destinoId: destinoId })", $js);
        self::assertStringContainsString("postJson(cfg.urlExcluirLote, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes })", $js);
        self::assertMatchesRegularExpression("/headers: \{ 'Content-Type': 'application\/json', 'X-Requested-With': 'XMLHttpRequest' \},\s*body: JSON\.stringify\(corpo\)/", $js);
        // Arraste da seleção (nativo) e o soltar do Sortable caem no mesmo lote — e os dois levam
        // a SELEÇÃO inteira quando a linha arrastada está nela.
        self::assertStringContainsString("function soltarEm(chaves, destinoLinha) {\n        moverLote(chaves, Number(destinoLinha.dataset.pexId));", $js);
        self::assertStringContainsString("if (alvo && chaves.indexOf(chaveDoElemento(alvo)) === -1) soltarEm(chaves, alvo);", $js);
        self::assertStringContainsString("arrasteChaves = Array.from(selecao);", $js, 'o arraste nativo leva a SELEÇÃO, não só a linha');
        self::assertStringContainsString("arrasteSortable = selecao.has(chave) ? Array.from(selecao) : [chave];", $js, 'o Sortable (Manual) também');
        self::assertStringContainsString("soltarEm(chaves.filter(function (k) { return k !== alvoChave; }), destino);", $js);
        // No Manual o nome (<a>) inicia o arraste; só botões, campos e a linha provisória ficam de fora.
        self::assertStringContainsString("if (t.closest('button, input, .pex-ren') || alvo.dataset.pexTemp !== undefined) return true;", $js);
        // Recorte: consumido SÓ no sucesso do mover-lote; chaves que sumiram caem fora; excluir limpa.
        self::assertStringContainsString("moverLote(chaves, destinoId).then(function (ok) { if (ok) areaDeTransferencia = null; });", $js);
        self::assertStringContainsString("const chaves = areaDeTransferencia.chaves.filter(function (k) { return k !== 'pasta:' + destinoId && chaveExiste(k); });", $js);
        self::assertStringContainsString("limparRecorteDoQueNaoExiste();", $this->funcao('excluirItens'));
        self::assertStringContainsString("return true;\n        }).catch(function (err) { toastErro(err.message || 'Erro de comunicação.'); return false; });", $this->funcao('moverLote'));
        // Pasta→pasta (DOC-55): a tela barra o ciclo antes do pedido; o servidor confere de novo.
        self::assertStringContainsString("if (destinoId != null && lote.secoes.some(function (id) { return id === destinoId || descendentes(id).indexOf(destinoId) !== -1; })) {", $js);
        // O caminho antigo (formulário POST + reload por documento) saiu. O único submit de
        // formulário é o do .zip (L8), que baixa em outra aba e não recarrega nada.
        self::assertStringNotContainsString('urlExcluirDocTpl', $js);
        self::assertStringNotContainsString('form.submit()', $js);
        self::assertSame(1, substr_count($js, '.submit()'), 'só o formZip.submit() do .zip');
        self::assertStringContainsString('formZip.submit();', $this->funcao('baixarZip'));
        // Depois do sucesso a memória muda e a lista é refeita a partir dela — nunca otimista.
        $mover = $this->funcao('moverLote');
        self::assertLessThan(strpos($mover, 'a.secaoId = destinoId'), strpos($mover, "if (!res.ok || !res.j.ok) throw new Error"));
        self::assertStringContainsString('renderizar();', $mover);
        $excluir = $this->funcao('excluirItens');
        self::assertLessThan(strpos($excluir, 'arquivos.splice(i, 1)'), strpos($excluir, "if (!res.ok || !res.j.ok) throw new Error"));
        self::assertStringContainsString("toastErro(err.message || 'Erro de comunicação.')", $excluir, 'erro do servidor vira toast');
    }

    #[TestDox('teto de 2.000 itens por ação é respeitado no cliente, com mensagem, ANTES do confirm() e do pedido')]
    public function testTetoDoLote(): void
    {
        $js = $this->js();

        self::assertStringContainsString('const TETO_LOTE      = 2000;', $js, 'o mesmo de PastaDocumentoController::TETO_DE_ITENS_POR_LOTE');
        self::assertStringContainsString('if (n <= TETO_LOTE) return false;', $js);
        self::assertStringContainsString("toastErro('Seleção acima do limite de ' + TETO_LOTE.toLocaleString('pt-BR') + ' itens por ação. Selecione menos itens.');", $js);
        $excluir = $this->funcao('excluirItens');
        self::assertLessThan(strpos($excluir, 'confirm('), strpos($excluir, 'acimaDoTeto('), 'o teto vem antes do confirm');
        $mover = $this->funcao('moverLote');
        self::assertLessThan(strpos($mover, 'postJson('), strpos($mover, 'acimaDoTeto('), 'o teto vem antes do pedido');
    }

    #[TestDox('excluir mantém o confirm() (S-3) com a contagem da árvore — e, desde o L7, o aviso diz lixeira, não "não pode ser desfeita"; o toast ganha Desfazer')]
    public function testExcluirComConfirmEDesfazer(): void
    {
        $js = $this->js();

        self::assertStringContainsString('if (!confirm(avisoExclusaoDoLote(itens))) return;', $js);
        self::assertStringContainsString("return 'Excluir ' + itens.length + ' itens? Ao todo: ' + partes.join(' e ') + '.' + AVISO_LIXEIRA;", $js);
        self::assertStringContainsString("const AVISO_LIXEIRA = ' Os itens ficam ' + DIAS_NA_LIXEIRA + ' dias na lixeira e podem ser restaurados.';", $js);
        self::assertStringNotContainsString('não pode ser desfeita', $js, 'com a lixeira (L7) a frase deixou de ser verdade');
        self::assertStringContainsString("const texto = itens.length === 1 ? 'Excluído: ' + itens[0].nome : itens.length + ' itens excluídos';", $js);
        self::assertStringContainsString('toast(texto, false, desfazer);', $this->funcao('excluirItens'));
        // Toast do desenho: 4,2 s (dc L4772).
        self::assertStringContainsString('const TOAST_MS       = 4200;', $js);
        // Nenhum alert() no caminho normal: só como último recurso se o toast não existir.
        self::assertSame(1, substr_count($js, 'alert('), 'alert() só no fallback do toast');
        self::assertStringContainsString("if (!el.toast) { if (erro) alert(texto); return; }", $js);
    }

    #[TestDox('⋮ por linha só em (hover: none) (S-1): oculto por padrão no CSS, com a coluna de 32px entrando junto; toque longo de 500 ms abre o menu')]
    public function testMenuDaLinhaSoNoToque(): void
    {
        $css = $this->css();
        $js  = $this->js();

        self::assertStringContainsString(".pex-cel-acoes,\n.pex-col-acoes { display: none; }", $css);
        $ini = strpos($css, '@media (hover: none) {');
        self::assertNotFalse($ini);
        $bloco = substr($css, $ini, strpos($css, "\n}", $ini) - $ini);
        self::assertStringContainsString('.pex-cel-acoes { display: flex; }', $bloco);
        self::assertStringContainsString('.pex-col-acoes { display: block; }', $bloco);
        self::assertStringContainsString('grid-template-columns: var(--pex-gtc, minmax(140px, 1fr) 150px 90px 110px) 32px;', $bloco);
        // Sem o balão de link do iOS no toque longo; a linha não é texto selecionável.
        self::assertMatchesRegularExpression('/\n\.pex-item \{[^}]*user-select: none;[^}]*-webkit-touch-callout: none;/', $css);

        self::assertStringContainsString('const TOQUE_LONGO_MS = 500;', $js);
        self::assertStringContainsString("if (e.pointerType !== 'touch' && e.pointerType !== 'pen') return;", $js, 'toque longo só com dedo/caneta');
        self::assertStringContainsString("if (toqueInicio && Math.hypot(e.clientX - toqueInicio.x, e.clientY - toqueInicio.y) > 10) cancelarToqueLongo();", $js, 'rolar cancela');
        // O clique sintetizado depois do toque longo é engolido por FLAG — onde quer que caia (menu,
        // fundo ou lista), por captura no document. A flag é zerada por ele mesmo, por QUALQUER
        // pointerdown novo na página (dedo, caneta ou mouse: o menu fica fora da lista, e num
        // híbrido o próximo clique de mouse não pode sumir) e expira em 1 s.
        self::assertStringContainsString("suprimirProximoClique = true;\n                suprimirProximoCliqueAte = Date.now() + 1000;", $js);
        self::assertStringContainsString("document.addEventListener('pointerdown', function () { suprimirProximoClique = false; }, true);", $js);
        self::assertStringContainsString("document.addEventListener('click', function (e) {\n        if (!suprimirProximoClique) return;\n        suprimirProximoClique = false;\n        if (Date.now() > suprimirProximoCliqueAte) return;\n        e.preventDefault();\n        e.stopPropagation();\n    }, true);", $js);
        self::assertSame(3, substr_count($js, 'suprimirProximoClique = false;'), 'declaração + pointerdown de captura + clique engolido: nenhum outro ponto zera (o de el.lista, só touch/pen, saiu)');
        self::assertSame(1, substr_count($js, "function () { suprimirProximoClique = false; }"), 'pointerdown de captura, sem filtro de pointerType');
        self::assertStringNotContainsString('suprimirCliqueAte = Date.now() + 700', $js);
        // Sortable no toque: arrastar o dedo rola; só segurar 250 ms reordena.
        self::assertStringContainsString("delay: 250,\n            delayOnTouchOnly: true,", $js);
        // Colar sem nada que ainda exista: avisa em vez de silêncio.
        self::assertStringContainsString("if (!chaves.length) { areaDeTransferencia = null; toast('Nada para colar'); return; }", $js);
        // Toque longo no FUNDO da lista (e no vazio) abre o menu de fundo: o iOS não dispara contextmenu.
        self::assertStringContainsString("abrirMenu(t.x, t.y, t.linha ? itemPorChave(chaveDoElemento(t.linha)) : null);", $js);
        self::assertStringContainsString("ligarToqueLongo(el.lista);\n    if (el.vazio) ligarToqueLongo(el.vazio);", $js);
        // O ⋮ deixou de ser dropdown do Bootstrap.
        self::assertStringNotContainsString('data-bs-toggle', $js);
        self::assertStringNotContainsString('dropdown-item', $js);
    }

    #[TestDox('laço (dc L4874): só com mouse, a partir do espaço vazio; 5px para começar; retângulo com os tokens do desenho; rola sozinho a 40px da borda')]
    public function testLaco(): void
    {
        $js  = $this->js();
        $css = $this->css();

        self::assertStringContainsString("el.lista.addEventListener('mousedown', function (e) {", $js);
        self::assertStringContainsString("window.addEventListener('mousemove', mover);\n        window.addEventListener('mouseup', soltar);", $js, 'mouse, não pointer: no toque não existe laço');
        self::assertStringContainsString('if (!ativo && Math.hypot(ev.clientX - x0, ev.clientY - y0) < 5) return;', $js);
        self::assertStringContainsString('const LACO_MARGEM_PX = 40;', $js);
        self::assertStringContainsString("const base = (e.ctrlKey || e.metaKey) ? Array.from(selecao) : [];", $js, 'Ctrl soma à seleção');
        // Do ícone/nome arrasta; do espaço vazio laça — e o dragstart do espaço vazio é cancelado.
        self::assertStringContainsString('if (linha && (linha.dataset.pexTemp !== undefined || pontoNoConteudo(linha, e.clientX))) return;', $js);
        self::assertStringContainsString("if (lacoVazio || pressaoNaCaixa) { e.preventDefault(); return; }", $js);
        self::assertStringContainsString('return !pontoNoConteudo(alvo, evt.clientX);', $js, 'o Sortable (Manual) obedece à mesma regra pelo filter');
        self::assertStringContainsString('if (ativo) suprimirCliqueAte = Date.now() + 80;', $js);

        $claro = substr($css, strpos($css, "\n.pex {"), strpos($css, "\n}", strpos($css, "\n.pex {")) - strpos($css, "\n.pex {"));
        self::assertMatchesRegularExpression('/--pex-laco-bg:\s+rgba\(15,111,196,\.12\);/', $claro);
        self::assertMatchesRegularExpression('/--pex-laco-bd:\s+rgba\(15,111,196,\.6\);/', $claro);
        self::assertMatchesRegularExpression('/\.pex-laco \{[^}]*position: fixed;[^}]*pointer-events: none;[^}]*border-radius: 2px;/', $css);
    }

    #[TestDox('seleção não refaz a lista: troca classes nas linhas já no DOM (Map chave→linha) e refaz só barra e painel')]
    public function testSelecaoNaoReRenderiza(): void
    {
        $js = $this->js();

        foreach (['aplicarSelecao', 'selecionar', 'definirSelecao', 'alternarSelecao', 'selecionarIntervalo', 'selecionarTudo', 'limparSelecao', 'renderizarBarra'] as $f) {
            self::assertStringNotContainsString(' renderizar();', $this->funcao($f), "{$f} não pode refazer a lista");
        }
        self::assertStringContainsString('linhasPorChave.set(chaveDe(it), linha);', $js);
        self::assertStringContainsString("if (n.classList.contains('pex-item--sel') === on) return;", $js, 'só toca a linha que mudou');
        self::assertStringContainsString('function linhaDe(chave) { return linhasPorChave.get(chave) || null; }', $js);
        // Shift estende da ÂNCORA na ordem da tela; Ctrl alterna.
        self::assertStringContainsString('definirSelecao(chaves.slice(Math.min(a, b), Math.max(a, b) + 1), { foco: chave });', $js);
        self::assertStringContainsString('if (selecao.has(chave)) selecao.delete(chave); else selecao.add(chave);', $js);
        // Navegar limpa a seleção (dc: `expSelK: []`).
        foreach (['entrar', 'irParaNivel', 'aplicarBusca', 'definirFiltro'] as $f) {
            self::assertStringContainsString('limparSelecao(true);', $this->funcao($f), "{$f} limpa a seleção");
        }
    }

    #[TestDox('inline (DOC-53/54): nova pasta nasce como linha provisória em edição; renomear usa a rota de seção ou o editar em XHR preservando categoria/descrição/número')]
    public function testCriarERenomearInline(): void
    {
        $js = $this->js();

        self::assertStringContainsString("if (el.btnNovaPasta) el.btnNovaPasta.addEventListener('click', novaPastaInline);", $js);
        self::assertStringContainsString("linha.dataset.pexTemp = '';", $js, 'a linha provisória é marcada');
        self::assertStringContainsString("const nome = nomeLivre('Nova pasta');", $js);
        self::assertStringContainsString("iniciarRenomear({ tipo: 'pasta', id: 0, nome: nome, dado: temp }, { linha: linha, criar: true });", $js);
        // Enter salva, Esc cancela, perder o foco salva (dc L4941-4944).
        self::assertStringContainsString("if (e.key === 'Enter') { e.preventDefault(); salvarRenomear(); }", $js);
        self::assertStringContainsString("else if (e.key === 'Escape') { e.preventDefault(); cancelarRenomear(); }", $js);
        self::assertStringContainsString("input.addEventListener('blur', function () { salvarRenomear(); });", $js);
        // Vazio ou igual: nenhum pedido.
        self::assertStringContainsString("if (v === '' || (!r.criar && v === r.original)) { cancelarRenomear(); return; }", $js);
        // Arquivo: só o nome-base, pelo editar XHR (D3), levando os outros campos como estão.
        self::assertStringContainsString("_token: a.csrfEditar, nomeBase: v, categoria: a.categoria || '', descricao: a.descricao || '', numero: a.numero || '',", $js);
        self::assertStringContainsString('mesclarDocumento(a, res.j.documento);', $js, 'a linha é atualizada a partir do `documento` da resposta');
        // Pasta: a rota de seção de sempre.
        self::assertStringContainsString("postForm(p.urlRenomear, { _token: p.csrfRenomear, nome: v })", $js);
        // Campo do desenho (dc L2234): 24px, anel de 1,5px, 12,5px.
        self::assertMatchesRegularExpression('/\.pex-ren \{[^}]*height: 24px;[^}]*box-shadow: inset 0 0 0 1\.5px var\(--pex-ren-anel\);[^}]*font-size: 12\.5px;/', $this->css());
        // Enquanto edita, a linha não arrasta; e o modal de texto antigo não é mais usado.
        self::assertStringContainsString("linha.setAttribute('draggable', 'false');", $js);
        self::assertStringNotContainsString('pedirTexto', $js);
    }

    #[TestDox('upload (DOC-43): a linha nova entra a partir do `documento` da resposta, sem recarregar; o reload fica só como fallback')]
    public function testUploadInsereSemRecarregar(): void
    {
        $js = $this->js();

        self::assertStringContainsString("if (!d || !d.id || !d.viewUrl || !d.nome) return false;", $js);
        self::assertStringContainsString("if (inserirArquivoEnviado(data)) novos.push('arquivo:' + Number(data.documento.id));", $js);
        // Um render só, ao concluir o lote — não um por arquivo (derrubaria um campo inline aberto).
        self::assertSame(1, substr_count($this->funcao('enviarArquivos'), 'renderizar();'));
        self::assertStringNotContainsString('cancelarRenomear', $this->funcao('enviarArquivos'));
        // No upload, um único reload: o fallback. (O outro reload do arquivo é o `recarregarNaAba` da
        // lixeira, L7-UI — Desfazer que não bate com a memória e restaurar pelo modal.)
        $upload = $this->funcao('enviarArquivos');
        self::assertSame(1, substr_count($upload, 'window.location.reload()'), 'um único reload no upload, o fallback');
        self::assertLessThan(strpos($upload, 'window.location.reload()'), strpos($upload, 'if (precisaReload) {'));
        self::assertSame(2, substr_count($js, 'window.location.reload()'), 'só o fallback do upload e o recarregarNaAba da lixeira');
        self::assertStringContainsString("definirSelecao(novos, { ancora: novos[0], foco: novos[novos.length - 1] });", $js, 'os novos ficam selecionados');
        // O helper continua o mesmo (Peticionar e #uploadDuplicadosAviso intocados).
        self::assertStringContainsString('window.enviarArquivoComProgresso(file, {', $js);
    }

    #[TestDox('zero innerHTML, nenhuma chave nova de storage, e o menu/barra/toast vêm do markup estático')]
    public function testHigiene(): void
    {
        $js = $this->js();

        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringNotContainsString('outerHTML', $js);
        // A área de transferência (Recortar/Copiar) vive em memória; nada vai para o storage.
        self::assertStringNotContainsString('areaDeTransferencia', implode("\n", preg_grep('/Storage\./', explode("\n", $js))));
        foreach (['pexSelecao', 'pexMenu', 'pexMenuFundo', 'pexMenuItem', 'pexToast', 'pexToastDesfazer', 'pexLixeiraAbrir', 'pexLaco'] as $id) {
            self::assertStringContainsString("document.getElementById('{$id}')", $js, "#{$id} é estático no template");
        }
    }
}
