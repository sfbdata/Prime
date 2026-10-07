<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Caixas de seleção nos itens do explorador da aba Documentos (pedido do dono, 07/10/2026; o
 * desenho 02 - EXPEDIENTES 1.2.3 é omisso — não há checkbox no explorador do dc).
 *
 * Teste de FOLHA (precedente: PastaExploradorInteracaoTest / FavoritosTest): sem harness de
 * navegador na suíte, o que dá para travar é que a caixa NÃO é um segundo mecanismo — ela escreve
 * na mesma `selecao` (alternarSelecao / selecionarIntervalo) e é repintada a partir dela
 * (aplicarSelecao), o "selecionar todos" conta só `itensRenderizados` (o que a busca, o filtro e
 * o nível deixaram na tela), e o clique/teclado/arraste/toque longo na caixa não abrem o item.
 * O comportamento real (clique, toque, foco, aparência) é do smoke no navegador.
 */
final class PastaExploradorCaixaSelecaoTest extends TestCase
{
    private const JS   = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const CSS  = __DIR__ . '/../../../public/css/pasta-explorador.css';
    private const TWIG = __DIR__ . '/../../../templates/pasta/_documentos_explorador.html.twig';

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

    /** Trecho do handler `el.lista.addEventListener('<evento>'` até o `});` dele. */
    private function handlerDaLista(string $evento): string
    {
        $js  = $this->js();
        $ini = strpos($js, "el.lista.addEventListener('" . $evento . "', function (e) {");
        self::assertNotFalse($ini, "handler {$evento} da lista não encontrado");

        return substr($js, $ini, (int) strpos($js, "\n    });", $ini) - $ini);
    }

    /** O ramo da caixa dentro do clique da lista. */
    private function ramoDaCaixaNoClique(): string
    {
        $clique = $this->handlerDaLista('click');
        $ini    = strpos($clique, "if (e.target.closest('.pex-chk') && item) {");
        self::assertNotFalse($ini, 'o clique da lista trata a caixa');

        return substr($clique, $ini, (int) strpos($clique, "\n        }", $ini) - $ini);
    }

    #[TestDox('a caixa é um <input type=checkbox> real, "Selecionar <nome>", fora da ordem de Tab, nasce marcada pela selecao e fica antes da estrela em toda linha/cartão (menos a provisória)')]
    public function testCaixaDaLinha(): void
    {
        $caixa = $this->funcao('caixaDeSelecao');
        self::assertStringContainsString("h('input', { type: 'checkbox', class: 'pex-chk-in', tabindex: '-1', 'aria-label': 'Selecionar ' + nome, draggable: 'false' });", $caixa);
        self::assertStringContainsString('input.checked = !!on;', $caixa);
        self::assertStringContainsString("h('span', { class: 'pex-chk-caixa', 'aria-hidden': 'true' })", $caixa, 'quem desenha é o span; o input é o alvo');
        self::assertStringNotContainsString('innerHTML', $caixa, 'o nome entra por setAttribute, nunca por HTML');

        $montar = $this->funcao('montarItem');
        self::assertStringContainsString('const on = selecao.has(chaveDaLinha);', $montar, 'o estado inicial vem da MESMA seleção');
        $posCaixa = strpos($montar, 'favorito === null ? null : caixaDeSelecao(on, nomeEl.textContent),');
        $posFav   = strpos($montar, 'favorito === null ? null : botaoFavorito(');
        self::assertNotFalse($posCaixa, 'linha provisória (nova pasta) não tem caixa');
        self::assertNotFalse($posFav);
        self::assertLessThan($posFav, $posCaixa, 'a caixa vem antes da estrela, na célula do nome — sem coluna própria');
        self::assertStringContainsString("if (on) attrs.class += ' pex-item--sel';", $montar);
    }

