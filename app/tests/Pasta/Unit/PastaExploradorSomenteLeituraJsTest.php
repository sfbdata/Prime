<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * DOC-118 — com `podeEditar` falso no `#pexDados` (só VER a pasta, ou pasta na lápide), o JS do
 * explorador e o do checklist NÃO desenham nem ligam ação de escrita. Leitura, busca, visualizar,
 * .zip e favoritos continuam.
 *
 * Teste de FOLHA (precedente: PastaExploradorInteracaoTest): não há harness de navegador na
 * suíte. O que dá para travar é que cada guarda existe e vem ANTES do `addEventListener` /
 * `new Sortable` / atalho que ela protege — a ordem é o que garante "nada é ligado".
 */
final class PastaExploradorSomenteLeituraJsTest extends TestCase
{
    use TextoDosScriptsDaPasta;

    private const JS        = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const CHECKLIST = __DIR__ . '/../../../public/js/pasta-checklist.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
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

    private function posicao(string $trecho, int $desde = 0): int
    {
        $p = strpos($this->js(), $trecho, $desde);
        self::assertNotFalse($p, "trecho ausente: {$trecho}");

        return $p;
    }

    #[TestDox('a flag vem do #pexDados, estrita: só `true` libera escrita (ausente = só leitura)')]
    public function testFlag(): void
    {
        self::assertStringContainsString('podeEditar:          dados.podeEditar === true,', $this->js());
    }

    #[TestDox('só leitura: Nova pasta, Anexar, o seletor, a faixa de limpeza e Copiar/Recortar/Renomear/Excluir da barra saem do DOM ANTES de qualquer listener deles')]
    public function testPecasDeEscritaSaemAntesDosListeners(): void
    {
        $bloco = $this->posicao('    if (!cfg.podeEditar) {');
        $js    = $this->js();
        $fim   = strpos($js, "\n    }\n", $bloco);
        $corpo = substr($js, $bloco, $fim - $bloco);

        self::assertStringContainsString('[el.btnNovaPasta, el.btnAnexar, el.fileInput, el.limpeza].forEach(function (n) { if (n) n.remove(); });', $corpo);
        self::assertStringContainsString('el.btnNovaPasta = el.btnAnexar = el.fileInput = el.limpeza = null;', $corpo);
        self::assertStringContainsString('[data-pex-sel="copiar"], [data-pex-sel="recortar"], [data-pex-sel="renomear"], [data-pex-sel="excluir"]', $corpo);

        foreach ([
            "if (el.btnNovaPasta) el.btnNovaPasta.addEventListener('click', novaPastaInline);",
            "el.btnAnexar.addEventListener('click', function () { el.fileInput.click(); });",
            "el.limpezaRevisar.addEventListener('click', function () {",
            "el.limpezaExcluir.addEventListener('click', function () {",
            "el.selecao.addEventListener('click', function (e) {",
        ] as $listener) {
            self::assertLessThan($this->posicao($listener), $bloco, "a remoção vem antes de: {$listener}");
        }
        // Os listeners que sobram dependem do elemento existir — com ele nulo, nada é ligado.
        self::assertStringContainsString('    if (el.btnAnexar && el.fileInput) {', $js);
        self::assertStringContainsString('    if (el.limpeza) {', $js);
        self::assertStringContainsString("if (!el.limpeza) return;", $this->funcao('renderizarLimpeza'));
        // Na barra, o que saiu do DOM não é mais procurado sem guarda.
        $barra = $this->funcao('renderizarBarra');
        self::assertStringContainsString('if (copiar) {', $barra);
        self::assertStringContainsString('if (renomear) renomear.hidden = sel.length !== 1;', $barra);
    }

