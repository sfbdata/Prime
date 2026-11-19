<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use App\Tests\Support\TextoDoCatalogo;
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
    use TextoDoCatalogo;

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
        self::assertStringContainsString("itemDoMenu('bi-person-vcard', T('menu.inserir_qualif'), '', false, executar(function () {", $bloco);
        self::assertSame('Inserir qualificação do cliente', self::ptBr('comum.js.editor.' . 'menu.inserir_qualif'));
        self::assertStringContainsString('inserirQualificacao(refs, qualificacao);', $bloco);
        self::assertLessThan(strpos($menu, "T('menu.localizar')"), $ini, 'antes de Localizar, como no desenho');
    }

    #[TestDox('insere como TEXTO no cursor com um espaço depois; o chip diz de quem ou quantas pendências; sem cadastro só avisa')]
    public function testInsercaoEAvisos(): void
    {
        $f = $this->funcao('inserirQualificacao');
        self::assertStringContainsString("inserirTexto(quill, faixa, texto + ' ');", $f);
        self::assertStringContainsString("T('qualif.com_pendencias', { n: pendencias })", $f);
        self::assertStringContainsString("T('qualif.inserida', { nome: primeiro })", $f);
        self::assertStringContainsString("T('qualif.sem_cadastro')", $f);
        self::assertSame('Qualificação inserida com {n} pendência(s) no cadastro', self::ptBr('comum.js.editor.' . 'qualif.com_pendencias'));
        self::assertSame('Qualificação de {nome} inserida', self::ptBr('comum.js.editor.' . 'qualif.inserida'));
        self::assertSame('Cliente sem cadastro. Abra a qualificação pelo botão ao lado do nome.', self::ptBr('comum.js.editor.' . 'qualif.sem_cadastro'));
        $vazio = substr($f, (int) strpos($f, "if (texto === '') {"), 400);
        self::assertStringContainsString('return;', $vazio, 'sem cadastro nada é inserido');
        self::assertStringNotContainsString('innerHTML', $f);
        self::assertStringNotContainsString('dangerouslyPasteHTML', $f);
    }
}
