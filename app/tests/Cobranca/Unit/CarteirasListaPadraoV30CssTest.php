<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Cobranças > Carteiras no "Guia visual BlueJus" v30 (pedido A5 de 19/11): sub-navegação com botões de 32px e
 * raio 4px, busca e filtro "Modo" retangulares com a borda de campo do guia, e o realce azul da linha.
 * Que aparece certo na tela é do smoke do dono.
 */
final class CarteirasListaPadraoV30CssTest extends TestCase
{
    private function css(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/css/' . $arquivo);
    }

    #[TestDox('Sub-navegação Painel/Carteiras/Alertas/Central: 32px, raio 4px, 13px/600; ativo no Secundário do guia')]
    public function testSubnavNoPadraoDoBotao(): void
    {
        $css = $this->css('cobrancas.css');
        self::assertStringContainsString('height: 32px; padding: 0 14px; border-radius: 4px;', $css);
        self::assertStringContainsString('font-size: 13px; font-weight: 600;', $css);
        self::assertStringContainsString('.cobranca-subnav-item.active { background: #e8f2fa; color: #0b5f86; }', $css);
        self::assertStringNotContainsString('padding: .4rem .85rem; border-radius: .5rem;', $css);
    }

    #[TestDox('Linha da tabela: realce #2b7ab0 com texto branco, no lugar do cinza')]
    public function testRealceDaLinha(): void
    {
        $css = $this->css('cobrancas.css');
        self::assertStringContainsString('.cobranca-card .table > tbody > tr:hover > td { background: #2b7ab0; color: #fff; }', $css);
        self::assertStringContainsString('.cobranca-card .table > tbody > tr:hover > td * { color: #fff !important; }', $css);
        self::assertStringNotContainsString('.cobranca-card .table > tbody > tr:hover { background: var(--bs-tertiary-bg); }', $css);
    }

    #[TestDox('Busca, filtros, datas e chips do filtro: raio 4px, sem pílula; campos com borda #7b93a2')]
    public function testFiltroRetangular(): void
    {
        $css = $this->css('filtro-tabela.css');
        self::assertDoesNotMatchRegularExpression('/border-radius:\s*(16|20)px/', $css);
        self::assertStringContainsString('html:not([data-bs-theme="dark"]) .filtro-data-campo { border-color: #7b93a2; }', $css);
    }
}
