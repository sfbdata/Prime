<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\SanitizadorTextoRico;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-017 (docs/specs/engineering-loop/inventario-editor.md): colar com opções, como o
 * `opcoesColar` do desenho (bj-editor.js l.948-957) — depois de colar HTML, a faixa
 * "Colar · Manter formatação · Mesclar formatação · Somente texto" por 7 s.
 *
 * Teste de FOLHA (lê o JS e o CSS; sem navegador) + sanitizador real. Trava: a colagem continua
 * passando pelo conversor do Quill (a sanitização do cliente que o editor já usa), as opções não
 * leem nem escrevem HTML, e o servidor ainda limpa o que um colado hostil traria.
 */
#[CoversClass(SanitizadorTextoRico::class)]
final class EditorRicoColarTest extends TestCase
{
    use CriaSanitizadorTextoRico;

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

    #[TestDox('a faixa tem os rótulos do desenho, na ordem, e dura 7 s')]
    public function testRotulosDoDesenho(): void
    {
        $mostrar = $this->funcao('mostrarOpcoesDeColar');

        self::assertStringContainsString("[['manter', 'Manter formatação'], ['mesclar', 'Mesclar formatação'], ['texto', 'Somente texto']]", $mostrar);
        self::assertStringContainsString("icone('bi-clipboard editor-rico-colar-icone')", $mostrar);
        self::assertStringContainsString("document.createTextNode('Colar')", $mostrar);
        self::assertStringContainsString('var DURACAO_OPCOES_DE_COLAR = 7000;', $this->js());
    }

    #[TestDox('a colagem continua sendo a do Quill (conversor com os formatos do editor): o editor só observa')]
    public function testColagemPassaPeloConversorDoQuill(): void
    {
        $js = $this->js();
        $aoColar = $this->funcao('aoColar');

        // Captura na casca: chega antes do Quill, sem cancelar a colagem dele.
        self::assertStringContainsString("casca.addEventListener('paste', function (evento) { aoColar(refs, evento); }, true);", $js);
        self::assertStringNotContainsString('preventDefault', $aoColar);
        self::assertStringContainsString('formats: FORMATOS,', $js, 'o conversor do Quill filtra pelos formatos do editor');
        // Só HTML abre as opções (o desenho também ignora texto puro).
        self::assertStringContainsString("if (!html) { return; }", $aoColar);
    }

    #[TestDox('nenhuma opção lê ou escreve HTML: Mesclar tira cor e realce; Somente texto insere TEXTO')]
    public function testOpcoesNaoMexemEmHtml(): void
    {
        $aplicar = $this->funcao('aplicarOpcaoDeColar');

        self::assertStringContainsString("quill.formatText(faixa.index, faixa.length, { color: false, background: false }, 'user')", $aplicar);
        self::assertStringContainsString('troca.insert(c.texto, c.formatos)', $aplicar);
        self::assertStringContainsString("getData('text/plain')", $this->funcao('aoColar'));

        foreach (['aoColar', 'aplicarOpcaoDeColar'] as $nome) {
            self::assertStringNotContainsString('innerHTML', $this->funcao($nome), "{$nome} mexe em innerHTML");
            self::assertStringNotContainsString('dangerouslyPasteHTML', $this->funcao($nome));
        }
        // Na faixa, innerHTML só recebe o ícone constante.
        preg_match_all('/\.innerHTML = ([^;]+);/', $this->funcao('mostrarOpcoesDeColar'), $m);
        self::assertSame(["icone('bi-clipboard editor-rico-colar-icone')"], $m[1]);
    }

    #[TestDox('mexer no texto, Esc ou desmontar fecham a faixa (a opção vale só para a colagem recém-feita)')]
    public function testFaixaFecha(): void
    {
        $js = $this->js();

        self::assertSame(1, preg_match("/quill\\.on\\('text-change', function \\(delta, anterior, origem\\) \\{(.*?)\n        \\}\\);/s", $js, $m));
        self::assertStringContainsString('fecharOpcoesDeColar(refs);', $m[1]);
        self::assertStringContainsString("(refs.janela || refs.chip || refs.colagem)", $js);
        self::assertStringContainsString('fecharOpcoesDeColar(refs);', $this->funcao('desmontar'));
    }

    #[TestDox('o servidor sanitiza de novo o que vem colado de fora (style, script e eventos caem)')]
    public function testServidorSanitizaOColado(): void
    {
        $colado = '<p style="color:#ff0000" onclick="alert(1)"><b>Prazo</b> em <span style="background:yellow" class="MsoNormal">5 dias</span></p><script>alert(2)</script><img src=x onerror=alert(3)>';
        $limpo = (string) $this->criarSanitizadorTextoRico()->limpar($colado);

        self::assertStringContainsString('<b>Prazo</b>', $limpo);
        self::assertStringContainsString('5 dias', $limpo);
        foreach (['style', 'onclick', '<script', 'alert', '<img', 'onerror', 'MsoNormal'] as $proibido) {
            self::assertStringNotContainsString($proibido, $limpo);
        }
    }

    #[TestDox('a faixa tem as medidas e cores do desenho (.bjed-paste)')]
    public function testCssDoDesenho(): void
    {
        $css = (string) file_get_contents(self::CSS);

        self::assertMatchesRegularExpression('/\.editor-rico-colar \{[^}]*position: fixed;[^}]*padding: 3px;[^}]*background: #12242f;/', $css);
        self::assertMatchesRegularExpression('/\.editor-rico-colar button \{[^}]*height: 24px;[^}]*padding: 0 8px;[^}]*font: 600 11px Arial/', $css);
        self::assertMatchesRegularExpression('/\.editor-rico-colar-rotulo \{[^}]*color: #9fb7c6;/', $css);
    }
}
