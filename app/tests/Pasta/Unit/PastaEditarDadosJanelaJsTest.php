<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O script da janela "Editar dados" (show.html.twig) lido como FOLHA, contra o desenho 02 de 18/10
 * (`peAbrir` L.4451, `pe.fundo`, `pe.fechar`, `pe.tecla`, `pe.foco` em `peVals` L.4475-4485).
 * Sem navegador no PHPUnit: o que se prova é o caminho de cada comportamento.
 */
#[CoversNothing]
#[Group('pasta')]
final class PastaEditarDadosJanelaJsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../';

    private function script(): string
    {
        $twig   = (string) file_get_contents(self::DIR . 'templates/pasta/show.html.twig');
        $inicio = strpos($twig, '/* Janela "Editar dados" do desenho 02');
        self::assertNotFalse($inicio, 'o script da janela existe');
        $fim = strpos($twig, '</script>', $inicio);
        self::assertNotFalse($fim);

        return substr($twig, $inicio, $fim - $inicio);
    }

    private function funcao(string $nome): string
    {
        $js     = $this->script();
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, "a função {$nome} existe");
        $fim = strpos($js, "\n    }\n", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('sem Bootstrap: nada de bootstrap.Modal nem de eventos .bs.modal no script da janela')]
    public function testSemBootstrap(): void
    {
        $js = $this->script();
        self::assertStringNotContainsString('bootstrap.', $js);
        self::assertStringNotContainsString('.bs.modal', $js);
        self::assertStringContainsString("if (!modal) { return; }", $js, 'não sai cedo esperando o Bootstrap');
    }

    #[TestDox('abrir (peAbrir): mostra a janela, relê o responsável, confere o estado e foca o número com o texto selecionado (pe.foco)')]
    public function testAbrir(): void
    {
        $abrir = $this->funcao('peAbrir');
        self::assertStringContainsString('modal.hidden = false;', $abrir);
        self::assertStringContainsString('conferir();', $abrir, 'pílula e Salvar no estado certo ao abrir');
        self::assertStringContainsString("document.querySelector('.ps-cab-resp .pasta-resp-chip .pasta-resp-nome')", $abrir);
        self::assertStringContainsString('if (nup) { nup.focus(); nup.select(); }', $abrir);

        $js = $this->script();
        self::assertStringContainsString("var gatilho = e.target.closest('[data-pe-abrir]');", $js, 'abre pelo item do ⋮ (e pelo atalho E, que clica nele)');
    }

    #[TestDox('fechar: X e Fechar (data-pe-fechar), mousedown no próprio fundo (pe.fundo) e Esc (pe.tecla); avisa pe:fechada e devolve o foco')]
    public function testFechar(): void
    {
        $js = $this->script();
        self::assertStringContainsString("if (e.target.closest('[data-pe-fechar]')) { peFechar(); }", $js);
        self::assertStringContainsString("modal.addEventListener('mousedown', function (e) {\n        if (e.target === modal) { peFechar(); }", $js, 'só o fundo, nunca a caixa');
        self::assertStringContainsString("if (e.key === 'Escape' && !modal.hidden) { peFechar(); }", $js);

        $fechar = $this->funcao('peFechar');
        self::assertStringContainsString('if (modal.hidden) { return; }', $fechar);
        self::assertStringContainsString('modal.hidden = true;', $fechar);
        self::assertStringContainsString("modal.dispatchEvent(new CustomEvent('pe:fechada'));", $fechar, 'o arraste zera a posição');
        self::assertStringContainsString('peDestinoDoFoco(peGatilho)', $fechar);
        self::assertStringNotContainsString('reset', $fechar, 'fechar não descarta o que foi digitado (como antes)');

        $foco = $this->funcao('peDestinoDoFoco');
        self::assertStringContainsString("gatilho.closest('.ps-pop[id], [role=\"menu\"][id]')", $foco, 'item do ⋮ sumiu: o foco vai ao botão do menu');
    }

    #[TestDox('atalhos: fecham a janela sem devolver o foco e abrem o destino; com alteração, perguntam antes')]
    public function testAtalhos(): void
    {
        $js = $this->script();
        self::assertStringContainsString("function seguir() {\n            peFechar(false);\n            destino();\n        }", $js);
        self::assertStringContainsString('window.confirmarPs({', $js, 'Descartar as alterações? continua');
    }

    #[TestDox('arraste: pasta-show.js move a caixa .ps-modal-pasta e a devolve ao lugar no pe:fechada')]
    public function testArraste(): void
    {
        $js = (string) file_get_contents(self::DIR . 'public/js/pasta-show.js');
        self::assertStringContainsString("cabecalho: '#modalEditarPasta:not([hidden]) .ps-modal-pasta-cab',", $js);
        self::assertStringContainsString("janela: function (cab) { return cab.closest('.ps-modal-pasta'); }", $js);
        self::assertStringContainsString("modal.addEventListener('pe:fechada', function () {", $js);
    }

    #[TestDox('folha: sobreposição e caixa com os valores do desenho (L.635-636)')]
    public function testFolha(): void
    {
        $css = (string) file_get_contents(self::DIR . 'public/css/pasta-show.css');
        $ini = strpos($css, '.ps-pe-sobre {');
        self::assertNotFalse($ini);
        $sobre = substr($css, $ini, (int) strpos($css, '}', $ini) - $ini);
        foreach (['position: fixed;', 'z-index: 2100;', 'align-items: flex-start;', 'padding: 4vh 12px;', 'overflow-y: auto;', 'background: rgba(14,37,51,.38);'] as $regra) {
            self::assertStringContainsString($regra, $sobre);
        }
        self::assertStringContainsString('.ps-pe-sobre[hidden] { display: none; }', $css);
        $ini   = strpos($css, '.ps-modal-pasta {');
        self::assertNotFalse($ini);
        $caixa = substr($css, $ini, (int) strpos($css, '}', $ini) - $ini);
        foreach (['max-width: 720px;', 'border-radius: 4px;', 'box-shadow: var(--ps-shadow-modal);', 'flex-direction: column;', 'overflow: hidden;'] as $regra) {
            self::assertStringContainsString($regra, $caixa);
        }
    }
}
