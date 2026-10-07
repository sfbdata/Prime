<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-015 (docs/specs/engineering-loop/inventario-editor.md): sugestão de palavras ao digitar,
 * como `sugerir`/`onKey` do desenho (bj-editor.js l.640-680) — a partir de 3 letras, até 4
 * palavras do vocabulário, prefixo marcado, Tab aceita, ↑/↓ navegam, Enter aceita depois de
 * navegar, Esc fecha. Observação 3 do inventário: com a lista de @menção aberta, ELA vence o Tab.
 *
 * Teste de FOLHA (lê o JS e o CSS; sem navegador). O comportamento na tela é do smoke.
 */
final class EditorRicoSugestaoTest extends TestCase
{
    private const APP = __DIR__ . '/../../..';
    private const JS = self::APP . '/public/js/editor-rico.js';
    private const MENCOES = self::APP . '/public/js/pasta-mencoes.js';
    private const CSS = self::APP . '/public/css/editor-rico.css';

    private function ler(string $caminho): string
    {
        return (string) file_get_contents($caminho);
    }

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $fonte, string $nome): string
    {
        $ini = strpos($fonte, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($fonte, "\n    }", $ini);

        return substr($fonte, $ini, $fim - $ini);
    }

    #[TestDox('as regras do desenho: 3 letras, até 4 palavras, prefixo sem acento, sem repetir a digitada')]
    public function testRegrasDoDesenho(): void
    {
        $js = $this->ler(self::JS);

        self::assertStringContainsString('var MAX_SUGESTOES = 4;', $js);
        self::assertStringContainsString('var MIN_LETRAS_SUGESTAO = 3;', $js);
        $opcoes = $this->funcao($js, 'opcoesDeSugestao');
        self::assertStringContainsString('vd.indexOf(dobrada) === 0 && vd !== dobrada', $opcoes);
        self::assertStringContainsString('.slice(0, MAX_SUGESTOES)', $opcoes);
    }

    #[TestDox('o vocabulário é o dicionário estático do desenho (AC + EXTRA), sem dado de cliente')]
    public function testVocabularioEstatico(): void
    {
        $js = $this->ler(self::JS);

        self::assertStringContainsString('return VOCABULARIO.filter(', $this->funcao($js, 'opcoesDeSugestao'));
        // O vocabulário sai só das duas listas fixas do arquivo (o `VOCAB` do desenho).
        self::assertSame(1, preg_match('/var VOCABULARIO = (.*?);\n/s', $js, $m));
        self::assertStringContainsString('Object.keys(AUTOCORRECAO)', $m[1]);
        self::assertStringContainsString('.concat(VOCABULARIO_EXTRA)', $m[1]);
        self::assertStringNotContainsString('fetch(', $this->funcao($js, 'sugerir'));
    }

    #[TestDox('Tab aceita, ↑/↓ navegam, Enter só aceita depois de navegar, Esc fecha — antes do teclado do Quill')]
    public function testTeclasDoDesenho(): void
    {
        $js = $this->ler(self::JS);
        $tecla = $this->funcao($js, 'teclaDaSugestao');

        self::assertStringContainsString("e.key === 'ArrowDown' || e.key === 'ArrowUp'", $tecla);
        self::assertStringContainsString("e.key === 'Tab' || (e.key === 'Enter' && s.nav && !ctrl)", $tecla);
        self::assertStringContainsString("e.key === 'Escape'", $tecla);
        self::assertGreaterThanOrEqual(3, substr_count($tecla, 'e.stopImmediatePropagation();'));
        // Captura na RAIZ: antes do teclado do Quill, depois da @menção (que ouve no contêiner).
        self::assertStringContainsString("quill.root.addEventListener('keydown', function (evento) { teclaDaSugestao(refs, evento); }, true);", $js);
    }

    #[TestDox('com a lista de @menção aberta, ela tem prioridade sobre o Tab (e a sugestão se recolhe)')]
    public function testMencaoTemPrioridadeSobreOTab(): void
    {
        $js = $this->ler(self::JS);
        $tecla = $this->funcao($js, 'teclaDaSugestao');

        // Antes de tratar qualquer tecla, a sugestão cede à menção aberta.
        $cede = strpos($tecla, 'if (mencaoAberta(refs.quill)) { fecharSugestoes(refs); return; }');
        $tab = strpos($tecla, "e.key === 'Tab'");
        self::assertNotFalse($cede);
        self::assertNotFalse($tab);
        self::assertLessThan($tab, $cede, 'a checagem da menção vem antes do Tab');
        self::assertStringContainsString('if (!quill || mencaoAberta(quill)) { fecharSugestoes(refs); return; }', $this->funcao($js, 'sugerir'));
        self::assertStringContainsString("if (texto.charAt(inicio - 1) === '@') { return null; }", $this->funcao($js, 'palavraNoCursor'));

        // Do lado da menção: a lista expõe o estado e segue parando a propagação no contêiner.
        $mencoes = $this->ler(self::MENCOES);
        self::assertStringContainsString('return !!pop && (!quill || estado.quill === quill);', $this->funcao($mencoes, 'listaAberta'));
        self::assertStringContainsString('listaAberta: listaAberta', $mencoes);
        self::assertMatchesRegularExpression("/\\(ev\\.key === 'Enter' && !ev\\.ctrlKey && !ev\\.metaKey\\) \\|\\| ev\\.key === 'Tab'\\) \\{\\s+ev\\.preventDefault\\(\\);\\s+ev\\.stopImmediatePropagation\\(\\);/", $mencoes);
        self::assertStringContainsString("quill.container.addEventListener('keydown', function (ev) {", $mencoes);
    }

    #[TestDox('a lista é montada com texto, sem innerHTML, e a palavra aceita entra como troca de texto')]
    public function testSemInnerHtml(): void
    {
        $js = $this->ler(self::JS);
        $sugerir = $this->funcao($js, 'sugerir');

        self::assertStringNotContainsString('innerHTML', $sugerir);
        self::assertStringContainsString("corpo.appendChild(el('mark', '', palavra.slice(0, w.palavra.length)));", $sugerir);
        self::assertStringContainsString("el('span', 'editor-rico-menu-atalho', 'Tab')", $sugerir);
        $aceitar = $this->funcao($js, 'aceitarSugestao');
        self::assertStringContainsString('trocarTrechos(quill, [{ inicio: w.inicio, tamanho: w.palavra.length, para: para }])', $aceitar);
        self::assertStringNotContainsString('innerHTML', $aceitar);
    }

    #[TestDox('só digitar/apagar uma letra abre a lista; o resto (separador, colagem, API) fecha')]
    public function testQuandoAbre(): void
    {
        $js = $this->ler(self::JS);

        self::assertStringContainsString("if (origem === 'user' && !cortou && digitouOuApagouLetra(delta)) {", $js);
        self::assertStringContainsString("/^[\\p{L}\\p{M}]$/u.test(op.insert)) || op.delete === 1", $this->funcao($js, 'digitouOuApagouLetra'));
        self::assertStringContainsString('fecharSugestoes(refs);', $this->funcao($js, 'desmontar'));
    }

    #[TestDox('a lista tem as medidas do desenho (.bjed-sug: min 170px, padding 4px; item 6px 9px; prefixo azul em negrito)')]
    public function testCssDoDesenho(): void
    {
        $css = $this->ler(self::CSS);

        self::assertMatchesRegularExpression('/\.editor-rico-sugestoes \{[^}]*min-width: 170px;[^}]*padding: 4px;/', $css);
        self::assertMatchesRegularExpression('/\.editor-rico-sugestao \{[^}]*padding: 6px 9px;/', $css);
        self::assertMatchesRegularExpression('/\.editor-rico-sugestao mark \{[^}]*background: transparent;[^}]*color: var\(--er-acento\);[^}]*font-weight: 700;/', $css);
    }
}
