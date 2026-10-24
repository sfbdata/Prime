<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `public/js/pasta-foto-cliente.js` lido como FOLHA: não há navegador no PHPUnit, então o que se prova é que ele
 * não monta HTML, que as classes e atributos que ele lê são os que o partial entrega, e que o recorte salvo é o do
 * desenho (JPEG 320x320, qualidade 0,88) enviado nos campos que o controller lê.
 */
#[Group('pasta')]
final class PastaFotoClienteJsTest extends TestCase
{
    private const JS      = __DIR__ . '/../../../public/js/pasta-foto-cliente.js';
    private const PARTIAL = __DIR__ . '/../../../templates/pasta/_cabecalho_foto_cliente.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('Não monta HTML: sem innerHTML, outerHTML, insertAdjacentHTML nem document.write')]
    public function testSemInnerHtml(): void
    {
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $this->js(), "{$proibido} não pode aparecer");
        }
        self::assertStringContainsString('textContent', $this->js());
    }

    #[TestDox('Toda classe js-ps-foto* e todo data-ps-foto-* que o JS lê existe no partial do cabeçalho')]
    public function testContratoComOPartial(): void
    {
        preg_match_all('/(?:js-ps-foto[a-z-]*|data-ps-foto-[a-z-]+)/', $this->js(), $m);
        $usados = array_values(array_unique($m[0]));
        self::assertNotEmpty($usados);
        $partial = (string) file_get_contents(self::PARTIAL);
        foreach ($usados as $nome) {
            self::assertMatchesRegularExpression('/[\s"]' . preg_quote($nome, '/') . '[\s">{]/', $partial, "{$nome} é contrato com o partial");
        }
        foreach (['data-token', 'data-url-trocar', 'data-url-remover', 'data-tem-foto'] as $atributo) {
            self::assertStringContainsString("getAttribute('{$atributo}')", $this->js());
            self::assertStringContainsString($atributo . '="', $partial);
        }
    }

    #[TestDox('Recorte do desenho: JPEG 320x320 de qualidade 0,88, enviado em "foto" com o "_token"')]
    public function testRecorteEEnvio(): void
    {
        $js = $this->js();
        self::assertStringContainsString('var S = 320;', $js);
        self::assertStringContainsString('var QUALIDADE = 0.88;', $js);
        self::assertStringContainsString("toBlob(feito, 'image/jpeg', QUALIDADE)", $js);
        self::assertStringContainsString("append('foto', blob, 'foto.jpg')", $js);
        self::assertStringContainsString("append('_token', botao.getAttribute('data-token'))", $js);
        self::assertStringContainsString("'X-Requested-With'", $js);
    }

    #[TestDox('Avisos: os da tela (avisarPs), com os textos do catálogo pasta_cabecalho.js.foto')]
    public function testAvisos(): void
    {
        $js = $this->js();
        self::assertStringContainsString('window.avisarPs', $js);
        foreach (['salva', 'removida', 'erro', 'carregar', 'carregar_nova'] as $chave) {
            self::assertStringContainsString("'pasta_cabecalho.js.foto.{$chave}'", $js);
        }
    }
}
