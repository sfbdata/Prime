<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Meta de pastas do mês (DASH-016), lado do navegador: as chaves do JS existem no catálogo, o texto entra por
 * textContent, o servidor é quem manda as rotas, e o CSS usa os valores do desenho "01 - Dashboard - VS 25.10.26".
 */
#[CoversNothing]
final class DashboardMetaJsTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../public/js/dashboard-meta.js';
    private const CSS = __DIR__ . '/../../../public/css/dashboard-meta.css';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('Toda chave BJ_T.t do JS da meta existe em dashboard.js.meta do catálogo, e o catálogo não tem chave sobrando')]
    public function testChavesDoCatalogo(): void
    {
        preg_match_all("/BJ_T\\.t\\(\\s*'(dashboard\\.js\\.meta\\.[a-z_]+)'/", $this->js(), $m);
        $usadas = array_unique($m[1]);
        $catalogo = CatalogoPtBr::carregar()['dashboard']['js']['meta'];

        self::assertNotEmpty($usadas);
        foreach ($usadas as $chave) {
            self::assertArrayHasKey(substr($chave, strlen('dashboard.js.meta.')), $catalogo, $chave);
        }
        self::assertEqualsCanonicalizing(array_map(static fn (string $c): string => substr($c, strlen('dashboard.js.meta.')), $usadas), array_keys($catalogo), 'catálogo e JS dizem as mesmas chaves');
    }

    #[TestDox('Textos do desenho no catálogo: editor, menu do botão direito e mensagens, sem travessão')]
    public function testTextosDoDesenho(): void
    {
        $js = CatalogoPtBr::carregar()['dashboard']['js']['meta'];

        self::assertSame('Meta de pastas', $js['titulo']);
        self::assertSame('Quantas pastas precisam ser abertas no mês.', $js['pergunta']);
        self::assertSame('Remover meta', $js['remover']);
        self::assertSame('Alterar meta do mês', $js['menu_alterar']);
        self::assertSame('Definir meta do mês', $js['menu_definir']);
        self::assertSame('Ver histórico', $js['menu_historico']);
        self::assertSame('Vale para toda a equipe e segue para os próximos meses até ser alterada.', $js['vigencia_nova']);
        foreach (array_merge($js, CatalogoPtBr::carregar()['dashboard']['meta']) as $chave => $texto) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', (string) $texto, $chave);
        }
    }

    #[TestDox('O JS só escreve texto por textContent, lê as rotas dos data-attributes e manda o CSRF no header')]
    public function testSeguranca(): void
    {
        $js = $this->js();

        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringContainsString("pagina.getAttribute(atributo)", $js);
        self::assertStringContainsString("'X-CSRF-Token': token()", $js);
        self::assertStringContainsString("postar('data-db-meta-url', { valor: valor() })", $js);
        self::assertStringContainsString("postar('data-db-meta-remover-url', null)", $js);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $js);
    }

    #[TestDox('Validação do desenho: só dígitos, mínimo 1; o teto é o do servidor (9999, 4 dígitos); Enter salva, Esc fecha')]
    public function testValidacao(): void
    {
        $js = $this->js();

        self::assertStringContainsString('var MAXIMO = 9999;', $js);
        self::assertStringContainsString("entrada.value.replace(/\\D/g, '').slice(0, String(MAXIMO).length)", $js);
        self::assertStringContainsString("entrada.min = '1';", $js);
        self::assertStringContainsString('return v > 0 && v <= MAXIMO ? v : 0;', $js);
        self::assertStringContainsString("e.key === 'Enter'", $js);
        self::assertStringContainsString("e.key === 'Escape'", $js);
        // A prévia do desenho, em dias úteis: valor / dias úteis do mês por dia e x5 por semana.
        self::assertStringContainsString('fmt(v / est.uteis * 5)', $js);
    }

    #[TestDox('O histórico entrega o botão direito do card Pastas criadas ao menu da meta, só se ele assumir')]
    public function testHistoricoChamaOMenu(): void
    {
        $hist = (string) file_get_contents(__DIR__ . '/../../../public/js/dashboard-historico.js');

        self::assertStringContainsString('window.DbMeta && window.DbMeta.menuDoCard(card, x, y, abrir)', $hist);
        self::assertStringContainsString('function menuDoCard(card, x, y, abrirHistorico) {', $this->js());
        self::assertStringContainsString("if (!pode() || !card.hasAttribute('data-db-meta-mes')) { return false; }", $this->js());
    }

    #[TestDox('CSS com os valores do desenho: trilho 8px, editor 280px, campo 40px, menu 216px, cores do ritmo')]
    public function testCssDoDesenho(): void
    {
        $css = (string) file_get_contents(self::CSS);

        self::assertMatchesRegularExpression('/\.db-meta-trilho \{[^}]*height: 8px;[^}]*border-radius: 3px;/s', $css);
        self::assertMatchesRegularExpression('/\.db-meta-fill \{[^}]*--db-meta-rgb: 15, 111, 196;/s', $css);
        self::assertMatchesRegularExpression('/\.db-meta-fill--abaixo \{ --db-meta-rgb: 192, 57, 47; \}/', $css);
        self::assertMatchesRegularExpression('/\.db-meta-marco \{[^}]*top: -3px;[^}]*bottom: -3px;[^}]*width: 2px;[^}]*opacity: \.55;/s', $css);
        self::assertMatchesRegularExpression('/\.db-meta-definir \{[^}]*height: 28px;[^}]*padding: 0 10px 0 8px;[^}]*rgba\(12, 122, 156, \.07\)/s', $css);
        self::assertMatchesRegularExpression('/\.db-meta-ed \{[^}]*width: 280px;[^}]*padding: 16px;/s', $css);
        self::assertMatchesRegularExpression('/\.db-meta-ed-entrada \{[^}]*height: 40px;[^}]*font-size: 16px;/s', $css);
        self::assertMatchesRegularExpression('/\.db-meta-menu \{[^}]*width: 216px;[^}]*padding: 5px;/s', $css);
        self::assertMatchesRegularExpression('/\.db-meta-status--abaixo \{ color: #b4352b; \}/', $css);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $css);
    }
}
