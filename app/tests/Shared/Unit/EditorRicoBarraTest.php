<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\SanitizadorTextoRico;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Arquitetura da barra do editor (`public/js/editor-rico.js`) contra o desenho (bj-editor.js,
 * Claude Design 2026-10-05): os botões de símbolos, data de hoje e revisão existem, cada um tem
 * a sua ação, a janela/aviso tem regra de CSS, e o que eles inserem (só TEXTO) passa pelo
 * sanitizador sem precisar mudá-lo.
 *
 * Teste estático (lê o JS e o CSS): não executa o editor. Comportamento visual é do smoke.
 */
#[CoversClass(SanitizadorTextoRico::class)]
final class EditorRicoBarraTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const JS = __DIR__ . '/../../../public/js/editor-rico.js';
    private const CSS = __DIR__ . '/../../../public/css/editor-rico.css';

    /** Os símbolos de `popSimbolo` do desenho, na mesma ordem. */
    private const SIMBOLOS_DO_DESENHO = ['§', 'º', 'ª', 'nº', 'R$', '°', '½', '¼', '©', '®', '™', '€', '•', '✓', '«', '»', '±', '×'];

    /** @return iterable<string, array{string}> */
    public static function botoesNovos(): iterable
    {
        yield 'símbolos' => ['simbolo'];
        yield 'data de hoje' => ['data'];
        yield 'revisão' => ['revisar'];
    }

    #[DataProvider('botoesNovos')]
    #[TestDox('o botão $_dataName está na barra, tem ação e dica')]
    public function testBotaoEstaNaBarraComAcaoEDica(string $nome): void
    {
        $js = $this->js();

        self::assertSame(1, preg_match('/var BARRA = \[(.*?)\n    \];/s', $js, $m), 'BARRA não encontrada');
        self::assertStringContainsString("'" . $nome . "'", $m[1], "botão {$nome} fora da barra");

        // Sem handler, o Quill ignora o botão (não é formato registrado) — botão morto.
        self::assertMatchesRegularExpression('/\n\s+' . $nome . ': function \(\) \{/', $js, "botão {$nome} sem ação");
        self::assertMatchesRegularExpression("/'ql-" . $nome . "': '[^']+'/", $js, "botão {$nome} sem dica");
    }

    #[TestDox('a ordem dos grupos segue o desenho: citação, símbolo e data; depois revisão, localizar e limpar')]
    public function testOrdemDosGruposSegueODesenho(): void
    {
        $js = $this->js();

        self::assertStringContainsString("['blockquote', 'simbolo', 'data'],", $js);
        self::assertStringContainsString("['revisar', 'localizar', 'clean'],", $js);
    }

    #[TestDox('os símbolos são exatamente os do desenho')]
    public function testSimbolosSaoOsDoDesenho(): void
    {
        self::assertSame(self::SIMBOLOS_DO_DESENHO, $this->simbolosDoJs());
    }

    #[TestDox('cada símbolo inserido sobrevive ao sanitizador como texto')]
    public function testSimbolosSobrevivemAoSanitizador(): void
    {
        $sanitizador = $this->criarSanitizadorTextoRico();

        foreach ($this->simbolosDoJs() as $simbolo) {
            $limpo = html_entity_decode((string) $sanitizador->limpar('<p>art. 5' . $simbolo . ' fim</p>'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertStringContainsString('5' . $simbolo . ' fim', $limpo, "{$simbolo} é descartado pelo sanitizador");
        }
    }

    #[TestDox('a data de hoje sai no formato pt-BR do desenho')]
    public function testDataDeHojeNoFormatoDoDesenho(): void
    {
        self::assertStringContainsString("new Date().toLocaleDateString('pt-BR')", $this->js());
    }

    #[TestDox('a autocorreção traz o dicionário do desenho')]
    public function testAutocorrecaoTrazODicionarioDoDesenho(): void
    {
        $js = $this->js();

        self::assertSame(1, preg_match('/var AUTOCORRECAO = \{(.*?)\n    \};/s', $js, $m), 'AUTOCORRECAO não encontrada');
        foreach (["nao: 'não'", "vc: 'você'", "peticao: 'petição'", "concerteza: 'com certeza'", "brasilia: 'Brasília'"] as $par) {
            self::assertStringContainsString($par, $m[1]);
        }
        self::assertStringContainsString('var VOCABULARIO_EXTRA = [', $js);
    }

    #[TestDox('o que a revisão e a autocorreção trocam continua sendo texto: nenhum style inline sai do editor')]
    public function testNenhumStyleInlineNoConteudo(): void
    {
        // As trocas usam `updateContents` com os formatos de trecho (classes ql-*); `style` o
        // sanitizador barra. Uma inserção de HTML com style seria descartada ao salvar.
        $js = $this->js();

        self::assertStringNotContainsString('dangerouslyPasteHTML(\'<', $js);
        self::assertStringNotContainsString('insertHTML', $js);
    }

    /** @return iterable<string, array{string}> */
    public static function classesComRegra(): iterable
    {
        foreach (['editor-rico-janela', 'editor-rico-janela-titulo', 'editor-rico-simbolos', 'editor-rico-revisao', 'editor-rico-revisao-item', 'editor-rico-revisao-ok', 'editor-rico-chip', 'editor-rico-icone-simbolo'] as $classe) {
            yield $classe => [$classe];
        }
    }

    #[DataProvider('classesComRegra')]
    #[TestDox('a classe $_dataName usada pelo editor tem regra de CSS')]
    public function testClasseTemRegraDeCss(string $classe): void
    {
        self::assertMatchesRegularExpression('/[\'"]' . preg_quote($classe, '/') . '[\'" ]/', $this->js(),"{$classe} não é usada no editor-rico.js");
        self::assertMatchesRegularExpression('/\.' . preg_quote($classe, '/') . '\s*\{/', (string) file_get_contents(self::CSS), "{$classe} sem regra no editor-rico.css");
    }

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    /** @return list<string> */
    private function simbolosDoJs(): array
    {
        self::assertSame(1, preg_match('/var SIMBOLOS = \[([^\]]*)\];/u', $this->js(), $m), 'SIMBOLOS não encontrada');
        preg_match_all("/'([^']*)'/u", $m[1], $nomes);

        return $nomes[1];
    }
}
