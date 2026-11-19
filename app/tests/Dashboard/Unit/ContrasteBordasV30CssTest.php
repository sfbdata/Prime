<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pedidos A3 e A4 de 19/11 (Guia visual BlueJus v30) no Dashboard e no Push Processual: texto de apoio com
 * contraste mínimo de 4,5:1 (#5f7684), ícone decorativo com 3:1 (#7b93a2), borda de área #d6e0e7, campo #7b93a2,
 * e o filtro ativo do Push marcado por sinal além da cor. Que aparece certo na tela é do smoke do dono.
 */
final class ContrasteBordasV30CssTest extends TestCase
{
    private function ler(string $caminho): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $caminho);
    }

    #[TestDox('Dashboard: tokens de texto apagado passam a #5f7684 e as bordas ao tom do guia')]
    public function testTokensDoDashboard(): void
    {
        $css = $this->ler('public/css/dashboard.css');
        foreach (['--db-muted', '--db-faint', '--db-placeholder', '--db-zero', '--db-sem-cargo', '--db-seta-igual'] as $token) {
            self::assertMatchesRegularExpression('/' . preg_quote($token, '/') . ':\s+#5f7684;/', $css, $token);
        }
        foreach (['--db-icon-muted', '--db-vazio-icone'] as $token) {
            self::assertMatchesRegularExpression('/' . preg_quote($token, '/') . ':\s+#7b93a2;/', $css, $token);
        }
        foreach (['--db-border', '--db-div-strong', '--db-seg-border'] as $token) {
            self::assertMatchesRegularExpression('/' . preg_quote($token, '/') . ':\s+#d6e0e7;/', $css, $token);
        }
        self::assertStringContainsString('.db-cal-dia.is-fora .db-cal-num { color: var(--db-zero); font-weight: 400; font-style: italic; }', $css, 'fora do mês se distingue do fim de semana sem clarear');
        self::assertStringContainsString('[data-bs-theme="dark"] .db-page .db-trofeu { color: #e6c48a; }', $css);
        self::assertStringNotContainsString('.db-pref-fixa { display: flex; align-items: center; gap: 10px; padding: 7px 8px; opacity: .55; }', $css);
    }

    #[TestDox('Janelas, relatório e meta do Dashboard: sem os cinzas de 1,6:1 a 2,6:1')]
    public function testCssAuxiliaresDoDashboard(): void
    {
        self::assertStringNotContainsString('#c3d0d8', $this->ler('public/css/dashboard-janelas.css'));
        self::assertMatchesRegularExpression('/--db-hist-rotulo:\s+#5f7684;/', $this->ler('public/css/dashboard-janelas.css'), 'rótulo do histórico é texto: 4,5:1');
        self::assertStringContainsString('.db-hist-rotulo.is-vazio { color: var(--db-hist-rotulo-vazio); font-style: italic; }', $this->ler('public/css/dashboard-janelas.css'));
        self::assertStringNotContainsString('#c49a3a', $this->ler('public/css/dashboard-janelas.css'));
        self::assertStringNotContainsString('#9fb2bf', $this->ler('public/css/dashboard-relatorio.css'));
        self::assertStringContainsString('.db-page .db-meta-feitas i { font-size: 10.5px; }', $this->ler('public/css/dashboard-meta.css'));
    }

    #[TestDox('Push: bordas no tom do guia e campos com borda #7b93a2')]
    public function testBordasDoPush(): void
    {
        foreach (['push-processual.css', 'push-processos.css', 'push-acervo.css', 'push-compartilhado.css'] as $arquivo) {
            self::assertStringNotContainsString('#d3dde4', $this->ler('public/css/' . $arquivo), $arquivo);
        }
        self::assertStringContainsString('.pp-campo { height: 36px; border: 1px solid #7b93a2;', $this->ler('public/css/push-processual.css'));
        self::assertStringContainsString('.pp-entrada { width: 100%; box-sizing: border-box; height: 38px; border: 1px solid #7b93a2;', $this->ler('public/css/push-processos.css'));
    }

    #[TestDox('Push: o filtro ativo ganha ✓, rótulo em negrito e aria-current, não só a cor da borda')]
    public function testFiltroAtivoDoPush(): void
    {
        $css = $this->ler('public/css/push-processos.css');
        self::assertStringContainsString('.pp-kpi--ativo .pp-kpi__rotulo { font-weight: 700; }', $css);
        self::assertStringContainsString('.pp-kpi--ativo .pp-kpi__rotulo::before { content: "\2713\00a0"; }', $css);
        self::assertSame(4, substr_count($this->ler('templates/djen/processos.html.twig'), '%} aria-current="true"{% endif %}>'));
    }
}
