<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** F3f (dc 05 VS 21.10.26 L497-498): o ícone de teste abre o modal, e o envio sai do modal, nunca direto do clique. */
final class ComercialTesteJsTest extends TestCase
{
    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/master/' . $arquivo);
    }

    #[TestDox('o editor não posta mais o teste no clique; o comercial-teste.js abre o modal em captura e posta o modelo atual')]
    public function testModalDoTeste(): void
    {
        self::assertStringNotContainsString("postar(ROTAS.urlTeste", self::js('comercial-campanha.js'));
        $js = self::js('comercial-teste.js');
        self::assertStringContainsString("if (e.target.closest('[data-mc-cp-teste]')) { e.preventDefault(); abrir(e); return; }", $js);
        self::assertStringContainsString('}, true);', $js, 'ouvinte em captura');
        self::assertStringContainsString('window.McCampanha.modeloAtual()', $js);
        self::assertStringContainsString("closest('.mc[data-modo]')", $js, 'o modelo vem da seção do clique (Comercial ou Indicações)');
        self::assertStringContainsString('secao.mcModeloAtual()', $js);
        self::assertStringContainsString("new CustomEvent('mc:relatorio-recarregar')", $js);
        self::assertStringContainsString("document.addEventListener('mc:relatorio-recarregar'", self::js('comercial-relatorio.js'));
    }
}
