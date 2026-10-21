<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O selo "Nº 0001" com os valores do desenho 02 (dc L1559), as cores em token. */
#[CoversNothing]
final class MetaNumeroGlobalCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/meta-numero-global.css';

    private function regra(string $seletor): string
    {
        $css = (string) file_get_contents(self::CSS);
        self::assertSame(1, preg_match('/^' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/m', $css, $m), "regra {$seletor} não encontrada");

        return $m[1];
    }

    #[TestDox('As cores do desenho moram em token: fundo #e9f1f6 e texto #4f6878')]
    public function testCoresEmToken(): void
    {
        $raiz = $this->regra(':root');
        self::assertStringContainsString('--meta-num-global-bg: #e9f1f6;', $raiz);
        self::assertStringContainsString('--meta-num-global-tx: #4f6878;', $raiz);
        self::assertStringContainsString('--meta-num-global-bg:', $this->regra('[data-bs-theme="dark"]'), 'o tema escuro troca o token');
    }

    #[TestDox('O selo usa os tokens e os valores do desenho: 11px/700, 1px 7px, canto 3px, algarismos tabulares')]
    public function testSeloComOsValoresDoDesenho(): void
    {
        $selo = $this->regra('.meta-num-global');
        foreach ([
            'display: inline-block;', 'margin-left: 8px;', 'padding: 1px 7px;', 'border-radius: 3px;',
            'background: var(--meta-num-global-bg);', 'color: var(--meta-num-global-tx);',
            'font-size: 11px;', 'font-weight: 700;', 'letter-spacing: .02em;',
            'font-variant-numeric: tabular-nums;', 'vertical-align: 2px;', 'text-decoration: none;',
        ] as $declaracao) {
            self::assertStringContainsString($declaracao, $selo);
        }
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,6}/i', $selo, 'cor só por token');
    }
}
