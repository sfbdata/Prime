<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Tests\Factory\Pasta\PastaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Zenstruck\Foundry\Test\Factories;

/**
 * 3ª passada de fidelidade do Dashboard × dc 1.2.2 (pendências pós-Documentos, §3.2, D2–D9).
 * D1 (seta por linha em Vencidas/Prazos) mora em DashboardFotoTendenciaTelaTest, que tem a foto;
 * D8 ("dia X de Y" no topo do ritmo) em DashboardInteligenciaTelaTest.
 *
 * Quase tudo aqui é CSS. Teste de PHPUnit não vê cor nem posição: o que dá para travar é que a
 * REGRA existe no arquivo, no seletor certo, e que o HTML tem o nó em que ela pega (filho direto).
 * Que a sombra fica por cima da linha seguinte, que a caixa redimensiona e que a legenda some no
 * PDF é smoke do dono.
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardTerceiraPassadaTelaTest extends DashboardWebTestCase
{
    use Factories;

    private function css(string $arquivo): string
    {
        // Sem o container: subir o kernel aqui impediria o createClient() do mesmo teste.
        return (string) file_get_contents(__DIR__ . '/../../../public/css/' . $arquivo);
    }

    /** Corpo de TODAS as regras cujo seletor termina exatamente em `$seletor` (agrupadas ou não). */
    private function regras(string $css, string $seletor): string
    {
        preg_match_all('/' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/', $css, $m);
        self::assertNotEmpty($m[1], 'Falta a regra ' . $seletor);

        return implode("\n", $m[1]);
    }

    #[TestDox('D2: a linha em hover sobe (z-index 2) para a sombra de baixo pintar por cima da seguinte')]
    public function testHoverDaLinhaSobe(): void
    {
        $regra = $this->regras($this->css('dashboard.css'), '.db-page .db-table tbody tr:hover');

        self::assertStringContainsString('0 10px 20px -16px rgba(12,60,90,.5)', $regra);
        self::assertStringContainsString('z-index: 2', $regra);
    }

    #[TestDox('D3: densidade Normal vale também na linha de Total (9px no tfoot)')]
    public function testDensidadeNormalNoTotal(): void
    {
        $css = $this->css('dashboard.css');

        self::assertMatchesRegularExpression(
            '/\.db-page\.db-page--confortavel \.db-table tfoot th,\s*\.db-page\.db-page--confortavel \.db-table tfoot td\s*\{\s*padding-top: 9px; padding-bottom: 9px;/',
            $css,
        );
    }

    #[TestDox('D3: a linha de Total existe como tfoot > tr > td, o nó em que a regra pega')]
    public function testTotalNoTfoot(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $user]);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(7, $crawler->filter('.db-table-card table.db-table > tfoot > tr.db-total-row > td.db-td-num')->count());
    }

    #[TestDox('D4: o ⋮ fechado usa o tom de rótulo #4f6878 (--db-label), não o do card')]
    public function testCorDoBotaoFechado(): void
    {
        $css   = $this->css('dashboard.css');
        $regra = $this->regras($css, '.db-page .db-pref-btn');

        self::assertStringContainsString('color: var(--db-label);', $regra);
        self::assertStringNotContainsString('--db-label-card', $regra);
        self::assertMatchesRegularExpression('/--db-label:\s+#4f6878;/', $css);
    }

    #[TestDox('D5: a caixa do menu ⋮ tem altura mínima de 220px, redimensiona e usa a borda #e3eaef')]
    public function testCaixaDoMenu(): void
    {
        $css   = $this->css('dashboard.css');
        $regra = $this->regras($css, '.db-page .db-pref-menu');

        self::assertStringContainsString('min-height: 220px;', $regra);
        self::assertStringContainsString('resize: both;', $regra);
        self::assertStringContainsString('border: 1px solid var(--db-borda-flutuante);', $regra);
        self::assertMatchesRegularExpression('/--db-borda-flutuante:\s+#e3eaef;/', $css);
    }

    #[TestDox('D6: o painel do calendário usa a borda #e3eaef; o do select continua na sua (#dde5eb)')]
    public function testBordaDoCalendario(): void
    {
        $css = $this->css('dashboard.css');

        self::assertStringContainsString('border-color: var(--db-borda-flutuante);', $this->regras($css, '.db-page .db-cal-painel'));
        self::assertMatchesRegularExpression('/--db-fp-painel-borda:\s+#dde5eb;/', $css);
        // o tema escuro também define o token novo (hex cravado viraria cor de um tema no outro)
        self::assertMatchesRegularExpression('/--db-borda-flutuante:\s+var\(--bs-border-color\);/', $css);
    }

    #[TestDox('D7: os 4 números do ritmo do Intelligence não viram 2×2 no celular')]
    public function testKpisDoIntelligenceNoCelular(): void
    {
        $css = $this->css('dashboard-inteligencia.css');

        self::assertSame(1, preg_match('/@media \(max-width: 719\.98px\) \{(.*?)\n\}/s', $css, $m));
        self::assertStringNotContainsString('.db-ia-kpis', $m[1]);
        self::assertStringContainsString('grid-template-columns: repeat(4, minmax(0, 1fr))', $this->regras($css, '.db-page .db-ia-kpis'));
    }

    #[TestDox('D9: no PDF as legendas de hover saem de vez (display:none), sem reservar a linha')]
    public function testLegendasDeHoverForaDoPdf(): void
    {
        $css = $this->css('dashboard.css');

        self::assertSame(1, preg_match('/@media print \{\n    @page(.*?)\n\}/s', $css, $m), 'bloco de impressão principal');
        self::assertStringContainsString('.db-page .db-card-legenda--hover { display: none !important; }', $m[1]);

        // e o HTML tem as duas legendas que a regra esconde, dentro dos cards
        $client = static::createClient();
        $this->criarGestorLogado($client);
        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('.db-stat-card .db-card-legenda.db-card-legenda--hover')->count());
    }
}
