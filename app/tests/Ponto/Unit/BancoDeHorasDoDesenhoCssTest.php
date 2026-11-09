<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Valores do CSS do desenho 16 para a aba "Banco de Horas" e o triângulo do topo (etapa E17, L106 e L201 a L207), regra
 * a regra, em `public/css/ponto-eletronico.css`. Os hex do desenho chegam pelos tokens do tema claro (`--pe-div`
 * #e6edf2, `--pe-texto-3` #4a6373, `--pe-incompleto-bg` #fff3cd, `--pe-alerta-cor` #7a5200, `--pe-alerta-bd` #e0c36a,
 * `--pe-mudo` #5b7383), que o tema escuro troca. O funcional confere as classes no HTML; este, o que cada uma faz.
 */
#[Group('ponto')]
#[CoversNothing]
final class BancoDeHorasDoDesenhoCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/ponto-eletronico.css';

    /** @param array<string, string> $esperado */
    #[DataProvider('regras')]
    #[TestDox('A regra $seletor tem os valores do desenho')]
    public function testRegraComOsValoresDoDesenho(string $seletor, array $esperado): void
    {
        self::assertSame($esperado, self::declaracoes(self::semComentario(self::css()), $seletor), $seletor);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function regras(): iterable
    {
        yield 'triângulo do topo (L106)' => ['.pe-banco-alerta', ['font-size' => '12px', 'color' => '#ffd666']];
        yield 'quadros (L202)' => ['.pe-bh-kpis', ['display' => 'grid', 'grid-template-columns' => 'repeat(auto-fit, minmax(170px, 1fr))', 'gap' => '1px', 'background' => 'var(--pe-div)', 'border-bottom' => '1px solid var(--pe-div)']];
        yield 'quadro (L203)' => ['.pe-bh-kpi', ['background' => 'var(--pe-card)', 'padding' => '12px 16px']];
        yield 'rótulo do quadro' => ['.pe-bh-kpi-rotulo', ['font-size' => '11.5px', 'font-weight' => 'bold', 'letter-spacing' => '.3px', 'color' => 'var(--pe-texto-3)']];
        yield 'número do quadro' => ['.pe-bh-kpi-valor', ['display' => 'block', 'font-size' => '22px', 'font-variant-numeric' => 'tabular-nums', 'margin-top' => '2px']];
        yield 'faixa do limite (L205)' => ['.pe-bh-aviso', ['padding' => '9px 16px', 'background' => 'var(--pe-incompleto-bg)', 'color' => 'var(--pe-alerta-cor)', 'font-size' => '13px', 'border-bottom' => '1px solid var(--pe-alerta-bd)']];
        yield 'saldo do lote (L206)' => ['.pe-tab td.pe-bh-saldo', ['font-weight' => 'bold']];
        yield 'selo da situação (L206)' => ['.pe-bh-selo', ['display' => 'inline-flex', 'align-items' => 'center', 'height' => '22px', 'padding' => '0 8px', 'border-radius' => '3px', 'color' => '#fff', 'font-size' => '12px', 'font-weight' => 'bold']];
        yield 'nota da lei (L207)' => ['.pe-bh-nota', ['padding' => '10px 16px', 'font-size' => '12px', 'color' => 'var(--pe-mudo)', 'line-height' => '1.5']];
    }

    #[TestDox('As regras da E17 ficam abaixo da âncora da E17 e acima da âncora da E15')]
    public function testRegrasNoLugarCombinado(): void
    {
        $css = self::css();
        $ancora = strpos($css, '/* --- E17: aba Banco de Horas, aviso do limite e triângulo do topo (P51 a, P52, P2). --- */');
        $seguinte = strpos($css, '/* --- E15:');
        self::assertIsInt($ancora);
        self::assertIsInt($seguinte);

        foreach (['.pe-banco-alerta', '.pe-bh-kpis', '.pe-bh-aviso', '.pe-bh-selo', '.pe-bh-nota'] as $seletor) {
            $posicao = strpos($css, $seletor . ' ');
            self::assertIsInt($posicao, $seletor);
            self::assertGreaterThan($ancora, $posicao, $seletor);
            self::assertLessThan($seguinte, $posicao, $seletor);
        }
    }

    private static function css(): string
    {
        $css = file_get_contents(self::CSS);
        self::assertIsString($css);

        return $css;
    }

    private static function semComentario(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** @return array<string, string> */
    private static function declaracoes(string $css, string $seletor): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $regras, PREG_SET_ORDER);
        foreach ($regras as [, $cabeca, $corpo]) {
            $nome = (string) preg_replace(['/\s*,\s*/', '/\s+/'], [',', ' '], trim($cabeca));
            if ($nome !== $seletor) {
                continue;
            }
            $declaracoes = [];
            foreach (array_filter(array_map('trim', explode(';', $corpo))) as $declaracao) {
                [$propriedade, $valor] = array_map('trim', explode(':', $declaracao, 2)) + [1 => ''];
                $declaracoes[$propriedade] = (string) preg_replace('/\s+/', ' ', $valor);
            }

            return $declaracoes;
        }

        self::fail('Regra não encontrada: ' . $seletor);
    }
}