    #[TestDox('só leitura: Del, F2 e Ctrl+X/C/V voltam ANTES dos atalhos de escrita; Ctrl+A, Enter, Espaço e setas seguem')]
    public function testAtalhos(): void
    {
        $guarda = $this->posicao("if (!cfg.podeEditar && (k === 'Delete' || k === 'F2' || (ctrl && (baixa === 'x' || baixa === 'c' || baixa === 'v')))) return;");

        self::assertLessThan($guarda, $this->posicao('if (!noAlvo) return;'), 'só com o foco na lista, como os outros');
        foreach ([
            "if (k === 'Delete') { if (sel.length) { e.preventDefault(); excluirItens(sel); } return; }",
            "if (k === 'F2') { if (sel.length === 1) { e.preventDefault(); iniciarRenomear(sel[0]); } return; }",
            "if (ctrl && baixa === 'x') { if (sel.length) { e.preventDefault(); recortar(sel); } return; }",
            "if (ctrl && baixa === 'c') { if (sel.length) { e.preventDefault(); copiar(sel); } return; }",
            "if (ctrl && baixa === 'v') { if (areaDeTransferencia) { e.preventDefault(); colarAqui(); } return; }",
        ] as $atalho) {
            self::assertGreaterThan($guarda, $this->posicao($atalho), "a guarda vem antes de: {$atalho}");
        }
        // Leitura: Ctrl+A vem antes da guarda e não é afetado por ela.
        self::assertLessThan($guarda, $this->posicao("if (ctrl && baixa === 'a') { e.preventDefault(); selecionarTudo(); return; }"));
    }

    /**
     * DOC-118b: as funções que ESCREVEM. Um `op(` cuja ação chame qualquer uma delas é de escrita.
     * A lista é conferida contra o próprio JS (cada nome tem de existir como `function`), para não
     * envelhecer em silêncio.
     */
    private const FUNCOES_DE_ESCRITA = [
        'excluirItens', 'iniciarRenomear', 'salvarRenomear', 'recortar', 'copiar', 'colarEm', 'colarAqui',
        'moverLote', 'copiarLote', 'escolherDestino', 'novaPastaInline', 'criarPasta', 'abrirEditar',
        'enviarArquivos', 'restaurarDaLixeira',
    ];

    /**
     * Todas as chamadas `op(…)` do JS (fora a definição), com os parênteses balanceados e as
     * strings puladas — `'Baixar como .zip (' + sel.length + ')'` não engana a contagem.
     *
     * @return list<string>
     */
    private function chamadasDeOp(): array
    {
        $js = $this->js();
        preg_match_all('/(?<![\w$.])op\(/', $js, $m, PREG_OFFSET_CAPTURE);
        $chamadas = [];
        foreach ($m[0] as [, $ini]) {
            if (substr($js, max(0, $ini - 9), 9) === 'function ') {
                continue;
            }
            $nivel = 0;
            $aspa  = null;
            for ($i = $ini + 2, $n = strlen($js); $i < $n; ++$i) {
                $c = $js[$i];
                if ($aspa !== null) {
                    if ($c === '\\') {
                        ++$i;
                    } elseif ($c === $aspa) {
                        $aspa = null;
                    }
                    continue;
                }
                if ($c === "'" || $c === '"' || $c === '`') {
                    $aspa = $c;
                } elseif ($c === '(') {
                    ++$nivel;
                } elseif ($c === ')' && --$nivel === 0) {
                    break;
                }
            }
            self::assertSame(0, $nivel, 'op( sem fechamento em ' . $ini);
            $chamadas[] = substr($js, $ini, $i - $ini + 1);
        }

        return $chamadas;
    }

