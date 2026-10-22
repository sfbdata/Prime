<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Aba Documentos do drawer (spec inteligencia-drawer-da-pasta, F4) no `pasta-ia-agentes.js`. Regex não
 * executa JS: prova que as peças estão no lugar; o comportamento no navegador é smoke do dono.
 */
#[Group('inteligencia')]
final class PastaIaDocumentosJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-ia-agentes.js';
    private const CATALOGO = __DIR__ . '/../../../translations/messages+intl-icu.pt_BR.yaml';

    private function js(): string
    {
        $conteudo = file_get_contents(self::JS);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    /** O corpo de `function $nome(` até o fim dela (o `}` com 8 espaços). */
    private function funcao(string $nome): string
    {
        $js = $this->js();
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, $nome);
        $fim = strpos($js, "\n        }\n", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('o POST do Índice leva os ids marcados como documentos[] e não sai sem seleção')]
    public function testEnvioDaSelecao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("dados.append('documentos[]', id);", $js);
        self::assertStringContainsString('if (semSelecao(botao)) { return; }', $js);
    }

    #[TestDox('depois do polling o title do Índice segue a seleção ("Marque os documentos do índice"), não o histórico')]
    public function testTitleDoIndiceDepoisDoPolling(): void
    {
        $sincronizar = $this->funcao('sincronizarBotao');
        $titulo = $this->funcao('tituloDoIndice');

        self::assertStringContainsString("if (botao.hasAttribute('data-ia-exige-selecao')) { botao.title = tituloDoIndice(botao); }", $sincronizar);
        $historico = strpos($sincronizar, "BJ_T.t('inteligencia.js.agentes.dica_nova')");
        self::assertNotFalse($historico);
        self::assertGreaterThan($historico, strpos($sincronizar, 'tituloDoIndice(botao)'), 'o title do Índice sobrescreve o do histórico');
        self::assertStringContainsString("semSelecao(botao) ? BJ_T.t('inteligencia.js.agentes.dica_indice_marque')", $titulo);
        // O texto mora no catálogo (spec idioma-do-sistema, §25).
        $js = Yaml::parseFile(self::CATALOGO)['inteligencia']['js']['agentes'];
        self::assertSame('Nova análise considerando o histórico', $js['dica_nova']);
        self::assertSame('Marque os documentos do índice', $js['dica_indice_marque']);
        self::assertStringContainsString('botao.title = tituloDoIndice(botao);', $this->funcao('atualizarSelecao'));
    }
}
