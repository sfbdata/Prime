<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-016 (docs/specs/engineering-loop/inventario-editor.md): autoformatação ao digitar, como o
 * `autoFormatar` do desenho (bj-editor.js l.618-626) — "> " vira citação, "1)" vira numeração
 * (além de "1.", "-" e "*", que o Quill já convertia) e um chip avisa "Formatação automática
 * aplicada", com Desfazer.
 *
 * Teste de FOLHA (lê o JS; sem navegador). O comportamento na tela é do smoke.
 */
final class EditorRicoAutoformatacaoTest extends TestCase
{
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

    /** O regex de `PREFIXO_AUTOFORMATACAO`, convertido para PCRE. */
    private function prefixo(): string
    {
        self::assertSame(1, preg_match('#var PREFIXO_AUTOFORMATACAO = /(.+?)/;#', $this->js(), $m), 'PREFIXO_AUTOFORMATACAO não encontrado');

        return '/' . $m[1] . '/';
    }

    /** @return iterable<string, array{string, bool}> texto antes do espaço, converte? */
    public static function prefixos(): iterable
    {
        yield '"1." (Quill)' => ['1.', true];
        yield '"12." (Quill)' => ['12.', true];
        yield '"1)" (desenho)' => ['1)', true];
        yield '"-" (Quill)' => ['-', true];
        yield '"*" (Quill)' => ['*', true];
        yield '"[ ]" (Quill)' => ['[ ]', true];
        yield '">" (desenho)' => ['>', true];
        yield '"2)" fica texto (desenho só aceita 1)' => ['2)', false];
        yield '" >" com espaço antes fica texto (desenho: ^> $)' => [' >', false];
        yield '">>" fica texto' => ['>>', false];
        yield '"a>" fica texto' => ['a>', false];
        yield '"texto 1)" fica texto' => ['texto 1)', false];
    }

    #[DataProvider('prefixos')]
    #[TestDox('o prefixo $_dataName')]
    public function testPrefixo(string $antes, bool $converte): void
    {
        self::assertSame($converte, preg_match($this->prefixo(), $antes) === 1);
    }

    #[TestDox('substitui o "list autofill" do Quill (mesmo nome), disparado pelo espaço com o cursor sem seleção')]
    public function testSubstituiOListAutofillDoQuill(): void
    {
        self::assertMatchesRegularExpression(
            "/'list autofill': \{\s+key: ' ',\s+shiftKey: null,\s+collapsed: true,\s+prefix: PREFIXO_AUTOFORMATACAO,\s+handler: function \(faixa, contexto\) \{ return autoFormatar\(refs, this, faixa, contexto\); \},/",
            $this->js()
        );
        // A premissa: o binding padrão tem esse nome e não converte "1)" nem ">".
        $quill = (string) file_get_contents(self::QUILL);
        self::assertStringContainsString('"list autofill":{key:" ",shiftKey:null,collapsed:!0,format:{"code-block":!1,blockquote:!1,table:!1},prefix:/^\s*?(\d+\.|-|\*|\[ ?\]|\[x\])$/', $quill);
    }

    #[TestDox('as listas seguem com a conversão do próprio Quill (o handler é lido dele, não copiado)')]
    public function testListasUsamOHandlerDoQuill(): void
    {
        self::assertStringContainsString(
            "Quill.import('modules/keyboard').DEFAULTS.bindings['list autofill'].handler",
            $this->funcao('conversaoDeListaDoQuill')
        );
        self::assertStringContainsString('conversaoDeListaDoQuill().call(teclado, faixa, contexto) === false', $this->funcao('autoFormatar'));
    }

    #[TestDox('"> " vira citação num passo só do histórico, fora de lista e só no começo da linha')]
    public function testCitacaoAutomatica(): void
    {
        $citacao = $this->funcao('citacaoAutomatica');

        self::assertStringContainsString('if (contexto.format.list || contexto.offset !== 1) { return false; }', $citacao);
        // Mesmo roteiro do Quill: o espaço entra, corta o histórico, converte, corta de novo.
        $ordem = array_map(
            static fn (string $t): int|false => strpos($citacao, $t),
            [
                "quill.insertText(faixa.index, ' ', 'user');",
                'quill.history.cutoff();',
                "quill.updateContents(new Delta().retain(inicio).delete(contexto.offset + 1), 'user');",
                "quill.formatLine(inicio, 1, 'blockquote', true, 'user');",
            ]
        );
        self::assertNotContains(false, $ordem);
        $ordenada = $ordem;
        sort($ordenada);
        self::assertSame($ordenada, $ordem);
        self::assertSame(2, substr_count($citacao, 'quill.history.cutoff();'));
    }

    #[TestDox('depois de converter, o chip do desenho: "Formatação automática aplicada" + Desfazer')]
    public function testChipComDesfazer(): void
    {
        $auto = $this->funcao('autoFormatar');

        self::assertStringContainsString("if (!convertido) { return true; }", $auto, 'sem conversão, o espaço segue para o Quill');
        self::assertStringContainsString("textoDoChip('bi-magic', [{ texto: 'Formatação automática aplicada' }])", $auto);
        self::assertStringContainsString("{ rotulo: 'Desfazer', acao: function () {", $auto);
        // Desfazer só volta a conversão se ela ainda for o último passo (não apaga digitação nova).
        self::assertStringContainsString('if (pilha && pilha.undo.length === passos) { quill.history.undo(); }', $auto);
        self::assertStringContainsString('], 3600);', $auto, 'mesma duração do chip do desenho');
        self::assertLessThan(strpos($auto, 'mostrarChip('), strpos($auto, 'if (!convertido)'), 'o chip só aparece quando houve conversão');
    }
}