    #[TestDox('só leitura (DOC-118b): o menu filtra pela marca `escrita` da origem, não por rótulo; todo op( que chama função de escrita tem `escrita: true` e nenhum de leitura tem')]
    public function testMenu(): void
    {
        $js = $this->js();
        // A marca é lida estrita no `op()` e o filtro usa só ela; a lista de rótulos acabou.
        self::assertStringContainsString("escrita: extra.escrita === true };", $this->funcao('op'));
        self::assertStringContainsString('opcoesDoMenu(alvo).filter(function (o) { return cfg.podeEditar || o === SEP || !o.escrita; }).forEach(function (o) {', $this->funcao('abrirMenu'));
        self::assertStringNotContainsString('ROTULOS_DE_ESCRITA', $js);
        self::assertStringNotContainsString('ehOpDeEscrita', $js);

        foreach (self::FUNCOES_DE_ESCRITA as $nome) {
            self::assertStringContainsString('    function ' . $nome . '(', $js, "função de escrita que não existe mais: {$nome}");
        }
        $chamaEscrita = '/\b(?:' . implode('|', self::FUNCOES_DE_ESCRITA) . ')\b/';

        $deEscrita = [];
        $deLeitura = [];
        foreach ($this->chamadasDeOp() as $chamada) {
            if (preg_match($chamaEscrita, $chamada) === 1) {
                self::assertStringContainsString('escrita: true', $chamada, "ação de escrita sem a marca: {$chamada}");
                $deEscrita[] = $chamada;
            } else {
                self::assertStringNotContainsString('escrita', $chamada, "ação de leitura marcada como escrita: {$chamada}");
                $deLeitura[] = $chamada;
            }
        }

        // Não vazio: as 12 de escrita de hoje (fundo 2, vários 4, um item 6 — Copiar mora no opCopiar)
        // e as de leitura que o só leitor tem de continuar vendo.
        self::assertCount(12, $deEscrita, 'ação de escrita nova? marque-a e ajuste a conta');
        foreach (["BJ_T.t('pasta_scripts.js.explorador.menu_nova_pasta')", "BJ_T.t('pasta_scripts.js.explorador.menu_colar')", "BJ_T.t('pasta_scripts.js.explorador.menu_recortar')", "BJ_T.t('pasta_scripts.js.explorador.menu_copiar')", "BJ_T.t('pasta_scripts.js.explorador.menu_mover_para')", "BJ_T.t('pasta_scripts.js.explorador.menu_renomear')", "BJ_T.t('pasta_scripts.js.explorador.menu_editar')", "BJ_T.t('pasta_scripts.js.explorador.menu_excluir')", "BJ_T.t('pasta_scripts.js.explorador.menu_excluir_n'"] as $rotulo) {
            self::assertNotEmpty(array_filter($deEscrita, static fn (string $c): bool => str_starts_with($c, 'op(' . $rotulo)), "escrita sem marca ou fora do menu: {$rotulo}");
        }
        foreach (["BJ_T.t('pasta_scripts.js.explorador.menu_abrir')", "BJ_T.t('pasta_scripts.js.explorador.menu_visualizar')", "BJ_T.t('pasta_scripts.js.explorador.baixar')", "BJ_T.t('pasta_scripts.js.explorador.menu_baixar_zip')", "BJ_T.t('pasta_scripts.js.explorador.menu_baixar_zip_n'", "BJ_T.t('pasta_scripts.js.explorador.menu_copiar_link')", 'arqs.length < sel.length ?', "BJ_T.t('pasta_scripts.js.explorador.menu_copiar_caminho')", "BJ_T.t('pasta_scripts.js.explorador.menu_copiar_caminhos')", "BJ_T.t('pasta_scripts.js.explorador.menu_propriedades')", "BJ_T.t('pasta_scripts.js.explorador.menu_selecionar_tudo')", 'rotulo,', 'rotuloLixeira()', 'par[1]', 'painel ?'] as $rotulo) {
            self::assertNotEmpty(array_filter($deLeitura, static fn (string $c): bool => str_starts_with($c, 'op(' . $rotulo)), "leitura que sumiria do só leitor: {$rotulo}");
        }
    }

    #[TestDox('só leitura: sem Sortable, sem arraste nativo (linha sem draggable, dragstart cancelado) e sem upload por soltar')]
    public function testArrasteEUpload(): void
    {
        $sortable = $this->funcao('ligarSortable');
        self::assertLessThan(strpos($sortable, 'new Sortable('), strpos($sortable, 'if (!cfg.podeEditar) return;'));

        $render = $this->funcao('renderizar');
        self::assertStringContainsString("if (!cfg.podeEditar) linha.removeAttribute('draggable');", $render);

        $js        = $this->js();
        $dragstart = $this->posicao("el.lista.addEventListener('dragstart', function (e) {");
        $guarda    = $this->posicao('if (!cfg.podeEditar) { e.preventDefault(); return; }', $dragstart);
        self::assertLessThan($this->posicao('arrasteChaves = Array.from(selecao);', $dragstart), $guarda);

        self::assertStringContainsString('    if (el.corpo && cfg.podeEditar) {', $js, 'o soltar arquivos do computador nem é ligado');
        self::assertLessThan(
            $this->posicao("el.corpo.addEventListener('drop', function (e) {"),
            $this->posicao('    if (el.corpo && cfg.podeEditar) {'),
        );
    }

