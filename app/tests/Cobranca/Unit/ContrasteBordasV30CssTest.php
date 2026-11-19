<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pedidos A3 e A4 de 19/11 (Guia visual BlueJus v30) em Cobranças, Perfis e Usuários do escritório: texto de apoio
 * com 4,5:1, opacidades que derrubavam o contraste trocadas pelo tom de apoio, campos com borda #7b93a2 e áreas com
 * #d6e0e7. Que aparece certo na tela é do smoke do dono.
 */
final class ContrasteBordasV30CssTest extends TestCase
{
    private function ler(string $caminho): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $caminho);
    }

    #[TestDox('Cobranças: sem opacidade em texto de apoio e chip de categoria vazia legível')]
    public function testCobrancas(): void
    {
        $css = $this->ler('public/css/cobrancas.css');
        self::assertStringContainsString('.carteira-chip-fonte { color: var(--bs-secondary-color); }', $css);
        self::assertStringContainsString('.jp-contato-label { font-weight: 600; color: var(--bs-secondary-color); white-space: nowrap; }', $css);
        self::assertStringContainsString('.jp-obr.is-substituida { color: var(--bs-secondary-color); }', $css);
        self::assertStringNotContainsString('#8a9aa6', $css);
        self::assertStringNotContainsString('.cobranca-row-link .sort-icon { opacity: .35; }', $css);
    }

    #[TestDox('Perfis: campos com borda #7b93a2, áreas #d6e0e7, textos de apoio e chip OFF com 4,5:1, avatar de sócio #2b7ab0')]
    public function testPerfis(): void
    {
        $css = $this->ler('public/css/perfis.css');
        self::assertMatchesRegularExpression('/--pf-borda-ctl:\s+#7b93a2;/', $css);
        self::assertMatchesRegularExpression('/--pf-lista-borda:\s+#d6e0e7;/', $css);
        self::assertMatchesRegularExpression('/--pf-perm-de:\s+#5f7684;/', $css);
        self::assertMatchesRegularExpression('/--pf-rs-off:\s+#5f7684;/', $css);
        self::assertMatchesRegularExpression('/--pf-socio:\s+#2b7ab0;/', $css);
        self::assertStringNotContainsString('#d3dde4', $css);
        self::assertStringNotContainsString('.pf-cartao.is-travado-rs { opacity', $css);
        self::assertStringContainsString('[data-bs-theme="dark"] .pf-page .pf-item.is-travado-rs { color: var(--bs-secondary-color); }', $css);
    }

    #[TestDox('Usuários do escritório: ícone de ordenação com 3:1')]
    public function testUsuarios(): void
    {
        self::assertStringContainsString('.sort-icon { font-size: 0.68em; opacity: 0.65;', $this->ler('templates/tenant/users.html.twig'));
    }
}
