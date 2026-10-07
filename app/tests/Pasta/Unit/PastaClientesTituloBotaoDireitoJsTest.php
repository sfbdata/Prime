<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * PAS-001 — botão direito no nome do cliente (h1 do cabeçalho) abre a MESMA janela "Detalhes do
 * cliente" do trilho e do botão Cadastro (`abrirJanela` em `public/js/pasta-clientes.js`). Lido
 * como folha: não há harness de navegador. A dica é só CSS (ver o `<style>` do `_cabecalho`).
 */
final class PastaClientesTituloBotaoDireitoJsTest extends TestCase
{
    private const JS        = __DIR__ . '/../../../public/js/pasta-clientes.js';
    private const CABECALHO = __DIR__ . '/../../../templates/pasta/_cabecalho.html.twig';

    private function contextmenu(): string
    {
        $js     = (string) file_get_contents(self::JS);
        $inicio = strpos($js, "document.addEventListener('contextmenu'");
        self::assertNotFalse($inicio, 'o botão direito é delegado no document');
        $fim = strpos($js, "document.addEventListener('keydown'", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('o ramo do título vem antes do da linha do trilho, acha o h1 pelo data-cliente-id e reusa abrirJanela no cursor')]
    public function testRamoDoTituloReusaAJanela(): void
    {
        $bloco  = $this->contextmenu();
        $titulo = strpos($bloco, "e.target.closest('.ps-cabecalho h1.ps-cab-titulo[data-cliente-id]')");
        $linha  = strpos($bloco, "e.target.closest('[data-trilho=\"clientes\"] .cliente-linha[data-cliente-id]')");
        self::assertNotFalse($titulo);
        self::assertNotFalse($linha);
        self::assertLessThan($linha, $titulo);

        $ramo = substr($bloco, (int) $titulo, (int) $linha - (int) $titulo);
        self::assertStringContainsString("abrirJanela(tituloCliente.getAttribute('data-cliente-id'), e.clientX + 4, e.clientY + 4)", $ramo, 'mesmo +4 do desenho (`abrirCli`)');
    }

    #[TestDox('com texto selecionado (copiar o nome) o navegador fica com o menu dele: a guarda vem antes do preventDefault')]
    public function testSelecaoFicaComONavegador(): void
    {
        $bloco   = $this->contextmenu();
        $inicio  = (int) strpos($bloco, 'var tituloCliente');
        $ramo    = substr($bloco, $inicio);
        $selecao = strpos($ramo, "if (selecao.trim() !== '') { return; }");
        $prevent = strpos($ramo, 'e.preventDefault()');
        self::assertNotFalse($selecao);
        self::assertNotFalse($prevent);
        self::assertLessThan($prevent, $selecao);
    }

    #[TestDox('a dica aparece só no hover do h1 COM cliente, depois de 350ms (desenho `tipOn`), e some em tela de toque')]
    public function testDicaEmCss(): void
    {
        $css = (string) file_get_contents(self::CABECALHO);
        self::assertStringContainsString('.ps-cab-cliente:has(.ps-cab-titulo[data-cliente-id]:hover) > .ps-cab-dica', $css);
        self::assertStringContainsString('transition-delay: .35s', $css);
        self::assertStringContainsString('@media (hover: none) { .ps-cab-dica { display: none; } }', $css);
        self::assertStringContainsString('.ps-cab-titulo[data-cliente-id] { cursor: context-menu; }', $css);
    }
}
