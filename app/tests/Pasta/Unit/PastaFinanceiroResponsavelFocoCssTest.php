<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E4 da frente de fidelidade (`docs/specs/fidelidade-designer-1310.md` §8), pacote do Claude Designer
 * de 13/10 (`02 - EXPEDIENTES 1.2.3.dc.html`):
 *
 * - L1890: o relatório do Financeiro perdeu `max-height:520px; overflow-y:auto; overscroll-behavior:contain`
 *   — as observações crescem com a página, sem rolagem interna.
 * - L3084: o nome do responsável do cabeçalho ganhou `max-width:170px` com reticências. Só no cabeçalho
 *   da pasta: o chip é o mesmo da lista do Expediente, que não muda.
 * - Foco do botão BLUEJUS Intelligence: o desenho declara um anel que o próprio protótipo não renderiza
 *   (divergência registrada, PZ-CAB-50). Decisão do dono (14/10): manter indicador visível e acessível.
 */
final class PastaFinanceiroResponsavelFocoCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/';

    private function css(string $arquivo): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS . $arquivo));
    }

    /** Corpos de todas as regras cuja lista de seletores contém exatamente `$seletor`. */
    private function regras(string $arquivo, string $seletor): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->css($arquivo), $m, PREG_SET_ORDER);
        $corpos = [];
        foreach ($m as [, $seletores, $corpo]) {
            $lista = array_map(static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s)), explode(',', $seletores));
            if (in_array($seletor, $lista, true)) {
                $corpos[] = $corpo;
            }
        }
        self::assertNotEmpty($corpos, 'Falta a regra ' . $seletor);

        return implode("\n", $corpos);
    }

    #[TestDox('Financeiro: as observações não têm mais rolagem interna (dc L1890)')]
    public function testObservacoesSemRolagemInterna(): void
    {
        $regra = $this->regras('pasta-show.css', '.ps-fin-obs-rolagem');

        self::assertStringNotContainsString('max-height', $regra);
        self::assertStringNotContainsString('overflow-y', $regra);
        self::assertStringNotContainsString('overscroll-behavior', $regra);
        self::assertStringNotContainsString('.ps-fin-obs-rolagem::-webkit-scrollbar', $this->css('pasta-show.css'), 'sem rolagem, sem barra própria');
    }

    #[TestDox('cabeçalho: nome do responsável corta em 170px com reticências (dc L3084)')]
    public function testNomeDoResponsavelNoCabecalho(): void
    {
        $regra = $this->regras('pasta-show.css', '.ps-cab-resp .pasta-resp-chip .pasta-resp-nome');

        self::assertMatchesRegularExpression('/max-width:\s*170px;/', $regra);
        self::assertMatchesRegularExpression('/overflow:\s*hidden;/', $regra);
        self::assertMatchesRegularExpression('/text-overflow:\s*ellipsis;/', $regra);
        self::assertMatchesRegularExpression('/white-space:\s*nowrap;/', $regra);
    }

    #[TestDox('foco do botão BlueJus IA continua visível: borda e sombra no :focus-visible e o contorno do navegador não é zerado')]
    public function testFocoDaMarcaContinuaVisivel(): void
    {
        $css = $this->css('pasta-ia-agentes.css');

        self::assertMatchesRegularExpression('/border-color:\s*var\(--ia-borda-botao-hover\)/', $this->regras('pasta-ia-agentes.css', '.ps-cab-ia:focus-visible'));
        preg_match_all('/([^{}]*\.ps-cab-ia\b[^{}]*)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);
        foreach ($m as [, $seletor, $corpo]) {
            self::assertDoesNotMatchRegularExpression('/outline:\s*(none|0)\b/', $corpo, 'outline zerado em ' . trim($seletor));
        }
    }
}
