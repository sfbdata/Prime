<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-019 e EDT-020 (docs/specs/engineering-loop/inventario-editor.md): atalhos do `onKey` do
 * desenho (bj-editor.js l.674-684).
 *  - EDT-019: Ctrl+Y refaz em todo sistema. O Quill só liga Ctrl+Y quando
 *    `/Win/i.test(navigator.platform)` — no Linux e no macOS a dica "Refazer (Ctrl+Y)" mentia.
 *  - EDT-020: Tab fora de lista insere 4 espaços não separáveis. O `tab` do Quill insere "\t",
 *    que some na exibição (espaço em branco colapsa); dentro da lista, o Tab recua o item.
 *
 * Teste de FOLHA (lê o JS; sem navegador). O comportamento na tela é do smoke.
 */
final class EditorRicoTecladoTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const JS = __DIR__ . '/../../../public/js/editor-rico.js';
    private const QUILL = __DIR__ . '/../../../public/js/quill/quill.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    /** Os `bindings` passados ao teclado do Quill em `montar()`. */
    private function bindings(): string
    {
        self::assertSame(1, preg_match('/keyboard: \{\n\s+bindings: \{(.*?)\n {16}\},\n {12}\},/s', $this->js(), $m), 'bindings do teclado não encontrados');

        return $m[1];
    }

    #[TestDox('a premissa: o Quill embutido só liga Ctrl+Y no Windows')]
    public function testQuillSoLigaCtrlYNoWindows(): void
    {
        // Se o Quill mudar e passar a ligar sempre, o binding próprio vira redundante (inofensivo).
        self::assertStringContainsString('/Win/i.test(navigator.platform)&&this.quill.keyboard.addBinding({key:"y",shortKey:!0}', (string) file_get_contents(self::QUILL));
    }

    #[TestDox('EDT-019: Ctrl+Y (Cmd+Y no macOS) refaz pelo histórico do Quill, sem depender do sistema')]
    public function testCtrlYRefazEmTodoSistema(): void
    {
        $bindings = $this->bindings();

        self::assertMatchesRegularExpression(
            "/refazer: \{\s+key: 'y',\s+shortKey: true,\s+handler: function \(\) \{ this\.quill\.history\.redo\(\); return false; \},/",
            $bindings
        );
        self::assertStringNotContainsString('navigator.platform', $bindings, 'o atalho não pode depender do sistema');
        // A dica da barra continua prometendo o mesmo atalho que agora existe.
        self::assertStringContainsString("'ql-redo': 'Refazer (Ctrl+Y)',", $this->js());
    }

    #[TestDox('EDT-020: o binding "tab" substitui o do Quill (mesmo nome) e só pega Tab sem Shift')]
    public function testTabSubstituiOBindingDoQuill(): void
    {
        $bindings = $this->bindings();

        // Mesmo nome do binding padrão (`tab`): o merge das opções troca o do Quill pelo nosso.
        self::assertMatchesRegularExpression(
            "/\n\s+tab: \{\s+key: 'Tab',\s+handler: function \(faixa, contexto\) \{ return tabulacao\(this\.quill, faixa, contexto\); \},/",
            $bindings
        );
        // Sem `shiftKey`: Shift+Tab fora de lista fica com o navegador, como no desenho.
        self::assertSame(1, preg_match("/\n\s+tab: \{(.*?)\n\s+\},/s", $bindings, $m));
        self::assertStringNotContainsString('shiftKey', $m[1]);
        // A premissa: o Quill embutido tem um binding chamado `tab` que insere "\t".
        self::assertStringContainsString('tab:{key:"Tab",handler(t,e){if(e.format.table)return!0;', (string) file_get_contents(self::QUILL));
    }

    #[TestDox('EDT-020: fora de lista, 4 espaços não separáveis no lugar da seleção; nunca "\t"')]
    public function testForaDeListaInsereQuatroEspacosNaoSeparaveis(): void
    {
        self::assertStringContainsString("var TABULACAO = '\\u00a0\\u00a0\\u00a0\\u00a0';", $this->js());

        $tab = $this->funcao('tabulacao');
        self::assertStringContainsString('inserirTexto(quill, faixa, TABULACAO);', $tab);
        self::assertStringNotContainsString('\\t', $tab);
        self::assertStringNotContainsString('return true', $tab, 'o Tab nunca pode cair no "\t" do Quill nem sair do editor');
    }

    #[TestDox('EDT-020: dentro de lista, o Tab recua o item (como o desenho), em qualquer posição do cursor')]
    public function testNaListaORecuoAumenta(): void
    {
        $tab = $this->funcao('tabulacao');

        self::assertStringContainsString('if (contexto.format && contexto.format.list) {', $tab);
        self::assertStringContainsString("quill.format('indent', '+1', 'user');", $tab);
        self::assertLessThan(strpos($tab, 'inserirTexto('), strpos($tab, "quill.format('indent'"), 'a lista decide antes do texto');
    }

    #[TestDox('o espaço não separável sobrevive ao sanitizador (o recuo não some ao salvar)')]
    public function testEspacoNaoSeparavelSobreviveAoSanitizador(): void
    {
        $sanitizador = $this->criarSanitizadorTextoRico();

        $limpo = html_entity_decode((string) $sanitizador->limpar('<p>&nbsp;&nbsp;&nbsp;&nbsp;Recuo</p>'), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        self::assertStringContainsString("\u{00a0}\u{00a0}\u{00a0}\u{00a0}Recuo", $limpo);
    }
}
