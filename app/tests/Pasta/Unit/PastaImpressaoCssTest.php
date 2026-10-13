<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * C6-EDT-08: a janela "Imprimir" no tema escuro. `public/css/pasta-impressao.css` lido como
 * folha (não há navegador no PHPUnit): as cores da janela são tokens `--psi-*` com o par escuro
 * em `[data-bs-theme="dark"]`, e as regras da lateral e dos botões leem os tokens — não cor fixa.
 * A folha (`.rp-*`) continua branca: é papel, e mora no template, não aqui.
 */
#[Group('pasta')]
final class PastaImpressaoCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/pasta-impressao.css';

    private function css(): string
    {
        $css = file_get_contents(self::CSS);
        self::assertIsString($css);

        return $css;
    }

    /** O corpo `{ … }` da PRIMEIRA regra com exatamente este seletor. */
    private function regra(string $css, string $seletor): string
    {
        // Começo de regra: depois de outra regra (`}`) ou de um comentário (`*/`).
        $re = '/(?:^|[}\/])\s*' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/';
        self::assertSame(1, preg_match($re, $css, $m), 'regra ' . $seletor);

        return $m[1];
    }

    #[TestDox('Os tokens da janela existem no claro e têm o par escuro em [data-bs-theme="dark"]')]
    public function testTokensComParEscuro(): void
    {
        $css    = $this->css();
        $claro  = $this->regra($css, '.psi-fundo');
        $escuro = $this->regra($css, '[data-bs-theme="dark"] .psi-fundo');

        preg_match_all('/(--psi-[a-z-]+)\s*:/', $claro, $tokensClaros);
        preg_match_all('/(--psi-[a-z-]+)\s*:/', $escuro, $tokensEscuros);
        self::assertNotEmpty($tokensClaros[1]);
        self::assertSame($tokensClaros[1], $tokensEscuros[1], 'cada token do claro tem o seu valor no escuro');
    }

    #[TestDox('Janela, lateral, rótulos, opções e botões leem os tokens, não cor fixa')]
    public function testRegrasLeemOsTokens(): void
    {
        $css = $this->css();

        $esperado = [
            '.psi-janela'          => 'background: var(--psi-janela-bg)',
            '.psi-lado'            => 'background: var(--psi-lado-bg)',
            '.psi-rotulo'          => 'color: var(--psi-rotulo)',
            '.psi-opcao'           => 'color: var(--psi-texto)',
            '.psi-tamanho-btn'     => 'background: var(--psi-botao-bg)',
            '.psi-tamanho-valor'   => 'color: var(--psi-texto-forte)',
            '.psi-nota'            => 'color: var(--psi-nota)',
            '.psi-baixar'          => 'background: var(--psi-baixar-bg)',
            '.psi-copiar'          => 'background: var(--psi-botao-bg)',
        ];
        foreach ($esperado as $seletor => $declaracao) {
            self::assertStringContainsString($declaracao, $this->regra($css, $seletor), $seletor);
        }
        self::assertStringContainsString('border-left: 1px solid var(--psi-lado-bd)', $this->regra($css, '.psi-lado'));
    }
}
