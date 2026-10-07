<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * DASH-017: o JS da janela "Histórico" (`public/js/dashboard-historico.js`) lido como FOLHA.
 * Prova o contrato com o template (cards com `data-historico-url`), com o componente de janelas
 * (DASH-020) e com o endpoint; o desenho na tela é smoke do dono.
 */
final class DashboardHistoricoJsTest extends TestCase
{
    private const JS        = __DIR__ . '/../../../public/js/dashboard-historico.js';
    private const RESULTADO = __DIR__ . '/../../../templates/dashboard/_resultado.html.twig';
    private const INDEX     = __DIR__ . '/../../../templates/dashboard/index.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('nada de innerHTML: o que vem do servidor entra por textContent')]
    public function testSemInnerHtml(): void
    {
        $js = $this->js();
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $js, $proibido . ' no histórico');
        }
        self::assertStringContainsString('n.textContent = String(texto);', $js);
        self::assertStringContainsString("janela.titulo.textContent = d.titulo;", $js);
        self::assertStringContainsString('sub.textContent = d.subtitulo;', $js);
    }

    #[TestDox('botão direito delegado no root persistente, só no card, deixando o link "ver …" com o menu do navegador')]
    public function testContextmenuNoCard(): void
    {
        $js = $this->js();
        self::assertStringContainsString("root.addEventListener('contextmenu', function (e) {", $js);
        self::assertStringContainsString("e.target.closest('.db-stat-card[data-historico-url]')", $js);
        self::assertMatchesRegularExpression("/if \\(e\\.target\\.closest\\('a, button, input, select, textarea'\\)\\) \\{ return; \\}\\s*e\\.preventDefault\\(\\);/", $js);
        self::assertStringContainsString("document.querySelectorAll('.db-page [data-filtro-root]').forEach(ligar);", $js);
    }

    #[TestDox('abre pelo componente de janelas, 330px, uma por card + recorte, devolvendo o foco ao card')]
    public function testAbrePeloComponente(): void
    {
        $js = $this->js();
        self::assertStringContainsString('window.DbJanelas.abrir({', $js);
        self::assertStringContainsString("chave: 'hist:' + tipo + '|' + f.responsavel + '|' + f.cargo,", $js);
        self::assertStringContainsString('largura: 330,', $js);
        self::assertStringContainsString("classe: 'db-hist db-hist--' + tipo,", $js);
        self::assertStringContainsString('origem: card,', $js);
    }

    #[TestDox('leva ao endpoint o responsável e o cargo do filtro do painel (o universo do card)')]
    public function testRecorteDoPainel(): void
    {
        $js = $this->js();
        self::assertStringContainsString("var url = new URL(card.getAttribute('data-historico-url'), window.location.origin);", $js);
        self::assertStringContainsString("url.searchParams.set('responsavel', f.responsavel);", $js);
        self::assertStringContainsString("url.searchParams.set('cargo', f.cargo);", $js);
        self::assertStringContainsString("credentials: 'same-origin'", $js);
    }

    #[TestDox('seletor de ano acessível (grupo + aria-pressed) e card sem série mostra só o texto do servidor')]
    public function testAnoEIndisponivel(): void
    {
        $js = $this->js();
        self::assertStringContainsString("anos.setAttribute('role', 'group');", $js);
        self::assertStringContainsString("b.setAttribute('aria-pressed', b.dataset.ano === chave ? 'true' : 'false');", $js);
        self::assertMatchesRegularExpression("/if \\(!d\\.disponivel\\) \\{\\s*corpo\\.replaceChildren\\(/", $js);
        self::assertStringContainsString("el('div', 'db-hist-nota', d.nota)", $js);
    }

    #[TestDox('os 4 cards apontam para o endpoint do próprio histórico e a casca carrega o script depois do componente')]
    public function testTemplate(): void
    {
        $twig = (string) file_get_contents(self::RESULTADO);
        foreach (['pastas', 'metas', 'urgentes', 'global'] as $card) {
            self::assertStringContainsString(
                sprintf('<div class="db-stat-card db-stat-card--%1$s" data-historico="%1$s" data-historico-url="{{ path(\'dashboard_historico\', { card: \'%1$s\' }) }}">', $card),
                $twig,
            );
        }

        $index = (string) file_get_contents(self::INDEX);
        self::assertGreaterThan(strpos($index, "asset('js/dashboard-janelas.js')"), strpos($index, "asset('js/dashboard-historico.js')"));
    }
}
