<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E5 da frente de fidelidade (`docs/specs/fidelidade-designer-1310.md` §9): Minhas Metas contra
 * `04 - Minhas Metas 1.0.dc.html` (pacote do Claude Designer de 13/10).
 *
 * - Cantos: cartões, abas, campos e botões com 4px; KPIs (desenho de 22/10), abas internas e status com 3px; o
 *   trilho e o preenchimento de "Seu andamento" com 4px.
 * - Hover da linha (dc L17-18): fundo #3b8fc2, faixa interna 4px #1f6f9c, sombra 0 4px 12px, e todo o
 *   conteúdo da linha em branco, sem fundo nem borda própria.
 * - Número da meta antes do título (dc L86): #0a6fa0, 10px de respiro.
 *
 * Que a linha fica azul na tela é a comparação visual da spec; aqui se prova que a regra está no seletor.
 */
final class MinhasMetasCantosHoverCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/minhas-metas.css';

    private function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS));
    }

    /** Corpos de todas as regras cuja lista de seletores contém exatamente `$seletor`. */
    private function regras(string $seletor): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->css(), $m, PREG_SET_ORDER);
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

    /** @return iterable<string, array{string, string}> */
    public static function raios(): iterable
    {
        yield 'KPI (dc 31/10 L83)' => ['.mm-kpi', '4px'];
        yield 'faixa das abas (dc L68)' => ['.mm-abas', '4px'];
        yield 'aba (dc L70)' => ['.mm-aba', '3px'];
        yield 'contagem da aba' => ['.mm-aba-badge', '3px'];
        yield 'cartão dos grupos (dc L81)' => ['.mm-cartao', '4px'];
        yield 'status da meta (dc L93)' => ['.mm-pilula', '3px'];
        yield 'marcador de acompanhamento (dc L96)' => ['.mm-marcador', '4px'];
        yield 'ícone de ação (dc L97)' => ['.mm-acao-icone', '4px'];
        yield 'trilho de Seu andamento (dc L108)' => ['.mm-progresso-barra', '4px'];
        yield 'preenchimento de Seu andamento (dc L108)' => ['.mm-progresso-barra > span', '4px'];
    }

    #[DataProvider('raios')]
    #[TestDox('canto como no dc de 13/10: $seletor = $raio')]
    public function testCantos(string $seletor, string $raio): void
    {
        self::assertMatchesRegularExpression('/border-radius:\s*' . preg_quote($raio, '/') . ';/', $this->regras($seletor));
        self::assertDoesNotMatchRegularExpression('/border-radius:\s*(999px|[5-9]px|1\d+px)/', $this->regras($seletor));
    }

    #[TestDox('hover da linha da meta: fundo #3b8fc2, faixa #1f6f9c, sombra e por cima das vizinhas (dc L17)')]
    public function testHoverDaLinha(): void
    {
        $regra = $this->regras('.mm-meta:hover');

        self::assertMatchesRegularExpression('/background:\s*var\(--mm-linha-hover-bg\)/', $regra);
        self::assertStringContainsString('box-shadow: inset 4px 0 0 var(--mm-linha-hover-barra), 0 4px 12px rgba(10,111,160,.16);', $regra, 'faixa e sombra na mesma declaração');
        self::assertStringNotContainsString('border-left', $regra);
        self::assertMatchesRegularExpression('/z-index:\s*1;/', $regra);
        self::assertMatchesRegularExpression('/--mm-linha-hover-bg:\s*#3b8fc2;/', $this->css());
        self::assertMatchesRegularExpression('/--mm-linha-hover-barra:\s*#1f6f9c;/', $this->css());
    }

    #[TestDox('no hover todo o conteúdo da linha fica branco, sem fundo nem borda própria (dc L18)')]
    public function testConteudoBrancoNoHover(): void
    {
        $regra = $this->regras('.mm-meta:hover .mm-meta-corpo *');

        self::assertMatchesRegularExpression('/color:\s*#fff/', $regra);
        self::assertMatchesRegularExpression('/background:\s*transparent/', $regra);
        self::assertMatchesRegularExpression('/border-color:\s*transparent/', $regra);
        foreach (['.mm-meta:hover .mm-meta-lado *', '.mm-meta:hover > .mm-marcador', '.mm-meta:hover > .mm-marcador *'] as $seletor) {
            self::assertSame($regra, $this->regras($seletor), $seletor . ' vai na mesma regra');
        }
    }

    #[TestDox('número da meta: #0a6fa0 e 10px antes do título (dc L86)')]
    public function testNumeroDaMeta(): void
    {
        $regra = $this->regras('.mm-meta-num');

        self::assertMatchesRegularExpression('/color:\s*var\(--mm-num\)/', $regra);
        self::assertMatchesRegularExpression('/--mm-num:\s*#0a6fa0;/', $this->css());
        self::assertMatchesRegularExpression('/margin-right:\s*10px;/', $regra);
    }

    #[TestDox('Desenho de 22/10 (dc L87): faixa de 4px em sombra interna, sem border-left; atrasada também branca; o hover continua vencendo')]
    public function testLinhaAtrasadaEFaixa(): void
    {
        $linha = $this->regras('.mm-meta');
        self::assertStringNotContainsString('border-left', $linha);
        self::assertMatchesRegularExpression('/padding:\s*18px 22px;/', $linha);
        self::assertMatchesRegularExpression('/transition:\s*background \.15s, box-shadow \.15s;/', $linha);
        self::assertStringNotContainsString('background', $this->regras('.mm-meta.tom-danger'), 'a atrasada não tem fundo próprio');
        self::assertStringNotContainsString('--mm-linha-atrasada-bg', $this->css());
        self::assertGreaterThan(strpos($this->css(), '.mm-meta.tom-danger'), strpos($this->css(), '.mm-meta:hover {'), 'hover depois dos tons');
    }

    #[TestDox('Título da meta em caixa alta, 14.5px negrito, uma linha com reticências (dc L89); a concluída não é riscada nem clareada')]
    public function testTituloEmCaixaAlta(): void
    {
        $regra = $this->regras('.mm-meta-titulo');
        foreach (['/text-transform:\s*uppercase;/', '/font-size:\s*14\.5px;/', '/font-weight:\s*700;/', '/white-space:\s*nowrap;/', '/text-overflow:\s*ellipsis;/', '/overflow:\s*hidden;/'] as $re) {
            self::assertMatchesRegularExpression($re, $regra);
        }
        self::assertStringNotContainsString('.mm-meta.tom-ok .mm-meta-titulo', $this->css());
        self::assertStringNotContainsString('line-through', $this->css());
        self::assertStringNotContainsString('a.mm-meta-titulo:hover', $this->css(), 'sem sublinhado no hover');
    }

    #[TestDox('E5b: pílula de status sem borda (dc L93) e com os tons novos (Sem prazo cinza)')]
    public function testPilulaSemBordaETomCinza(): void
    {
        self::assertMatchesRegularExpression('/border:\s*0;/', $this->regras('.mm-pilula'));
        self::assertMatchesRegularExpression('/background:\s*var\(--mm-sem-bg\)/', $this->regras('.mm-pilula.tom-cinza'));
    }

    #[TestDox('E5b: abas e filtros num cartão só, com separador de 1×28px (dc L65-67)')]
    public function testCartaoDaBarra(): void
    {
        $cartao = $this->regras('.mm-barra-cartao');
        self::assertMatchesRegularExpression('/border-radius:\s*4px;/', $cartao);
        self::assertMatchesRegularExpression('/padding:\s*14px 18px;/', $cartao);
        $sep = $this->regras('.mm-barra-sep');
        self::assertMatchesRegularExpression('/width:\s*1px;/', $sep);
        self::assertMatchesRegularExpression('/height:\s*28px;/', $sep);
    }

    #[TestDox('E5b: trilho fixo na rolagem abaixo do topo e faixas de 3px verde e vermelha (dc L104/L114)')]
    public function testTrilhoFixoEFaixas(): void
    {
        $css = $this->css();
        self::assertMatchesRegularExpression('/\.mm-trilho\s*\{[^}]*position:\s*sticky;[^}]*top:\s*calc\(var\(--header-height\) \+ var\(--subnav-height\) \+ 16px\)/', $css);
        self::assertMatchesRegularExpression('/overflow:\s*visible;/', $this->regras('body.layout-fixed .app-main:has(.mm-page)'), 'sem isto o main do AdminLTE prende o sticky');
        self::assertMatchesRegularExpression('/border-bottom:\s*3px solid var\(--mm-faixa-andamento\)/', $this->regras('.mm-trilho > .mm-cartao.mm-trilho-andamento'));
        self::assertMatchesRegularExpression('/border-bottom:\s*3px solid var\(--mm-faixa-atencao\)/', $this->regras('.mm-trilho > .mm-cartao.mm-trilho-atencao'));
    }

    #[TestDox('Indicador do desenho de 31/10 (dc L83) com ícone e anel, sem halo, faixa nem estado ativo; número 42px, vermelho só com atrasadas')]
    public function testIndicadores(): void
    {
        foreach (['.mm-kpi-halo', '.mm-kpi-faixa', '.mm-kpi.is-ativo'] as $saiu) {
            self::assertStringNotContainsString($saiu, $this->css());
        }
        self::assertMatchesRegularExpression('/font-size:\s*42px;/', $this->regras('.mm-kpi-valor'));
        self::assertMatchesRegularExpression('/color:\s*var\(--mm-danger-text\)/', $this->regras('.mm-kpi-valor.is-alerta'));
    }

    #[TestDox('Traço de 34×3px sob o título: o vermelho do P6 segue no .mm-titulo geral (Equipe); em Minhas Metas é o azul do desenho (dono, 22/10)')]
    public function testTracoSobOTitulo(): void
    {
        $traco = $this->regras('.mm-page .mm-titulo::after');
        self::assertMatchesRegularExpression('/width:\s*34px;/', $traco);
        self::assertMatchesRegularExpression('/height:\s*3px;/', $traco);
        self::assertMatchesRegularExpression('/background:\s*var\(--mm-danger-dot\)/', $traco);
        self::assertMatchesRegularExpression('/background:\s*var\(--mm-num\)/', $this->regras('.mm-page .mm-cabecalho .mm-titulo::after'));
    }
}
