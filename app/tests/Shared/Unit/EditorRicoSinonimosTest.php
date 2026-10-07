<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-012 (docs/specs/engineering-loop/inventario-editor.md): sinônimos no menu do botão direito,
 * como o `ctxMenu` do desenho (bj-editor.js l.740-745) — com a palavra sob o cursor no dicionário
 * local `SIN` (l.166), o bloco "Sinônimos de "palavra"" lista cada sinônimo com
 * `bi-arrow-left-right`, e o clique troca a palavra mantendo a caixa dela.
 *
 * Teste de FOLHA (lê o JS; sem navegador). O comportamento na tela é do smoke.
 */
final class EditorRicoSinonimosTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/editor-rico.js';

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

    /** @return array<string, list<string>> o `SINONIMOS` do JS, lido como dado */
    private function dicionario(): array
    {
        self::assertSame(1, preg_match('/\n    var SINONIMOS = \{(.*?)\};\n/', $this->js(), $m), 'SINONIMOS não encontrado');
        preg_match_all("/(\\w+): \\[([^\\]]*)\\]/u", $m[1], $pares, \PREG_SET_ORDER);
        $dic = [];
        foreach ($pares as $par) {
            preg_match_all("/'([^']*)'/u", $par[2], $valores);
            $dic[$par[1]] = $valores[1];
        }

        return $dic;
    }

    #[TestDox('o dicionário é o SIN do desenho: 33 palavras, chaves sem acento, valores literais')]
    public function testDicionarioDoDesenho(): void
    {
        $dic = $this->dicionario();

        self::assertCount(33, $dic);
        self::assertSame(['prioritário', 'imediato', 'inadiável'], $dic['urgente']);
        self::assertSame(['encontro', 'conferência', 'atendimento'], $dic['reuniao']);
        self::assertSame(['patrono', 'procurador', 'causídico'], $dic['advogado']);
        self::assertSame(['começar', 'instaurar', 'dar início'], $dic['iniciar']);
        self::assertSame(['propor', 'distribuir', 'protocolar'], $dic['ajuizar']);
        foreach (array_keys($dic) as $chave) {
            self::assertMatchesRegularExpression('/^[a-z]+$/', $chave, "chave {$chave} precisa estar como dobrar() devolve");
        }
    }

    #[TestDox('a busca é sem acento e sem caixa, e só pelas chaves do próprio dicionário')]
    public function testBuscaPelaFormaDobrada(): void
    {
        $sinonimos = $this->funcao('sinonimosDe');

        self::assertStringContainsString('Object.prototype.hasOwnProperty.call(SINONIMOS, dobrar(palavra))', $sinonimos, '"constructor" ou "toString" não podem virar sinônimo');
        self::assertStringNotContainsString('fetch(', $sinonimos);
    }

    #[TestDox('a palavra é a que está sob o cursor (estendida às letras vizinhas); mais de uma palavra não tem sinônimo')]
    public function testPalavraSobOCursor(): void
    {
        $palavra = $this->funcao('palavraDoMenu');

        self::assertStringContainsString('var letra = /[\p{L}\p{M}]/u;', $palavra);
        self::assertStringContainsString("while (inicio > 0 && letra.test(texto.charAt(inicio - 1))) { inicio--; }", $palavra);
        self::assertStringContainsString('while (fim < texto.length && letra.test(texto.charAt(fim))) { fim++; }', $palavra);
        self::assertStringContainsString('/^[\p{L}\p{M}]+$/u.test(palavra) ? { inicio: inicio, texto: palavra } : null', $palavra);
    }

    #[TestDox('o botão direito fora da seleção leva o cursor ao ponto do clique, como o desenho')]
    public function testCursorVaiAoClique(): void
    {
        $mover = $this->funcao('moverCursorParaOClique');

        self::assertStringContainsString('if (dentro) { return; }', $mover, 'dentro da seleção, ela fica');
        self::assertStringContainsString('Quill.find(ponto.no, true)', $mover);
        self::assertStringContainsString('blot.offset(quill.scroll)', $mover);
        self::assertStringContainsString('!quill.root.contains(ponto.no)', $mover, 'só um ponto dentro do editor move o cursor');
        self::assertStringContainsString('document.caretPositionFromPoint', $this->funcao('pontoDoTexto'));
        self::assertStringContainsString('document.caretRangeFromPoint', $this->funcao('pontoDoTexto'));

        $menu = $this->funcao('abrirMenuContexto');
        $mover = strpos($menu, 'moverCursorParaOClique(quill, x, y);');
        $leitura = strpos($menu, 'var faixa = quill.getSelection()');
        self::assertNotFalse($mover, 'o menu não leva o cursor ao clique');
        self::assertNotFalse($leitura);
        self::assertLessThan($leitura, $mover, 'o cursor vai ao clique ANTES de o menu ler a seleção');
    }

    #[TestDox('o bloco "Sinônimos de" fica depois de Parágrafo e antes de Localizar, com o ícone do desenho')]
    public function testBlocoNoMenu(): void
    {
        $menu = $this->funcao('abrirMenuContexto');

        $bloco = strpos($menu, "cabecalhoDaJanela('Sinônimos de \"' + palavra.texto + '\"')");
        self::assertNotFalse($bloco);
        self::assertGreaterThan(strpos($menu, "'Aumentar recuo'"), $bloco);
        self::assertLessThan(strpos($menu, "'Localizar e substituir'"), $bloco);
        self::assertStringContainsString("itemDoMenu('bi-arrow-left-right', sinonimo, '', false, executar(function () {", $menu);
        self::assertStringContainsString('trocarPorSinonimo(quill, palavra, sinonimo);', $menu);
        self::assertStringContainsString('if (sinonimos.length) {', $menu, 'sem sinônimo, o bloco não aparece');
    }

    #[TestDox('nenhuma função do editor é declarada duas vezes (a segunda apagaria a primeira em silêncio)')]
    public function testNenhumaFuncaoDuplicada(): void
    {
        // Já aconteceu nesta frente: um `palavraNoCursor` novo para o menu sobrescrevia o da
        // sugestão de palavras (hoisting de função no mesmo escopo), sem erro nenhum no console.
        preg_match_all('/^    function (\w+)\(/m', $this->js(), $m);
        $repetidas = array_keys(array_filter(array_count_values($m[1]), static fn (int $n): bool => $n > 1));

        self::assertSame([], $repetidas);
    }

    #[TestDox('a troca mantém a caixa da palavra e é um passo só do histórico; nada de HTML')]
    public function testTrocaPorSinonimo(): void
    {
        $troca = $this->funcao('trocarPorSinonimo');

        self::assertStringContainsString('var para = mesmaCaixa(palavra.texto, sinonimo);', $troca);
        self::assertStringContainsString('trocarTrechos(quill, [{ inicio: palavra.inicio, tamanho: palavra.texto.length, para: para }]);', $troca);
        self::assertStringNotContainsString('innerHTML', $troca);
        // O rótulo (palavra digitada pela pessoa) entra como texto, nunca como HTML.
        self::assertStringContainsString("return el('div', 'editor-rico-janela-titulo', texto);", $this->funcao('cabecalhoDaJanela'));
        self::assertStringContainsString("if (texto !== undefined) { e.textContent = texto; }", $this->funcao('el'));
    }
}
