<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\SanitizadorTextoRico;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Contrato entre o editor (`public/js/editor-rico.js`), o servidor (`SanitizadorTextoRico`) e a
 * exibição (`public/css/editor-rico.css`): toda cor/realce que a barra oferece tem de SOBREVIVER
 * ao sanitizador e ter regra de CSS — senão é função fake: aparece ao editar e some ao salvar (ou
 * salva e não pinta nada na tela).
 *
 * A paleta é lida do próprio JS, então acrescentar uma cor sem a regra de CSS (ou com um nome que
 * o sanitizador descarta, como um hexadecimal) derruba este teste.
 */
#[CoversClass(SanitizadorTextoRico::class)]
final class EditorRicoPaletaTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const JS = __DIR__ . '/../../../public/js/editor-rico.js';
    private const CSS = __DIR__ . '/../../../public/css/editor-rico.css';

    #[TestDox('cada cor da fonte da barra sobrevive ao sanitizador e tem regra de exibição')]
    public function testPaletaDeTextoSobreviveESeExibe(): void
    {
        $this->conferirPaleta('PALETA_TEXTO', 'ql-color-', 'color');
    }

    #[TestDox('cada cor de realce da barra sobrevive ao sanitizador e tem regra de exibição')]
    public function testPaletaDeRealceSobreviveESeExibe(): void
    {
        $this->conferirPaleta('PALETA_REALCE', 'ql-bg-', 'background-color');
    }

    #[TestDox('o realce está entre os formatos aceitos pelo editor')]
    public function testRealceEstaEntreOsFormatos(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertMatchesRegularExpression('/var FORMATOS = \[[^\]]*\'background\'/s', $js);
        // Sem o atributo por classe o Quill gravaria `style="background-color:…"`, que o servidor barra.
        self::assertStringContainsString("Quill.import('attributors/class/background')", $js);
    }

    private function conferirPaleta(string $variavel, string $prefixo, string $propriedade): void
    {
        $js = (string) file_get_contents(self::JS);
        $css = (string) file_get_contents(self::CSS);

        self::assertSame(1, preg_match('/var ' . $variavel . ' = \[([^\]]*)\]/', $js, $m), "{$variavel} não encontrada no editor-rico.js");
        preg_match_all("/'([^']*)'/", $m[1], $nomes);
        self::assertNotEmpty($nomes[1], "{$variavel} sem cores");

        $sanitizador = $this->criarSanitizadorTextoRico();

        foreach ($nomes[1] as $nome) {
            $classe = $prefixo . $nome;

            $limpo = (string) $sanitizador->limpar('<p><span class="' . $classe . '">texto</span></p>');
            self::assertStringContainsString('class="' . $classe . '"', $limpo, "{$classe} é descartada pelo sanitizador");

            self::assertMatchesRegularExpression(
                '/\.ql-editor \.' . preg_quote($classe, '/') . ' \{ ' . preg_quote($propriedade, '/') . ':/',
                $css,
                "{$classe} não tem regra de exibição no editor-rico.css",
            );
        }
    }
}
