<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * PR-D: o JS do modal "Ver relatório" (`public/js/dashboard-relatorio.js`) e a ponte com o menu da célula
 * (`dashboard-zerar.js`), lidos como FOLHA. O desenho na tela é smoke do dono.
 */
final class DashboardRelatorioJsTest extends TestCase
{
    private const JS     = __DIR__ . '/../../../public/js/dashboard-relatorio.js';
    private const ZERAR  = __DIR__ . '/../../../public/js/dashboard-zerar.js';
    private const INDEX  = __DIR__ . '/../../../templates/dashboard/index.html.twig';
    private const EXTRAS = __DIR__ . '/../../../templates/dashboard/_extras.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('nada de innerHTML: nome, cliente e trecho do registro entram por textContent')]
    public function testSemInnerHtml(): void
    {
        $js = $this->js();
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $js, $proibido);
        }
        self::assertStringContainsString('n.textContent = String(texto);', $js);
        self::assertStringContainsString("el('span', 'db-rel-item-trecho', x.oculto ?", $js);
    }

    #[TestDox('o link da pasta só é aceito como caminho do próprio site')]
    public function testLinkSoCaminhoLocal(): void
    {
        self::assertStringContainsString("/^\\/[\\w\\-./#]*\$/.test(url)", $this->js());
    }

    #[TestDox('toda chave do catálogo usada no JS é literal e existe no catálogo pt_BR')]
    public function testChavesDoCatalogo(): void
    {
        $fontes = $this->js() . (string) file_get_contents(self::ZERAR);
        preg_match_all("/\\bt\\('([a-z_.]+)'|BJ_T\\.t\\('([a-z_.]+)'/", $fontes, $m);
        $chaves = array_unique(array_filter(array_merge($m[1], $m[2])));
        self::assertNotEmpty($chaves);

        $catalogo = CatalogoPtBr::carregar();
        foreach ($chaves as $chave) {
            $valor = $catalogo;
            foreach (explode('.', $chave) as $parte) {
                self::assertIsArray($valor, $chave);
                self::assertArrayHasKey($parte, $valor, $chave);
                $valor = $valor[$parte];
            }
            self::assertIsString($valor, $chave);
        }
        self::assertStringNotContainsString("t('dashboard.relatorio.js.aba_' +", $this->js(), 'chave montada por concatenação');
    }

    #[TestDox('texto visível do catálogo do modal e do Total sem travessão')]
    public function testSemTravessao(): void
    {
        $bloco = CatalogoPtBr::carregar()['dashboard']['relatorio'];
        array_walk_recursive($bloco, static function (string $texto, string $chave): void {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $texto, $chave);
        });
    }

    #[TestDox('o menu da célula abre o modal na coluna Relatório; o número clica por delegação no botão')]
    public function testPonteComOMenu(): void
    {
        $zerar = (string) file_get_contents(self::ZERAR);
        self::assertStringContainsString("alvo.coluna === 'relatorio' && alvo.relatorioUrl && window.DbRelatorio", $zerar);
        self::assertStringContainsString("cel.querySelector('[data-db-relatorio-url]')", $zerar);
        self::assertStringContainsString('window.DbRelatorio.abrir(alvo.relatorioUrl, alvo.nome, null);', $zerar);

        self::assertStringContainsString("e.target.closest('button[data-db-relatorio-url]')", $this->js());
        self::assertStringContainsString("fetch(url, { credentials: 'same-origin'", $this->js());
    }

    #[TestDox('a casca carrega o catálogo e o script do modal antes do Zerar, que o chama')]
    public function testCasca(): void
    {
        $index = (string) file_get_contents(self::INDEX);
        $posCatalogo = strpos($index, "catalogo_js('dashboard.relatorio.js')");
        $posModal    = strpos($index, "js/dashboard-relatorio.js");
        $posZerar    = strpos($index, "js/dashboard-zerar.js");
        self::assertIsInt($posCatalogo);
        self::assertLessThan($posModal, $posCatalogo);
        self::assertLessThan($posZerar, $posModal);
        self::assertStringContainsString('css/dashboard-relatorio.css', $index);
    }

    #[TestDox('o botão do número leva a rota do modal e o nome da pessoa')]
    public function testBotaoNoTemplate(): void
    {
        $extras = (string) file_get_contents(self::EXTRAS);
        self::assertStringContainsString("path('dashboard_relatorio', { userId: pessoaId }|merge(periodoLink))", $extras);
        self::assertStringContainsString('data-db-relatorio-nome="{{ quem }}"', $extras);
    }
}