    #[TestDox('sincronização: aplicarSelecao repinta a caixa de cada linha a partir da selecao (clique, Ctrl, Shift, teclado, laço, menu passam todos por ela) e o render refaz o mapa das caixas')]
    public function testCaixaEspelhaASelecao(): void
    {
        $aplicar = $this->funcao('aplicarSelecao');
        self::assertStringContainsString('const caixa = caixasPorChave.get(chave);', $aplicar);
        self::assertStringContainsString('if (caixa && caixa.checked !== on) caixa.checked = on;', $aplicar);
        // A repintura da caixa vem ANTES do atalho "classe já está certa" — senão uma caixa
        // desalinhada nunca voltaria.
        self::assertLessThan(
            strpos($aplicar, "if (n.classList.contains('pex-item--sel') === on) return;"),
            strpos($aplicar, 'if (caixa && caixa.checked !== on) caixa.checked = on;')
        );
        self::assertStringContainsString('atualizarCaixasTodos();', $aplicar);

        // Todos os caminhos de seleção desembocam em aplicarSelecao.
        self::assertStringContainsString('aplicarSelecao();', $this->funcao('selecionar'));
        self::assertStringContainsString('aplicarSelecao();', $this->funcao('depoisDaSelecao'));
        self::assertStringContainsString('depoisDaSelecao();', $this->funcao('definirSelecao'));
        self::assertStringContainsString('depoisDaSelecao();', $this->funcao('alternarSelecao'));
        $js = $this->js();
        self::assertStringContainsString('definirSelecao(chaves.slice(Math.min(a, n), Math.max(a, n) + 1), { ancora: chaves[a], foco: nk });', $js, 'Shift+setas');
        self::assertStringContainsString('if (nova !== assinatura) { assinatura = nova; definirSelecao(chaves, { foco: chaves.length ? chaves[chaves.length - 1] : null }); }', $js, 'laço');
        self::assertStringContainsString('if (alvo && !selecao.has(chaveDe(alvo))) selecionar(chaveDe(alvo));', $this->funcao('abrirMenu'), 'menu de contexto');

        $render = $this->funcao('renderizar');
        self::assertStringContainsString('caixasPorChave = new Map();', $render);
        self::assertStringContainsString("const caixa = linha.querySelector('.pex-chk-in');", $render);
        self::assertStringContainsString('if (caixa) caixasPorChave.set(chaveDe(it), caixa);', $render);
        self::assertStringContainsString('atualizarCaixasTodos();', $render, 're-render preserva o estado das caixas');
        // Itens que saíram da tela (lixeira, busca, filtro) saem da seleção no render.
        self::assertStringContainsString('Array.from(selecao).forEach(function (k) { if (!vivas[k]) selecao.delete(k); });', $render);
    }

    #[TestDox('clique na caixa: alterna como Ctrl+clique, Shift estende da âncora, não abre/entra/visualiza, não conta para o duplo clique e é tratado antes de qualquer outra decisão da linha')]
    public function testCliqueNaCaixa(): void
    {
        $ramo = $this->ramoDaCaixaNoClique();
        self::assertStringContainsString('e.stopPropagation();', $ramo);
        self::assertStringContainsString('registrarClique(chaveDaCaixa, true);', $ramo, 'o clique na caixa fica marcado como clique de caixa');
        self::assertStringContainsString('if (e.shiftKey && ancora) selecionarIntervalo(chaveDaCaixa);', $ramo);
        self::assertStringContainsString('else alternarSelecao(chaveDaCaixa);', $ramo);
        self::assertStringContainsString('e.target.checked = selecao.has(chaveDaCaixa);', $ramo, 'o input termina igual à seleção (Shift numa caixa marcada)');
        self::assertStringContainsString('return;', $ramo);
        foreach (['abrirItem', 'entrar(', 'abrirPreview', 'selecionar(chave'] as $proibido) {
            self::assertStringNotContainsString($proibido, $ramo, "a caixa não chama {$proibido}");
        }
        // Não cancela o clique do input (preventDefault desfaria o ✓ nativo) — só fora dele.
        self::assertStringContainsString("if (e.target.tagName !== 'INPUT') { e.preventDefault(); return; }", $ramo);

        $clique = $this->handlerDaLista('click');
        $posCaixa = strpos($clique, "if (e.target.closest('.pex-chk') && item) {");
        foreach ([
            "const btnFav = e.target.closest('.pex-fav');",
            "const prev = e.target.closest('.pex-arq-preview');",
            'registrarClique(item ? chaveDoElemento(item) : null);',
            "if (ehToque() && item.dataset.pexTipo === 'pasta') { entrar(Number(item.dataset.pexId)); return; }",
        ] as $depois) {
            $pos = strpos($clique, $depois);
            self::assertNotFalse($pos, $depois);
            self::assertLessThan($pos, $posCaixa, 'a caixa decide antes: no toque ela não entra na pasta');
        }

        self::assertStringContainsString("if (e.target.closest('.pex-ren, .pex-menu, .pex-fav, .pex-chk')) return;", $this->handlerDaLista('dblclick'), 'dois cliques rápidos na caixa não abrem o item');
    }

