<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-011 (docs/specs/engineering-loop/inventario-editor.md): menu do botão direito DENTRO do
 * editor, como o `ctxMenu` do desenho (bj-editor.js l.693-762) — mini barra, Recortar, Copiar,
 * Colar, Colar somente texto, Parágrafo, Localizar e substituir, Revisar texto e Selecionar tudo.
 *
 * Teste de FOLHA (lê o JS e o CSS; sem navegador). Trava: os rótulos, ícones e atalhos do
 * desenho; que cada item chama a função que o editor já tem; a Clipboard API com o aviso do
 * desenho quando o navegador nega; nada de HTML cru; Esc fecha; e teclado/toque/Shift ficam com o
 * menu do navegador. O comportamento na tela é do smoke.
 */
final class EditorRicoMenuContextoTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/editor-rico.js';
    private const CSS = __DIR__ . '/../../../public/css/editor-rico.css';

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

    /** @return iterable<string, array{string, string, string}> ícone, rótulo, atalho do desenho */
    public static function itensDoDesenho(): iterable
    {
        yield 'Recortar' => ['bi-scissors', 'Recortar', 'Ctrl+X'];
        yield 'Copiar' => ['bi-copy', 'Copiar', 'Ctrl+C'];
        yield 'Colar' => ['bi-clipboard', 'Colar', 'Ctrl+V'];
        yield 'Colar somente texto' => ['bi-clipboard-check', 'Colar somente texto', 'Ctrl+Shift+V'];
        yield 'Localizar e substituir' => ['bi-search', 'Localizar e substituir', 'Ctrl+H'];
        yield 'Revisar texto' => ['bi-spellcheck', 'Revisar texto', ''];
        yield 'Selecionar tudo' => ['bi-check2-all', 'Selecionar tudo', 'Ctrl+A'];
    }

    #[DataProvider('itensDoDesenho')]
    #[TestDox('o item $_dataName tem o ícone, o rótulo e o atalho do desenho')]
    public function testItemDoDesenho(string $icone, string $rotulo, string $atalho): void
    {
        self::assertStringContainsString(
            "itemDoMenu('{$icone}', '{$rotulo}', '{$atalho}',",
            $this->funcao('abrirMenuContexto')
        );
    }

    #[TestDox('os itens seguem a ordem do desenho')]
    public function testOrdemDosItens(): void
    {
        $menu = $this->funcao('abrirMenuContexto');
        $posicoes = array_map(
            static fn (string $r): int|false => strpos($menu, "'" . $r . "'"),
            ['Negrito', 'Limpar formatação', 'Recortar', 'Copiar', 'Colar', 'Colar somente texto', 'Parágrafo', 'À esquerda', 'Aumentar recuo', 'Localizar e substituir', 'Revisar texto', 'Selecionar tudo']
        );
        self::assertNotContains(false, $posicoes);
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes);
    }

    #[TestDox('C6-EDT-05: o bloco "Sugestões de correção" vem depois da mini barra e antes de Recortar')]
    public function testSugestoesDeCorrecaoAntesDeRecortar(): void
    {
        $menu = $this->funcao('abrirMenuContexto');
        $bloco = strpos($menu, "cabecalhoDaJanela('Sugestões de correção')");

        self::assertNotFalse($bloco, 'bloco "Sugestões de correção" ausente do menu');
        self::assertGreaterThan(strpos($menu, "'Limpar formatação'"), $bloco);
        self::assertLessThan(strpos($menu, "'Recortar'"), $bloco);
        self::assertStringContainsString('var correcoes = palavra ? correcoesDoMenu(palavra.texto) : [];', $menu);
        self::assertStringContainsString('if (correcoes.length) {', $menu, 'só aparece quando há correção');
    }

    #[TestDox('C6-EDT-05: cada sugestão tem o ícone do desenho, fica verde e troca a palavra num passo do histórico')]
    public function testSugestaoDeCorrecaoTrocaAPalavra(): void
    {
        $menu = $this->funcao('abrirMenuContexto');
        $ini = (int) strpos($menu, "cabecalhoDaJanela('Sugestões de correção')");
        $trecho = substr($menu, $ini, (int) strpos($menu, "'Recortar'") - $ini);

        self::assertStringContainsString("itemDoMenu('bi-spellcheck', correcao, '', false, executar(function () {", $trecho);
        self::assertStringContainsString('trocarPorSinonimo(quill, palavra, correcao);', $trecho);
        self::assertStringContainsString("item.classList.add('editor-rico-menu-correcao');", $trecho);
        self::assertStringContainsString('trocarTrechos(quill, [', $this->funcao('trocarPorSinonimo'));
        self::assertMatchesRegularExpression(
            '/\.editor-rico-menu-correcao,\s*\.editor-rico-menu-correcao i \{ color: var\(--er-revisao-ok, #186c47\); \}/',
            (string) file_get_contents(self::CSS)
        );
        self::assertMatchesRegularExpression('/\.editor-rico-menu-correcao span \{ font-weight: 700; \}/', (string) file_get_contents(self::CSS));
    }

    #[TestDox('C6-EDT-05: as correções seguem a regra do desenho e só usam o dicionário local')]
    public function testCorrecoesDoMenuSeguemODesenho(): void
    {
        $f = $this->funcao('correcoesDoMenu');

        self::assertStringContainsString('Object.prototype.hasOwnProperty.call(AUTOCORRECAO, minuscula)', $f);
        self::assertStringContainsString('VOCABULARIO_DOBRADO.get(dobrada)', $f);
        // Palavra conhecida e já certa encerra sem sugestão: sem o `: []` ela cairia na busca aproximada
        // e se sugeriria a si mesma (distância 0) em toda palavra correta do vocabulário.
        self::assertStringContainsString('return conhecida !== minuscula && conhecida !== palavra ? [mesmaCaixa(palavra, conhecida)] : [];', $f);
        self::assertStringContainsString('if (palavra.length < 5) { return []; }', $f);
        self::assertStringContainsString('var teto = dobrada.length >= 9 ? 2 : 1;', $f);
        self::assertStringContainsString('saida.length < 3 && vd[0] === dobrada[0] && distancia(dobrada, vd, teto) <= teto', $f);
        // Nada sai do navegador: nem rede, nem janela externa.
        foreach (['fetch(', 'XMLHttpRequest', 'window.open', 'http'] as $proibido) {
            self::assertStringNotContainsString($proibido, $f);
        }
    }

    /** @return iterable<string, array{string, string}> rótulo, chamada real do editor */
    public static function acoesReais(): iterable
    {
        yield 'Negrito' => ['Negrito', "quill.format('bold', !formatos.bold, 'user')"];
        yield 'Tachado' => ['Tachado', "quill.format('strike', !formatos.strike, 'user')"];
        yield 'Realce' => ['Realçar com a última cor', "quill.format('background', realce, 'user')"];
        yield 'Cor' => ['Cor da fonte (última usada)', "quill.format('color', cor, 'user')"];
        yield 'Marcadores' => ['Marcadores', "quill.format('list', formatos.list === 'bullet' ? false : 'bullet', 'user')"];
        yield 'Título' => ['Título', "quill.format('header', formatos.header === 2 ? false : 2, 'user')"];
        yield 'Limpar' => ['Limpar formatação', 'limparFormatacao(quill, quill.getSelection())'];
        yield 'Justificado' => ['Justificado', "quill.format('align', 'justify', 'user')"];
        yield 'Recuo' => ['Aumentar recuo', 'recuar(quill, 1)'];
        yield 'Localizar' => ['Localizar e substituir', 'refs.busca.abrir()'];
        yield 'Revisar' => ['Revisar texto', 'conteudoDaRevisao(refs)'];
        yield 'Selecionar tudo' => ['Selecionar tudo', "quill.setSelection(0, Math.max(quill.getLength() - 1, 0), 'user')"];
    }

    #[DataProvider('acoesReais')]
    #[TestDox('o item "$_dataName" aciona a função que o editor já tem')]
    public function testItemAcionaFuncaoReal(string $rotulo, string $chamada): void
    {
        $menu = $this->funcao('abrirMenuContexto');
        $ini = strpos($menu, "'" . $rotulo . "'");
        self::assertNotFalse($ini);
        $linha = substr($menu, $ini, (int) strpos($menu, "\n", $ini) - $ini);
        // A chamada pode vir na mesma linha ou na seguinte (itens de várias linhas).
        $trecho = $linha . substr($menu, $ini + strlen($linha), 200);
        self::assertStringContainsString($chamada, $trecho);
    }

    #[TestDox('Recortar e Copiar usam a Clipboard API e caem no comando do navegador sem ela')]
    public function testCopiarUsaClipboardApi(): void
    {
        $copiar = $this->funcao('copiarSelecao');

        self::assertStringContainsString('api.write([new ClipboardItem({', $copiar);
        self::assertStringContainsString('api.writeText(texto)', $copiar);
        self::assertStringContainsString("document.execCommand(recortar ? 'cut' : 'copy')", $copiar);
        self::assertStringContainsString("quill.deleteText(faixa.index, faixa.length, 'user')", $copiar, 'recortar apaga só DEPOIS de copiar');
    }

    #[TestDox('Colar respeita a permissão do navegador: sem ela, a dica do desenho e nada colado')]
    public function testColarSegueAPermissao(): void
    {
        $colar = $this->funcao('colarDaAreaDeTransferencia');

        self::assertStringContainsString("'Use Ctrl+V para colar (o navegador bloqueou o acesso direto)'", $colar);
        self::assertStringContainsString('if (!api || !api.readText) { bloqueado(); return; }', $colar);
        self::assertStringContainsString('api.readText().then(function (t) { colarConteudo(refs, faixa, \'\', t); }, bloqueado)', $colar);
    }

    #[TestDox('o HTML colado pelo menu passa pelo conversor do Quill, nunca por innerHTML')]
    public function testColarHtmlPassaPeloConversor(): void
    {
        $conteudo = $this->funcao('colarConteudo');

        self::assertStringContainsString("quill.clipboard.convert({ html: html, text: texto || '' })", $conteudo);
        self::assertStringNotContainsString('innerHTML', $conteudo);
        self::assertStringNotContainsString('innerHTML', $this->funcao('colarDaAreaDeTransferencia'));
    }

    #[TestDox('innerHTML no menu só recebe ícone constante, nunca rótulo ou dado')]
    public function testInnerHtmlSoComIcone(): void
    {
        foreach (['abrirMenuContexto', 'botaoDoMenu', 'itemDoMenu', 'iconeComCor'] as $nome) {
            preg_match_all('/\.innerHTML = ([^;]+);/', $this->funcao($nome), $m);
            foreach ($m[1] as $valor) {
                self::assertMatchesRegularExpression('/^(icone\(\w+\)|iconeHtml)$/', trim($valor), "{$nome}: innerHTML com {$valor}");
            }
        }
        // O rótulo entra como texto.
        self::assertStringContainsString("b.appendChild(el('span', '', rotulo));", $this->funcao('itemDoMenu'));
    }

    #[TestDox('só o botão direito do mouse abre o menu; Shift, teclado e toque ficam com o do navegador')]
    public function testSoMouseAbreOMenu(): void
    {
        $ligar = $this->funcao('ligarMenuContexto');

        self::assertStringContainsString("e.pointerType === 'mouse' && direito", $ligar);
        self::assertStringContainsString('if (e.shiftKey || !doMouse) { return; }', $ligar);
        self::assertStringContainsString('e.preventDefault();', $ligar);
        self::assertStringContainsString('ligarMenuContexto(refs);', $this->funcao('montar'));
    }

    #[TestDox('Esc fecha o menu sem fechar o modal em volta; desmontar o editor fecha o menu')]
    public function testEscFechaOMenu(): void
    {
        $menu = $this->funcao('abrirMenuContexto');

        self::assertMatchesRegularExpression("/if \\(e\\.key !== 'Escape'\\) \\{ return; \\}\\s+e\\.preventDefault\\(\\);\\s+e\\.stopPropagation\\(\\);\\s+fecharMenuContexto\\(refs\\);/", $menu);
        self::assertStringContainsString("document.addEventListener('keydown', estado.tecla, true);", $menu);
        self::assertStringContainsString('fecharMenuContexto(refs);', $this->funcao('desmontar'));
    }

    #[TestDox('a última cor e o último realce da barra alimentam a mini barra (padrão vermelho/amarelo do desenho)')]
    public function testUltimaCorLembrada(): void
    {
        $js = $this->js();

        self::assertStringContainsString("var COR_INICIAL = 'vermelho';", $js);
        self::assertStringContainsString("var REALCE_INICIAL = 'amarelo';", $js);
        self::assertStringContainsString('if (valor) { refs.ultimaCor = valor; }', $js);
        self::assertStringContainsString('if (valor) { refs.ultimoRealce = valor; }', $js);
    }

    /** @return iterable<string, array{string}> */
    public static function classesDoMenu(): iterable
    {
        foreach (['editor-rico-menu', 'editor-rico-menu-barra', 'editor-rico-menu-barra-paragrafo', 'editor-rico-menu-botao', 'editor-rico-menu-cor', 'editor-rico-menu-item', 'editor-rico-menu-atalho', 'editor-rico-menu-separador'] as $classe) {
            yield $classe => [$classe];
        }
    }

    #[DataProvider('classesDoMenu')]
    #[TestDox('a classe $_dataName do menu tem regra de CSS')]
    public function testClasseTemRegra(string $classe): void
    {
        self::assertMatchesRegularExpression('/\.' . preg_quote($classe, '/') . '\s*\{/', (string) file_get_contents(self::CSS));
    }

    #[TestDox('o menu tem as medidas do desenho (.bjed-cm: min 250px, padding 5px; item 6px 10px, 13px)')]
    public function testMedidasDoDesenho(): void
    {
        $css = (string) file_get_contents(self::CSS);

        self::assertMatchesRegularExpression('/\.editor-rico-menu \{[^}]*min-width: 250px;[^}]*padding: 5px;/', $css);
        self::assertMatchesRegularExpression('/\.editor-rico-menu-item \{[^}]*padding: 6px 10px;[^}]*font-size: 13px;/', $css);
        self::assertMatchesRegularExpression('/\.editor-rico-menu-botao \{[^}]*width: 28px;[^}]*height: 26px;/', $css);
    }
}