    #[TestDox('só leitura (DOC-118c): a lixeira só é oferecida com `podeVerLixeira` do servidor (a permissão do GET dela) — na lápide, quem só vê não a vê; quem edita ainda olha')]
    public function testLixeira(): void
    {
        $js = $this->js();
        self::assertStringContainsString('podeVerLixeira:      dados.podeVerLixeira === true,', $js, 'estrita: ausente = sem lixeira');
        self::assertStringContainsString('function veLixeira() { return !!cfg.urlLixeira && cfg.podeVerLixeira; }', $js);
        // A lápide sozinha não abre mais a lixeira (era o furo: só-leitor via o botão e levava 403).
        self::assertStringNotContainsString('pastaExcluida', $this->funcao('veLixeira'));
        self::assertStringContainsString('], veLixeira() ? [', $this->funcao('opcoesDoMenu'));
        self::assertStringContainsString('el.btnLixeira.hidden = !veLixeira();', $js);
        self::assertStringContainsString('if (!veLixeira() || lixeiraContagem != null || lixeiraContando) return;', $this->funcao('atualizarContagemDaLixeira'));
    }

    #[TestDox('o vazio de quem só lê não convida a arrastar, colar nem anexar')]
    public function testVazio(): void
    {
        self::assertStringContainsString(": (!cfg.podeEditar ? (caminho.length ? BJ_T.t('pasta_scripts.js.explorador.vazio_pasta') : BJ_T.t('pasta_scripts.js.explorador.vazio_raiz'))", $this->funcao('renderizar'));
        self::assertTextoDoScript('Pasta vazia.', 'explorador.vazio_pasta', $this->funcao('renderizar'));
        self::assertTextoDoScript('Nenhum arquivo ainda.', 'explorador.vazio_raiz', $this->funcao('renderizar'));
    }

    #[TestDox('checklist: sem data-pode-editar="1" no #pexExplorador, ✎, +, modelos, alça e marcar saem ANTES de qualquer listener; o interruptor e o Reativar também')]
    public function testChecklist(): void
    {
        $js = (string) file_get_contents(self::CHECKLIST);

        $guarda = strpos($js, "if (!explorador || explorador.getAttribute('data-pode-editar') !== '1') {");
        self::assertNotFalse($guarda);
        $bloco = substr($js, $guarda, (int) strpos($js, "\n        return;\n    }", $guarda) - $guarda);
        foreach (['btnChecklistEditar', 'btnChecklistAdicionar', 'checklistFormAdicionar', 'btnChecklistModelos', 'checklistModelosPainel'] as $id) {
            self::assertStringContainsString("'" . $id . "'", $bloco);
        }
        self::assertStringContainsString("lista.querySelectorAll('.checklist-drag-handle')", $bloco);
        self::assertStringContainsString("lista.querySelectorAll('.js-checklist-toggle')", $bloco);
        self::assertStringContainsString('b.disabled = true;', $bloco);
        self::assertLessThan(strpos($js, "lista.addEventListener('click'"), $guarda, 'antes do marcar/excluir');
        self::assertLessThan(strpos($js, "btnEditar.addEventListener('click'"), $guarda);
        self::assertLessThan(strpos($js, "btnAdicionar.addEventListener('click'"), $guarda);

        // Segundo bloco (interruptor): a mesma guarda antes do clique.
        $segunda = strpos($js, "if (!explorador || explorador.getAttribute('data-pode-editar') !== '1') {", $guarda + 1);
        self::assertNotFalse($segunda, 'o bloco do interruptor tem a guarda própria');
        self::assertStringContainsString('[botao, painel, reativ].forEach(function (n) { if (n) { n.remove(); } });', substr($js, $segunda, 200));
        self::assertLessThan(strpos($js, "botao.addEventListener('click'"), $segunda);
    }
}
