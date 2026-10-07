<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-014 (docs/specs/engineering-loop/inventario-editor.md): "Inserir qualificação do cliente" no
 * menu do botão direito do editor, como o `__bjQualificacao` do desenho (bj-editor.js l.751).
 *
 * Teste de FOLHA (lê o JS; sem navegador): o item só existe quando a página traz a qualificação
 * (e a página só a traz para quem vê o cliente — PastaQualificacaoNoEditorTelaTest), o texto entra
 * como TEXTO com um espaço depois e o chip diz o que entrou.
 */
final class EditorRicoQualificacaoTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/editor-rico.js';

    private function js(): string
    {
        $js = file_get_contents(self::JS);
        self::assertIsString($js);

        return $js;
    }

    private function funcao(string $nome): string
    {
        $js  = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);
        self::assertNotFalse($fim);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('o item do desenho (bi-person-vcard, "Inserir qualificação do cliente") só entra com a fonte na página')]
    public function testItemCondicional(): void
    {
        $menu = $this->funcao('abrirMenuContexto');
        $ini  = strpos($menu, "var qualificacao = document.querySelector('[data-editor-qualificacao]');");
        self::assertNotFalse($ini);
        $bloco = substr($menu, $ini, 400);
        self::assertStringContainsString('if (qualificacao) {', $bloco);
        self::assertStringContainsString("itemDoMenu('bi-person-vcard', 'Inserir qualificação do cliente', '', false, executar(function () {", $bloco);
        self::assertStringContainsString('inserirQualificacao(refs, qualificacao);', $bloco);
        self::assertLessThan(strpos($menu, "'Localizar e substituir'"), $ini, 'antes de Localizar, como no desenho');
    }

    #[TestDox('insere como TEXTO no cursor com um espaço depois; o chip diz de quem ou quantas pendências; sem cadastro só avisa')]
    public function testInsercaoEAvisos(): void
    {
        $f = $this->funcao('inserirQualificacao');
        self::assertStringContainsString("inserirTexto(quill, faixa, texto + ' ');", $f);
        self::assertStringContainsString("'Qualificação inserida com ' + pendencias + ' pendência(s) no cadastro'", $f);
        self::assertStringContainsString("'Qualificação de ' + primeiro + ' inserida'", $f);
        self::assertStringContainsString("'Cliente sem cadastro. Abra a qualificação pelo botão ao lado do nome.'", $f);
        $vazio = substr($f, (int) strpos($f, "if (texto === '') {"), 400);
        self::assertStringContainsString('return;', $vazio, 'sem cadastro nada é inserido');
        self::assertStringNotContainsString('innerHTML', $f);
        self::assertStringNotContainsString('dangerouslyPasteHTML', $f);
    }
}
