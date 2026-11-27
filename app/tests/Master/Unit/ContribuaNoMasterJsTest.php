<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * L2-M5 (pedido do Farlei, lote 2, 27/11/2026): o pagamento por cartão e os pedidos de espaço saíram da página Contribua
 * para o painel do Master. O JS foi junto, com as mesmas rotas e o mesmo CSRF; o da página não os trata mais.
 */
#[CoversNothing]
final class ContribuaNoMasterJsTest extends TestCase
{
    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/' . $arquivo);
    }

    #[TestDox('O contribua-no-master.js abre o modal do pagamento, grava, confirma pedidos e decide os pedidos de espaço com o CSRF do bloco')]
    public function testJsDoPainel(): void
    {
        $js = self::js('master/contribua-no-master.js');

        self::assertStringContainsString("const raiz = document.querySelector('[data-mp-contribua]');", $js);
        self::assertStringContainsString("corpo.append('_token', raiz.dataset.csrf);", $js);
        self::assertStringContainsString("$('[data-abrir-master]', raiz)", $js);
        self::assertStringContainsString('enviar(master.dataset.urlSalvar, { gateway: seletor.value, chavePublica:', $js);
        self::assertStringContainsString("master.dataset.urlConfirmar.replace(/\\/0\\/confirmar$/, '/' + b.dataset.confirmarPedido + '/confirmar')", $js);
        self::assertStringContainsString("$$('[data-pedido-decidir]', raiz)", $js);
        self::assertStringContainsString('location.reload();', $js, 'recarrega na mesma seção (o hash fica)');
        self::assertStringNotContainsString('innerHTML', $js);
    }

    #[TestDox('O painel.js põe o bloco no fim da seção dos planos, movendo o mesmo nó a cada montagem')]
    public function testAnexaNaSecaoDosPlanos(): void
    {
        $js = self::js('master/painel.js');

        self::assertStringContainsString("const elContribua = raiz.querySelector('[data-mp-contribua]');", $js);
        self::assertStringContainsString("    document.getElementById('mp-planos-aviso').innerHTML = avisoHtml();\n    anexarConfiguracoesDoContribua();\n  }", $js);
        self::assertStringContainsString("    elSecao.appendChild(elContribua);\n    elContribua.hidden = false;", $js);
    }

    #[TestDox('A página Contribua não trata mais o modal do Master nem o Liberar/Recusar; a integração bancária aceita o CSRF do painel')]
    public function testContribuaSemOBlocoDoMaster(): void
    {
        self::assertStringNotContainsString('iniciarModalDoMaster', self::js('contribua-checkout.js'));
        self::assertStringNotContainsString('[data-pedido-decidir]', self::js('contribua.js'));
        self::assertStringContainsString('[data-espaco-previa]', self::js('contribua.js'), 'a prévia segue na página');
        self::assertStringContainsString("const raiz = () => $('[data-contribua]') || $('[data-mp-contribua]');", self::js('contribua-cora.js'));
    }
}
