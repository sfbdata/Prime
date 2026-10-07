<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O menu do botão direito no cartão do Push (FPN-014, bloco 6 de `public/js/pasta-push.js`) lido
 * como FOLHA: não há navegador no PHPUnit, então o que se prova é o contrato com o template, com
 * o teor e com o nota-tecnica.js — e que o menu NÃO inventa caminho próprio.
 */
#[Group('pasta')]
final class PastaPushMenuContextoJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-push.js';

    private function blocoDoMenu(): string
    {
        $js     = (string) file_get_contents(self::JS);
        $inicio = strpos($js, '6. Botão direito no cartão');
        self::assertNotFalse($inicio, 'o bloco do menu existe');

        return substr($js, $inicio);
    }

    #[TestDox('escuta o contextmenu na LISTA e só abre sobre o cartão, deixando links e campos com o menu do navegador')]
    public function testEscutaSoOCartao(): void
    {
        $bloco = $this->blocoDoMenu();

        self::assertStringContainsString("lista.addEventListener('contextmenu'", $bloco);
        self::assertStringContainsString("e.target.closest('.ps-push-cartao')", $bloco);
        self::assertStringContainsString("e.target.closest('a, input, textarea, select')", $bloco);
        self::assertStringContainsString('e.preventDefault()', $bloco);
    }

    #[TestDox('ids e classes do menu são os do template')]
    public function testContratoComOTemplate(): void
    {
        $bloco = $this->blocoDoMenu();

        foreach (["getElementById('pushMenuContexto')", "'.ps-push-menu-titulo'", "'.ps-push-menu-item'", "'js-push-menu-nota'", "'js-push-menu-tarefa'", "'js-push-menu-copiar'", "'.ps-push-assunto-txt'"] as $trecho) {
            self::assertStringContainsString($trecho, $bloco);
        }
        $twig = (string) file_get_contents(__DIR__ . '/../../../templates/pasta/_push_processual.html.twig');
        foreach (['id="pushMenuContexto"', 'ps-push-menu-titulo', 'js-push-menu-nota', 'js-push-menu-tarefa', 'js-push-menu-copiar'] as $marca) {
            self::assertStringContainsString($marca, $twig, "{$marca} é contrato com o JS");
        }
    }

    #[TestDox('cada item aciona o botão que o teor já traz: .js-push-criar-meta e o .js-nota-nova de #notas-publicacao-<id>')]
    public function testAcionaOsBotoesDoTeor(): void
    {
        $bloco = $this->blocoDoMenu();

        self::assertStringContainsString("querySelector('.js-push-criar-meta')", $bloco);
        self::assertStringContainsString("querySelector('#notas-publicacao-' + id)", $bloco);
        self::assertStringContainsString("querySelector('.js-nota-nova')", $bloco);
        self::assertStringContainsString('.click()', $bloco);
        // As classes procuradas existem do outro lado do contrato.
        $teor  = (string) file_get_contents(__DIR__ . '/../../../templates/pasta/_push_teor.html.twig');
        $notas = (string) file_get_contents(__DIR__ . '/../../../templates/processo/_notas_tecnicas.html.twig');
        self::assertStringContainsString('js-push-criar-meta', $teor);
        self::assertStringContainsString("'notas-publicacao-' ~ publicacaoId", $notas);
        self::assertStringContainsString('js-nota-nova', $notas);
    }

    #[TestDox('o menu não lê o teor por conta própria: abre o cartão (o clique do pasta-show.js) e espera data-carregado')]
    public function testSemSegundoCaminhoDeLeitura(): void
    {
        $bloco = $this->blocoDoMenu();

        self::assertStringNotContainsString('fetch(', $bloco);
        self::assertStringNotContainsString('XMLHttpRequest', $bloco);
        self::assertStringContainsString('cab.click()', $bloco);
        self::assertStringContainsString("attributeFilter: ['data-carregado']", $bloco);
        // E o pasta-show.js continua marcando o painel assim.
        $show = (string) file_get_contents(__DIR__ . '/../../../public/js/pasta-show.js');
        self::assertStringContainsString("painel.dataset.carregado = '1'", $show);
    }

    #[TestDox('"Copiar texto" usa o teor carregado (.djen-teor) e, antes dele, o trecho do título — sem abrir o cartão')]
    public function testCopiarSemAbrirOCartao(): void
    {
        $bloco  = $this->blocoDoMenu();
        $inicio = strpos($bloco, "contains('js-push-menu-copiar')");
        self::assertNotFalse($inicio);
        $ramo = substr($bloco, $inicio, (int) strpos($bloco, 'return;', $inicio) - $inicio);

        self::assertStringContainsString("'.djen-teor'", $ramo);
        self::assertStringContainsString("'.ps-push-assunto-txt'", $ramo);
        self::assertStringNotContainsString('comTeor(', $ramo, 'copiar não pode marcar como lida');
        self::assertStringNotContainsString('innerHTML', $bloco);
    }

    #[TestDox('fecha com Esc (devolvendo o foco ao cartão), clique fora, rolagem e redimensionamento')]
    public function testFechamento(): void
    {
        $bloco = $this->blocoDoMenu();

        self::assertStringContainsString("e.key === 'Escape') { fecharMenu(true); }", $bloco);
        self::assertStringContainsString('de.cab.focus()', $bloco);
        self::assertStringContainsString("window.addEventListener('scroll'", $bloco);
        self::assertStringContainsString("window.addEventListener('resize'", $bloco);
        self::assertStringContainsString("'ps:fechar-popovers'", $bloco);
    }
}