    #[TestDox('duplo clique cujo 1º clique foi numa caixa (o 2º caiu no vizinho empurrado pela barra) não abre NADA — nem o vizinho, nem o item da caixa')]
    public function testDuploCliqueComPrimeiroNaCaixaNaoAbre(): void
    {
        self::assertStringContainsString('ultimoClique = { chave: chave, t: Date.now(), caixa: !!caixa };', $this->funcao('registrarClique'));

        $dbl = $this->handlerDaLista('dblclick');
        $guarda = strpos($dbl, 'const primeiroNaCaixa = !!(cliqueAnterior && cliqueAnterior.caixa && ultimoClique && ultimoClique.t - cliqueAnterior.t < DUPLO_CLIQUE_MS);');
        $decide = strpos($dbl, 'const chave = primeiroNaCaixa ? null : chaveDoDuploClique(chaveDoAlvo);');
        $sai    = strpos($dbl, 'if (!chave) return;');
        $abre   = strpos($dbl, 'abrirItem(itemPorChave(chave));');
        foreach ([$guarda, $decide, $sai, $abre] as $pos) {
            self::assertNotFalse($pos);
        }
        self::assertLessThan($decide, $guarda);
        self::assertLessThan($sai, $decide);
        self::assertLessThan($abre, $sai, 'sem chave, nada abre');
        self::assertStringNotContainsString('const chave = chaveDoDuploClique(chaveDoAlvo);', $dbl, 'a chave não pode vir direto do par de cliques');
    }

    #[TestDox('re-render com foco numa peça da linha (caixa, estrela) que sumiu devolve o foco à lista, não ao <body>')]
    public function testRenderDevolveOFocoALista(): void
    {
        $render = $this->funcao('renderizar');
        $antes  = strpos($render, 'const focoNaLinha = !!(focado && focado !== el.lista && el.lista.contains(focado));');
        $troca  = strpos($render, "el.lista.textContent = '';");
        $volta  = strpos($render, 'if (focoNaLinha && !focado.isConnected) el.lista.focus({ preventScroll: true });');
        self::assertNotFalse($antes);
        self::assertNotFalse($troca);
        self::assertNotFalse($volta);
        self::assertLessThan($troca, $antes, 'o foco é lido ANTES de a lista ser esvaziada');
        self::assertLessThan($volta, $troca);
        self::assertLessThan(strpos($render, 'if (renomeando) cancelarRenomear(true);'), strpos($render, 'const focado = document.activeElement;'), 'antes até de cancelar o renomear');
    }

    #[TestDox('teclado: com foco na caixa da linha, Espaço é dela (alterna) e Enter não abre; setas devolvem o foco à lista; a caixa "todos" segue como campo')]
    public function testTecladoNaCaixa(): void
    {
        $js = $this->js();
        $ini = strpos($js, "raiz.addEventListener('keydown', function (e) {");
        self::assertNotFalse($ini);
        $handler = substr($js, $ini, (int) strpos($js, "\n    });", $ini) - $ini);

        self::assertStringContainsString("const naCaixa = tag === 'INPUT' && !!(e.target.classList && e.target.classList.contains('pex-chk-in') && e.target.closest('.pex-item'));", $handler);
        $saida = strpos($handler, "if (naCaixa && (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Enter')) return;");
        self::assertNotFalse($saida);
        foreach (['abrirItem(alvo)', 'abrirPreviewDe(alvo.dado)', "if (ctrl && baixa === 'a')"] as $acao) {
            $pos = strpos($handler, $acao);
            self::assertNotFalse($pos, $acao);
            self::assertLessThan($pos, $saida, 'Espaço/Enter na caixa saem antes de abrir/visualizar');
        }
        self::assertStringContainsString("if (!naCaixa && (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable))) return;", $handler, 'os demais campos continuam fora dos atalhos');
        self::assertStringContainsString('if (naCaixa) el.lista.focus({ preventScroll: true });', $handler);
    }

