<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pedidos A3 e A4 de 19/11 (Guia visual BlueJus v30) no Ponto, no Cálculo e no Kanban: texto de apoio #5f7684,
 * controle #7b93a2, borda de área #d6e0e7 e cor nunca como único sinal. Só aparência; que fica certo na tela
 * é do smoke do dono.
 */
final class ContrasteBordasV30CssTest extends TestCase
{
    private function ler(string $caminho): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $caminho);
    }

    #[TestDox('Ponto: tokens de borda, concluída e chave desligada seguem o guia v30')]
    public function testTokensDoPonto(): void
    {
        $css = $this->ler('public/css/ponto-eletronico.css');
        self::assertMatchesRegularExpression('/--pe-borda:\s+#d6e0e7;/', $css);
        self::assertMatchesRegularExpression('/--pe-borda-campo:\s+#7b93a2;/', $css);
        self::assertMatchesRegularExpression('/--pe-borda-campo:\s+var\(--bs-border-color\);/', $css, 'tema escuro');
        self::assertMatchesRegularExpression('/--pe-concluida:\s+#5f7684;/', $css);
        self::assertMatchesRegularExpression('/--pe-chave-desligada:\s+#7b93a2;/', $css);
        self::assertStringNotContainsString('#d3dde4', $css);
        self::assertStringContainsString('.pe-cal-dia:disabled { color: var(--pe-concluida); cursor: default; }', $css);
    }

    #[TestDox('Ponto: campos e botão de tipo usam a borda de campo')]
    public function testCamposDoPontoUsamBordaDeCampo(): void
    {
        $css = $this->ler('public/css/ponto-eletronico.css');
        foreach (['.pe-tipo-btn', '.pe-janela-campo', '.pe-janela-select', '.pe-sf-campo', '.pe-comp-campo'] as $seletor) {
            $achou = preg_match('/^' . preg_quote($seletor, '/') . ' \{(.*?)\n\}/ms', $css, $m);
            self::assertSame(1, $achou, $seletor);
            self::assertStringContainsString('border: 1px solid var(--pe-borda-campo);', $m[1], $seletor);
        }
    }

    #[TestDox('Ponto: validar comprovante e calendário da justificativa com sinal além da cor')]
    public function testTemplatesDoPonto(): void
    {
        self::assertStringContainsString('border:1px solid #7b93a2', $this->ler('templates/ponto/validar_comprovante.html.twig'));
        $cal = $this->ler('templates/ponto/_justificativa_calendario.html.twig');
        self::assertMatchesRegularExpression('/\.cal-selected \{[^}]*color: #212529;[^}]*font-weight: 700;[^}]*text-decoration: underline;/', $cal);
        self::assertMatchesRegularExpression('/\.cal-today \{[^}]*font-weight: 700;/', $cal);
        self::assertMatchesRegularExpression('/\.cal-disabled \{\s*color: var\(--bs-secondary-color\);\s*cursor: default;/', $cal);
    }

    #[TestDox('Cálculo: campos #7b93a2, áreas #d6e0e7 e texto de apoio #5f7684')]
    public function testCalculo(): void
    {
        $css = $this->ler('public/css/calculo.css');
        self::assertStringNotContainsString('#d3dde4', $css);
        foreach (['.calc-in', '.calc-sel', '.calc-abat-linha input', '.calc-btn-limpar'] as $seletor) {
            self::assertMatchesRegularExpression('/' . preg_quote($seletor, '/') . ' \{[^}]*border: 1px solid #7b93a2;/', $css, $seletor);
        }
        self::assertMatchesRegularExpression('/\.calc-sec \{[^}]*border: 1px solid #d6e0e7;/', $css);
        self::assertMatchesRegularExpression('/\.calc-vals-vazio \{[^}]*color: #5f7684;/', $css);
        self::assertMatchesRegularExpression('/\.calc-nota \{[^}]*color: #5f7684;/', $css);
        $v = $this->ler('templates/calculo/verificar.html.twig');
        self::assertStringContainsString('.cv-campo input { height:38px; box-sizing:border-box; border:1px solid #7b93a2;', $v);
        self::assertStringContainsString('.cv-dica { font-size:12px; font-weight:normal; color:#5f7684; }', $v);
    }

    #[TestDox('Kanban: vencido em #b91c1c com ícone de alerta, avatar #0a6fa0 e check no marcador selecionado')]
    public function testKanban(): void
    {
        $board = $this->ler('templates/kanban/board.html.twig');
        self::assertStringContainsString('.kb-card-meta-item.vencido { color: #b91c1c; }', $board);
        self::assertMatchesRegularExpression('/\.kb-avatar \{[^}]*background: #0a6fa0;/', $board);
        self::assertStringContainsString('.kb-cor-btn.selecionado::after { content: "\2713";', $board);
        self::assertStringContainsString("border = '2px solid var(--bs-emphasis-color)'", $board);
        self::assertStringContainsString("classList.add('selecionado')", $board);
        self::assertStringNotContainsString('#111', $board);

        $mini = $this->ler('templates/kanban/_partials/_card_mini.html.twig');
        self::assertStringContainsString("card.estaVencido ? 'bi-exclamation-circle' : 'bi-calendar-event'", $mini);
    }
}
