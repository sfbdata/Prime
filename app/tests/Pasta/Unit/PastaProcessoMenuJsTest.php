<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O ⋮ do processo (`public/js/pasta-processo.js`) lido como FOLHA: sem navegador no PHPUnit, o
 * que se prova é o caminho de cada item novo e que nada é montado em HTML de string.
 */
#[Group('pasta')]
final class PastaProcessoMenuJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-processo.js';

    private function js(): string
    {
        $js = file_get_contents(self::JS);
        self::assertIsString($js);

        return $js;
    }

    private function funcao(string $nome): string
    {
        $js     = $this->js();
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, "a função {$nome} existe");
        $fim = strpos($js, "\n    }\n", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('EDT-005: "Imprimir resumo" monta a tabela das MESMAS linhas do resumo, por DOM e textContent, e abre a janela da pasta')]
    public function testImprimirResumo(): void
    {
        $f = $this->funcao('imprimirResumo');

        self::assertStringContainsString('linhasDoResumo(item)', $f);
        self::assertStringContainsString("document.createElement('table')", $f);
        self::assertStringContainsString("document.createElement('strong')", $f);
        self::assertStringContainsString('forte.textContent', $f);
        self::assertStringContainsString('valor.textContent', $f);
        self::assertStringContainsString("linha.indexOf(': ')", $f, 'rótulo e valor partidos no primeiro ": ", como no desenho');
        self::assertStringContainsString('window.PastaImpressao.conteudo(', $f);
        self::assertStringContainsString("item.getAttribute('data-titulo')", $f);

        self::assertStringContainsString("item.classList.contains('js-proc-imprimir'))       { imprimirResumo(item); return; }", $this->js());
        self::assertSame(0, preg_match('/\.(innerHTML|outerHTML)\b|insertAdjacentHTML/', $this->js()), 'nada de HTML em string no ⋮ do processo');
    }

    #[TestDox('EDT-006: sem navigator.share, Compartilhar copia o resumo pelo caminho do "Copiar resumo" — e o menu não esconde mais o item')]
    public function testCompartilharSemWebShare(): void
    {
        $f = $this->funcao('compartilhar');

        self::assertStringContainsString("if (typeof navigator.share !== 'function') {\n            copiar(item);\n            return;\n        }", $f);
        self::assertStringContainsString('navigator.share({', $f);

        $preparar = $this->funcao('preparar');
        self::assertStringNotContainsString('js-proc-compartilhar', $preparar, 'o item não é mais escondido na abertura');
        self::assertStringNotContainsString('.hidden', $preparar);
        // O aviso é o `data-aviso` do item (copiar), que o template preenche com o texto do desenho.
        self::assertStringContainsString("item.getAttribute('data-aviso')", $this->funcao('copiar'));
    }
}