    #[TestDox('selecionar todos os VISÍVEIS: conta e marca só itensRenderizados; indeterminado com parte (indeterminate nativo, sem aria-checked); desliga sem itens; desmarca só os visíveis')]
    public function testSelecionarTodosVisiveis(): void
    {
        $atualizar = $this->funcao('atualizarCaixasTodos');
        self::assertStringContainsString('const total = itensRenderizados.length;', $atualizar);
        self::assertStringContainsString('itensRenderizados.forEach(function (it) { if (selecao.has(chaveDe(it))) n++; });', $atualizar);
        self::assertStringContainsString('const todos = total > 0 && n === total;', $atualizar);
        self::assertStringContainsString('const parte = n > 0 && n < total;', $atualizar);
        self::assertStringContainsString('c.checked = todos;', $atualizar);
        self::assertStringContainsString('c.indeterminate = parte;', $atualizar);
        self::assertStringContainsString('c.disabled = total === 0;', $atualizar);
        self::assertStringNotContainsString('aria-checked', $atualizar, 'checkbox nativo: indeterminate já expõe "mixed"');
        self::assertStringContainsString('[el.todosCab, el.todosFaixa].forEach(', $atualizar, 'as duas caixas (Detalhes e demais modos) mostram o mesmo estado');

        $alternar = $this->funcao('alternarTodosVisiveis');
        self::assertStringContainsString('const chaves = itensRenderizados.map(chaveDe);', $alternar);
        self::assertStringContainsString('else definirSelecao(chaves, {', $alternar);
        self::assertStringContainsString('if (todos) definirSelecao(Array.from(selecao).filter(function (k) { return !visiveis.has(k); }), { ancora: null, foco: null });', $alternar);
        // Nada do conjunto completo de dados: filtro/busca escondem, a caixa não alcança.
        foreach (['arquivos', 'pastas', 'itensVisiveis('] as $proibido) {
            self::assertStringNotContainsString($proibido, $alternar);
        }
        // itensRenderizados É a lista já filtrada da tela.
        self::assertStringContainsString('const itens = itensVisiveis();', $this->funcao('renderizar'));
        self::assertStringContainsString('itensRenderizados = itens;', $this->funcao('renderizar'));
        self::assertStringContainsString("[el.todosCab, el.todosFaixa].forEach(function (c) { if (c) c.addEventListener('change', alternarTodosVisiveis); });", $this->js());

        // A faixa do "todos" só nos modos sem cabeçalho de colunas, e só com itens.
        self::assertStringContainsString("if (el.todos) el.todos.hidden = modo === 'det' || itens.length === 0;", $this->funcao('renderizar'));
    }

    #[TestDox('lotes: barra, menu, copiar, ZIP, excluir e mover leem a mesma selecao que a caixa escreve — não há segundo estado')]
    public function testLotesUsamAMesmaSelecao(): void
    {
        $js = $this->js();
        self::assertStringContainsString('return itensRenderizados.filter(function (it) { return selecao.has(chaveDe(it)); });', $this->funcao('itensSelecionados'));
        self::assertStringContainsString("if (selecao.has(chave)) selecao.delete(chave); else selecao.add(chave);", $this->funcao('alternarSelecao'), 'a caixa escreve AQUI');
        foreach (["case 'baixar':   baixarSelecao(sel); break;", "case 'copiar':   copiar(sel); break;", "case 'excluir':  excluirItens(sel); break;"] as $acao) {
            self::assertStringContainsString($acao, $js);
        }
        self::assertStringContainsString("op('Baixar como .zip (' + sel.length + ')', 'bi-file-earmark-zip', function () { baixarZip(sel); })", $js);
        self::assertStringContainsString("op('Excluir ' + sel.length + ' itens', 'bi-trash3', function () { excluirItens(sel); }", $js);
        // Nenhum outro conjunto de "marcados" no explorador além da limpeza (que tem lista própria).
        self::assertSame(1, substr_count($js, 'const selecao = new Set();'));
        self::assertStringNotContainsString('marcadosPelaCaixa', $js);
    }

    #[TestDox('arrastar e toque longo: pressão na caixa não arrasta a linha (nativo e Sortable) nem abre o menu por toque longo; o laço ignora o input')]
    public function testArrasteEToqueLongoNaCaixa(): void
    {
        $js = $this->js();
        self::assertStringContainsString("pressaoNaCaixa = !!e.target.closest('.pex-chk');", $this->handlerDaLista('mousedown'));
        self::assertStringContainsString("if (e.target.closest('input, button, a, .pex-ren, .pex-menu')) return;", $this->handlerDaLista('mousedown'), 'o laço não nasce no input');
        self::assertStringContainsString('if (lacoVazio || pressaoNaCaixa) { e.preventDefault(); return; }', $this->handlerDaLista('dragstart'));
        self::assertStringContainsString("if (t.closest('button, input, .pex-ren') || alvo.dataset.pexTemp !== undefined) return true;", $js, 'o filter do Sortable já recusa input');
        self::assertStringContainsString("e.target.closest('.pex-ren, .pex-menu, .pex-fav, .pex-chk')) return;\n            cancelarToqueLongo();", $js);
    }

