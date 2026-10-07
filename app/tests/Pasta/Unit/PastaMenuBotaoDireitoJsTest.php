<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * PMC-001 e PMC-027 — botão direito no cartão do processo e na linha da meta abre o ⋮ que JÁ
 * existe, no cursor. Não há harness de navegador no PHPUnit: aqui o JS é lido como folha e se
 * prova o contrato com o template (`data-ps-menu-contexto` → id do menu), que o menu é o mesmo
 * elemento (nenhum item montado em JS), o `preventDefault` só depois de achar o menu e as
 * exceções que ficam com o menu nativo. O clique em si é do smoke do dono.
 */
final class PastaMenuBotaoDireitoJsTest extends TestCase
{
    private const JS_PROCESSO = __DIR__ . '/../../../public/js/pasta-processo.js';
    private const JS_METAS    = __DIR__ . '/../../../public/js/pasta-metas.js';

    private function blocoProcesso(): string
    {
        $js     = (string) file_get_contents(self::JS_PROCESSO);
        $inicio = strpos($js, "document.addEventListener('contextmenu'");
        self::assertNotFalse($inicio, 'o botão direito é delegado no document (o parcial volta por XHR)');
        $fim = strpos($js, "document.addEventListener('keydown'", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    private function blocoMetas(): string
    {
        $js     = (string) file_get_contents(self::JS_METAS);
        $inicio = strpos($js, 'function menuNoBotaoDireito()');
        self::assertNotFalse($inicio, 'o bloco do botão direito existe');

        return substr($js, $inicio);
    }

    #[TestDox('processo: o cartão é achado pelo gancho data-ps-menu-contexto e o menu é o do id que ele aponta')]
    public function testProcessoUsaOGancho(): void
    {
        $bloco = $this->blocoProcesso();
        self::assertStringContainsString("closest('article.ps-processo[data-ps-menu-contexto]')", $bloco);
        self::assertStringContainsString("document.getElementById(cartao.getAttribute('data-ps-menu-contexto'))", $bloco);
        self::assertStringNotContainsString('createElement', $bloco, 'nenhum item é montado em JS: o menu é o mesmo do ⋮');
    }

    #[TestDox('processo: abre pelo MESMO abrirMenu do ⋮, com o ponto do cursor; sem coordenada, sob o ⋮')]
    public function testProcessoAbreNoCursor(): void
    {
        $bloco = $this->blocoProcesso();
        self::assertStringContainsString('abrirMenu(menu, gatilho, temPonto ? { x: e.clientX, y: e.clientY } : null)', $bloco);

        $js = (string) file_get_contents(self::JS_PROCESSO);
        self::assertStringContainsString('if (ponto) { posicionarNoPonto(menu, ponto.x, ponto.y); } else { posicionar(menu, gatilho); }', $js);
    }

    #[TestDox('processo: links, botões, campos, o próprio menu e texto selecionado ficam com o menu nativo — antes do preventDefault')]
    public function testProcessoExcecoesAntesDoPreventDefault(): void
    {
        $bloco   = $this->blocoProcesso();
        $excecao = strpos($bloco, "alvo.closest('a, button, input, textarea, select, [contenteditable=\"true\"], .ps-processo-menu')");
        $selecao = strpos($bloco, 'window.getSelection');
        $achou   = strpos($bloco, 'if (!menu || !gatilho) { return; }');
        $prevent = strpos($bloco, 'e.preventDefault()');
        self::assertNotFalse($excecao);
        self::assertNotFalse($selecao);
        self::assertNotFalse($achou);
        self::assertNotFalse($prevent);
        self::assertLessThan($prevent, $excecao);
        self::assertLessThan($prevent, $selecao);
        self::assertLessThan($prevent, $achou, 'sem menu, o navegador mostra o dele');
    }

    #[TestDox('metas: a linha é achada pelo gancho; o ⋮ da linha é acionado (pasta-show.js abre) e o painel vai ao cursor')]
    public function testMetasAcionaOProprioMenu(): void
    {
        $bloco = $this->blocoMetas();
        self::assertStringContainsString("cartao.addEventListener('contextmenu'", $bloco);
        self::assertStringContainsString("closest('article.ps-meta[data-ps-menu-contexto]')", $bloco);
        self::assertStringContainsString("document.getElementById(linha.getAttribute('data-ps-menu-contexto'))", $bloco);
        self::assertStringContainsString("if (!menu.classList.contains('is-aberto')) { gatilho.click(); }", $bloco);
        self::assertStringContainsString('noCursor(menu, e.clientX, e.clientY)', $bloco);
        self::assertStringNotContainsString('createElement', $bloco);

        $js = (string) file_get_contents(self::JS_METAS);
        self::assertMatchesRegularExpression('/function iniciar\(\) \{[^}]*menuNoBotaoDireito\(\);/', $js, 'o bloco é ligado no boot');
    }

    #[TestDox('metas: o ⋮ comum devolve o painel ao canto de sempre (listener em captura, antes do pasta-show.js)')]
    public function testMetasVoltaAoCanto(): void
    {
        $bloco = $this->blocoMetas();
        self::assertMatchesRegularExpression('/voltarAoCanto\(menu\); \}\s*\}, true\);/', $bloco);
    }

    #[TestDox('metas: editor do nome, botões, campos, o menu e texto selecionado ficam com o menu nativo — antes do preventDefault')]
    public function testMetasExcecoes(): void
    {
        $bloco   = $this->blocoMetas();
        $inicio  = (int) strpos($bloco, "cartao.addEventListener('contextmenu'");
        $corpo   = substr($bloco, $inicio);
        $prevent = strpos($corpo, 'e.preventDefault()');
        self::assertNotFalse($prevent);
        foreach (["linha.classList.contains('is-renomeando')", "e.target.closest('button, input, textarea, select, form, .ps-pop')", 'window.getSelection', 'if (!menu || !gatilho) { return; }'] as $guarda) {
            $pos = strpos($corpo, $guarda);
            self::assertNotFalse($pos, $guarda);
            self::assertLessThan($prevent, $pos, $guarda . ' vem antes do preventDefault');
        }
    }
}
