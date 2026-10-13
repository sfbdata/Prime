<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-018 (docs/specs/engineering-loop/inventario-editor.md): o seletor de estilo do parágrafo
 * tem "Citação", como o `popEstilo` do desenho (bj-editor.js l.445-450): Normal · Título 1 ·
 * Título 2 · Citação, cada um com a prévia do próprio estilo.
 *
 * Teste de FOLHA (lê o JS e o CSS; sem navegador). O comportamento na tela é do smoke.
 */
final class EditorRicoEstiloCitacaoTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const JS = __DIR__ . '/../../../public/js/editor-rico.js';
    private const CSS = __DIR__ . '/../../../public/css/editor-rico.css';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    private function css(): string
    {
        return (string) file_get_contents(self::CSS);
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

    #[TestDox('o seletor lista Normal, Título 1, Título 2 e Citação, na ordem do desenho')]
    public function testOpcoesNaOrdemDoDesenho(): void
    {
        $js = $this->js();

        self::assertStringContainsString("var ESTILO_CITACAO = 'citacao';", $js);
        self::assertStringContainsString('[{ header: [false, 2, 3, ESTILO_CITACAO] }],', $js);
        self::assertStringContainsString("{ '': 'Normal', '2': 'Título 1', '3': 'Título 2', citacao: 'Citação' }", $this->funcao('aplicarDicas'));
    }

    #[TestDox('Citação aplica o formato blockquote — nunca vira "header" no texto')]
    public function testCitacaoAplicaBlockquote(): void
    {
        $js = $this->js();
        self::assertStringContainsString('header: function (valor) { aplicarEstilo(this.quill, valor); },', $js);

        $estilo = $this->funcao('aplicarEstilo');
        $citacao = strpos($estilo, 'if (valor === ESTILO_CITACAO) {');
        self::assertNotFalse($citacao);
        $ramo = substr($estilo, $citacao, (int) strpos($estilo, 'return;', $citacao) - $citacao);
        self::assertStringContainsString("quill.format('blockquote', true, 'user');", $ramo);
        self::assertStringNotContainsString("'header'", $ramo, 'o valor da Citação não pode chegar ao formato header');
        // O return da Citação vem antes do format('header', …).
        self::assertLessThan(strpos($estilo, "quill.format('header', valor, 'user');"), (int) strpos($estilo, 'return;', $citacao));
    }

    #[TestDox('Normal desliga a citação e o título; Título 1/2 seguem pelo formato header')]
    public function testNormalDesligaCitacao(): void
    {
        $estilo = $this->funcao('aplicarEstilo');

        self::assertStringContainsString("if (!valor) {\n            quill.format('blockquote', false, 'user');\n        }", $estilo);
        self::assertStringContainsString("quill.format('header', valor, 'user');", $estilo);
    }

    #[TestDox('com o cursor numa citação, o seletor mostra "Citação" (o Quill só marcaria pelo header)')]
    public function testSeletorMostraCitacao(): void
    {
        $js = $this->js();
        $marcar = $this->funcao('marcarCitacaoNoEstilo');

        self::assertStringContainsString('refs.quill.getFormat(faixa).blockquote', $marcar);
        self::assertStringContainsString('option[value="\' + ESTILO_CITACAO + \'"]', $marcar);
        self::assertStringContainsString('seletor.update();', $marcar);
        self::assertStringContainsString("quill.on('editor-change', function () { marcarCitacaoNoEstilo(refs); });", $js);
        self::assertStringContainsString("p.select.classList.contains('ql-header')", $js);
        // Registrado depois da barra (o Quill marca o seletor primeiro; este listener corrige).
        self::assertGreaterThan(strpos($js, 'var quill = new Quill(alvo, {'), strpos($js, "quill.on('editor-change', function () { marcarCitacaoNoEstilo(refs); });"));
    }

    #[TestDox('o rótulo e o item mostram "Citação", com a prévia em itálico do desenho, nos dois temas')]
    public function testCssDaCitacao(): void
    {
        $css = $this->css();

        self::assertStringContainsString('.ql-picker.ql-header .ql-picker-label[data-value="citacao"]::before,', $css);
        self::assertStringContainsString('.ql-picker.ql-header .ql-picker-item[data-value="citacao"]::before { content: "Citação"; }', $css);
        self::assertStringContainsString('.ql-picker.ql-header .ql-picker-item[data-value="citacao"]::before { font-style: italic; color: var(--er-citacao-tx); }', $css);
        self::assertStringContainsString('--er-citacao-tx:   #455c6b;', $css);
        self::assertStringContainsString('--er-citacao-tx:   var(--bs-secondary-color);', $css);
    }

    #[TestDox('a citação sobrevive ao sanitizador (o que o seletor aplica não some ao salvar)')]
    public function testBlockquoteSobreviveAoSanitizador(): void
    {
        $limpo = (string) $this->criarSanitizadorTextoRico()->limpar('<blockquote>Trecho citado</blockquote>');

        self::assertStringContainsString('<blockquote>Trecho citado</blockquote>', $limpo);
    }
}