    #[TestDox('CSS: input transparente cobrindo a caixa; alvo de 32px no toque/estreito sem largura fixa na linha; marcada/indeterminada/foco visíveis; grade no canto direito (estrela no esquerdo); par escuro para cada cor nova')]
    public function testCss(): void
    {
        $css = $this->css();

        self::assertStringContainsString('.pex-chk { position: relative; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; }', $css);
        self::assertStringContainsString('.pex-chk-in { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; padding: 0; opacity: 0; cursor: pointer; z-index: 1; }', $css, 'o alvo é o input inteiro, do tamanho da peça');
        self::assertMatchesRegularExpression('/@media \(max-width: 767\.98px\), \(hover: none\) \{\s*\.pex-chk \{ width: 32px; height: 32px; \}\s*\}/', $css, 'alvo de toque ≥ 32px');
        self::assertStringContainsString('.pex-chk-in:checked + .pex-chk-caixa::after', $css);
        self::assertStringContainsString('.pex-chk-in:indeterminate + .pex-chk-caixa::after', $css);
        self::assertStringContainsString('.pex-chk-in:focus-visible + .pex-chk-caixa { box-shadow: 0 0 0 2px var(--pex-busca-anel); }', $css, 'foco visível');

        // Grade: a caixa no canto DIREITO; a estrela segue no esquerdo; o ⋮ do toque desce.
        self::assertStringContainsString('.pex--grade .pex-chk { position: absolute; top: 5px; right: 5px; z-index: 2; }', $css);
        self::assertStringContainsString('.pex--grade .pex-fav { position: absolute; top: 4px; left: 4px; z-index: 2; }', $css);
        self::assertMatchesRegularExpression('/@media \(hover: none\) \{\s*\.pex--grade \.pex-cel-acoes \{ top: 34px; \}\s*\}/', $css);

        // Sem largura fixa que force rolagem a 375px: nem a faixa nem a peça pedem mais que 32px.
        $faixa = substr($css, (int) strpos($css, "\n.pex-todos {"), 400);
        self::assertStringNotContainsString('width:', preg_replace('/min-width:\s*0/', '', $faixa) ?? '');
        self::assertStringContainsString('.pex-todos[hidden] { display: none; }', $css);

        // Cada cor nova tem par no escuro.
        $claro  = substr($css, (int) strpos($css, "\n.pex {"), (int) strpos($css, "\n}", (int) strpos($css, "\n.pex {")) - (int) strpos($css, "\n.pex {"));
        $iniEsc = (int) strpos($css, '[data-bs-theme="dark"] .pex,');
        $escuro = substr($css, $iniEsc, (int) strpos($css, "\n}", $iniEsc) - $iniEsc);
        foreach (['--pex-chk-bd', '--pex-chk-bg', '--pex-chk-on', '--pex-chk-marca'] as $token) {
            self::assertStringContainsString($token . ':', $claro, "{$token} no claro");
            self::assertStringContainsString($token . ':', $escuro, "{$token} no escuro");
        }
    }

    #[TestDox('template: caixa "todos" no cabeçalho do Detalhes (coluna Nome) e na faixa dos demais modos, ambas checkbox real com aria-label e nascendo desligadas')]
    public function testTemplate(): void
    {
        $twig = (string) file_get_contents(self::TWIG);
        self::assertStringContainsString('<div class="pex-col pex-col-nome" aria-sort="none"><span class="pex-chk pex-chk--todos"><input type="checkbox" class="pex-chk-in pex-chk-todos" id="pexTodosCab" aria-label="Selecionar todos os itens visíveis" disabled>', $twig);
        self::assertStringContainsString('<input type="checkbox" class="pex-chk-in pex-chk-todos" id="pexTodosFaixa" aria-label="Selecionar todos os itens visíveis" disabled>', $twig);
        self::assertStringContainsString('<label class="pex-todos-txt" for="pexTodosFaixa">Selecionar todos</label>', $twig);
    }
}
