<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PAS-040 (desenho 1.2.3, dc L.2316 `onContextMenu`): o botão direito na observação de Detalhes
 * abre o MESMO `.ps-obs-menu` do ⋮, no ponto do clique. Roda no navegador; aqui se prova o
 * script que a tela carrega.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaDetalhesObsMenuContextoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('contextmenu delegado na lista abre o menu do ⋮ do cartão no ponto do clique; links, campos e seleção ficam com o navegador')]
    public function testBotaoDireitoAbreOMenuDaObservacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $ini = strpos($html, "obsLista.addEventListener('contextmenu', function (e) {");
        self::assertNotFalse($ini, 'o botão direito é ouvido na lista (#detalhesObsLista), por delegação');
        $ouvinte = substr($html, $ini, 900);
        self::assertStringContainsString("alvo.closest('.detalhes-obs-item')", $ouvinte);
        self::assertStringContainsString("alvo.closest('a, button, input, textarea, select, [contenteditable=\"true\"], .ps-anotacao-edicao, .ps-obs-menu')", $ouvinte);
        self::assertStringContainsString("if (selecao.trim() !== '') { return; }", $ouvinte, 'com texto selecionado, o menu é o do navegador');
        self::assertStringContainsString("const gatilho = cartao.querySelector('.js-obs-menu');", $ouvinte, 'o MESMO menu do ⋮');
        self::assertStringContainsString('e.preventDefault();', $ouvinte);
        self::assertStringContainsString('abrirMenuObs(gatilho, e.clientX, e.clientY);', $ouvinte);

        $abrir = substr($html, (int) strpos($html, 'function abrirMenuObs(gatilho, px, py) {'), 1200);
        self::assertStringContainsString("const porClique = typeof px === 'number';", $abrir);
        self::assertStringContainsString('x = px;', $abrir);
        self::assertStringContainsString('y = py;', $abrir);
    }
}
