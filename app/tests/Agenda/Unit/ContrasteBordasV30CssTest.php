<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pedidos A3 e A4 de 19/11 (Guia visual BlueJus v30) na Agenda (1A e nova) e em Demandas: texto de apoio com
 * 4,5:1 (#5f7684), ícone e borda de controle com 3:1 (#7b93a2), bordas de área #d6e0e7, e o compromisso concluído
 * marcado com ✓ além do tom. Que aparece certo na tela é do smoke do dono.
 */
final class ContrasteBordasV30CssTest extends TestCase
{
    private function ler(string $caminho): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $caminho);
    }

    #[TestDox('Agenda 1A: tokens de texto apagado a #5f7684, controles a #7b93a2 e sem os cinzas de 1,8:1 a 3,2:1')]
    public function testAgenda1a(): void
    {
        $css = $this->ler('public/css/a1-agenda.css');
        foreach (['--a1-t4', '--a1-mudo', '--a1-apagado'] as $token) {
            self::assertMatchesRegularExpression('/' . preg_quote($token, '/') . ': #5f7684;/', $css, $token);
        }
        self::assertMatchesRegularExpression('/--a1-ctl: #7b93a2;/', $css);
        self::assertMatchesRegularExpression('/--a1-borda: #d6e0e7;/', $css);
        foreach (['#a9b8c2', '#b3c1ca', 'opacity: .72', 'opacity: .45'] as $antigo) {
            self::assertStringNotContainsString($antigo, $css, $antigo);
        }
        self::assertStringNotContainsString('.a1-hoje-lin.is-cancelado { opacity', $css);
        self::assertStringContainsString('.is-cancelado .a1-lin-tit { color: var(--a1-t3);', $css);
        self::assertStringContainsString('.is-concluido > .a1-chip-tit::before { content: "\2713\00a0"; }', $css);
    }

    #[TestDox('Agenda nova: tokens a #5f7684 e #7b93a2; concluído com ✓ e sem a opacidade .6')]
    public function testAgendaNova(): void
    {
        $css = $this->ler('public/css/agenda-nova.css');
        foreach (['--ag-fraco', '--ag-apagado', '--ag-apagado-2'] as $token) {
            self::assertMatchesRegularExpression('/' . preg_quote($token, '/') . ':\s+#5f7684;/', $css, $token);
        }
        self::assertMatchesRegularExpression('/--ag-borda-ctl:\s+#7b93a2;/', $css);
        self::assertMatchesRegularExpression('/--ag-borda:\s+#d6e0e7;/', $css);
        self::assertStringNotContainsString('.ag-chip.is-concluido, .ag-bloco.is-concluido { opacity: .6; }', $css);
        self::assertStringContainsString('.ag-chip.is-concluido > span::before, .ag-bloco.is-concluido > b::after { content: "\2713"; }', $css);
        self::assertStringNotContainsString('#7a8e9b', $this->ler('templates/agenda/nova.html.twig'));
        self::assertStringNotContainsString('text-decoration: line-through; opacity: .7;', $css, 'cancelado sem opacidade');
    }
}
