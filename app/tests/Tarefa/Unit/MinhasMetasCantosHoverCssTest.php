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
 * - Cantos: cartões, KPIs, abas, campos e botões com 4px; abas internas e status com 3px; o trilho e o
 *   preenchimento de "Seu andamento" com 4px. O que só o BlueJus tem (alternância cartões/lista e a
 *   pílula de prioridade) segue os irmãos mais próximos — o desenho é omisso e o README do pacote manda
 *   cantos de no máximo 4px.
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
        yield 'KPI (dc L54)' => ['.mm-kpi', '4px'];
        yield 'faixa das abas (dc L68)' => ['.mm-abas', '4px'];
        yield 'aba (dc L70)' => ['.mm-aba', '3px'];
        yield 'contagem da aba' => ['.mm-aba-badge', '3px'];
        yield 'alternância cartões/lista (só BlueJus, segue a faixa das abas)' => ['.mm-modos', '4px'];
        yield 'botão da alternância (só BlueJus, segue a aba)' => ['.mm-modo', '3px'];
        yield 'cartão dos grupos (dc L81)' => ['.mm-cartao', '4px'];
        yield 'status da meta (dc L93)' => ['.mm-pilula', '3px'];
        yield 'prioridade (só BlueJus, segue o status)' => ['.mm-prioridade', '3px'];
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
        self::assertMatchesRegularExpression('/border-left-color:\s*var\(--mm-linha-hover-barra\)/', $regra);
        self::assertStringContainsString('0 4px 12px rgba(10,111,160,.16)', $regra);
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
        foreach (['.mm-meta:hover .mm-meta-lado *', '.mm-meta:hover .mm-meta-acoes *'] as $seletor) {
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
}
