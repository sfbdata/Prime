<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Teste de FOLHA do `public/js/pasta-dialogos.js` (lote L6): lê o JS, sem navegador. Trava o
 * que o desenho pede e o que a tela não pode perder; o comportamento na tela é do smoke.
 */
final class PastaDialogosJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-dialogos.js';

    private function js(): string
    {
        $js = file_get_contents(self::JS);
        self::assertIsString($js);

        return $js;
    }

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js  = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);
        self::assertNotFalse($fim);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('PMC-039: o contador usa o maxlength do campo e reconta ao abrir e fechar o modal')]
    public function testContador(): void
    {
        $f = $this->funcao('contadores');
        self::assertStringContainsString("campo.getAttribute('maxlength')", $f);
        self::assertStringContainsString("String(campo.value.length) + (limite ? '/' + limite : '')", $f);
        self::assertStringContainsString("modalMeta.addEventListener('show.bs.modal', atualizar)", $f);
        self::assertStringContainsString("modalMeta.addEventListener('hidden.bs.modal', atualizar)", $f);
    }

    #[TestDox('PMC-041: os chips saem do <select> (a fonte) e marcar/desmarcar mexe só na <option>')]
    public function testSelectEhAFonte(): void
    {
        $f = $this->funcao('seletorDeResponsaveis');
        self::assertStringContainsString('option.selected = !option.selected;', $f);
        self::assertStringContainsString("select.dispatchEvent(new Event('change', { bubbles: true }))", $f);
        self::assertStringContainsString("var on = !!(option && option.selected);", $f);
        self::assertStringContainsString("modalMeta.addEventListener('show.bs.modal', pintar)", $f, 'o modal pode abrir com marcação de outro script');
    }

    #[TestDox('PMC-041: Esc na lista fecha a lista e não o modal; Enter na busca marca o primeiro que sobrou')]
    public function testTeclasDaLista(): void
    {
        $f   = $this->funcao('seletorDeResponsaveis');
        $esc = substr($f, (int) strpos($f, "if (e.key === 'Escape')"), 160);
        self::assertStringContainsString('e.stopPropagation();', $esc);
        self::assertStringContainsString('fechar(true);', $esc);
        self::assertStringContainsString("e.key === 'Enter' && e.target === busca", $f);
    }

    #[TestDox('nada de HTML montado com texto: só textContent e nós já desenhados pelo Twig')]
    public function testSemInnerHtml(): void
    {
        self::assertStringNotContainsString('innerHTML', $this->js());
        self::assertStringNotContainsString('insertAdjacentHTML', $this->js());
    }
}
