<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Instalar aplicativo" do menu do usuário (desenho 03 VS 18.10.26, dc L.690): com o pedido do navegador, o prompt;
 * no iPhone, o passo a passo; já instalado, a marca; sem nada disso (computador sem o pedido), o aviso de como instalar,
 * em vez de não acontecer nada. O comportamento na tela é conferido no navegador.
 */
final class InstalarAplicativoFolhaTest extends TestCase
{
    #[TestDox('Sem o pedido do navegador, o clique mostra o aviso do desenho 03 com o texto exato')]
    public function testAvisoSemPedido(): void
    {
        $base = (string) file_get_contents(__DIR__ . '/../../../templates/base.html.twig');

        self::assertStringContainsString("const AVISO_SEM_PEDIDO = 'Para instalar: no Chrome ou Edge, use o ícone de instalar na barra de endereço. No celular, \"Adicionar à tela inicial\".';", $base);
        self::assertStringContainsString("if (isStandalone) { marcarInstalado(); return; }", $base);
        self::assertStringContainsString('if (window.bjAviso) window.bjAviso(AVISO_SEM_PEDIDO);', $base);
    }
}
