<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Valores do CSS do desenho 16 para a aba Equipe (etapa E5, L210 a L219), regra a regra, em
 * `public/css/ponto-eletronico.css`. O teste funcional (EquipeDoPontoTelaTest) confere as classes no HTML; este confere
 * o que cada classe faz. Os hex do desenho viram os tokens da tela (o tema escuro troca os tokens).
 */
#[Group('ponto')]
#[CoversNothing]
final class EquipeDoDesenhoCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/ponto-eletronico.css';

    /** @param array<string, string> $esperado */
    #[DataProvider('regras')]
    #[TestDox('A regra $seletor tem os valores do desenho')]
    public function testRegraComOsValoresDoDesenho(string $seletor, array $esperado): void
    {
        $declaracoes = self::declaracoes(self::semMedia(self::css()), $seletor);

        foreach ($esperado as $propriedade => $valor) {
            self::assertSame($valor, $declaracoes[$propriedade] ?? null, sprintf('%s { %s }', $seletor, $propriedade));
        }
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function regras(): iterable
    {
        yield 'grade dos indicadores (L212)' => ['.pe-eq-kpis', ['display' => 'grid', 'grid-template-columns' => 'repeat(auto-fit, minmax(130px, 1fr))', 'gap' => '1px', 'background' => 'var(--pe-div)', 'border-bottom' => '1px solid var(--pe-div)']];
        yield 'quadro (L213)' => ['.pe-eq-kpi', ['background' => 'var(--pe-card)', 'padding' => '10px 14px']];
        yield 'rótulo do quadro' => ['.pe-eq-kpi-rotulo', ['font-size' => '11.5px', 'font-weight' => 'bold', 'color' => 'var(--pe-texto-3)']];
        yield 'valor do quadro' => ['.pe-eq-kpi-valor', ['display' => 'block', 'font-size' => '24px']];
        yield 'Em jornada (#1e7b45)' => ['.pe-eq-kpi-valor--jornada', ['color' => 'var(--pe-ok-forte)']];
        yield 'Em intervalo (#0a6fa0)' => ['.pe-eq-kpi-valor--intervalo', ['color' => 'var(--pe-link)']];
        yield 'Sem registro (#8a6200)' => ['.pe-eq-kpi-valor--sem', ['color' => 'var(--pe-num-pend)']];
        yield 'Pendências (#34505f)' => ['.pe-eq-kpi-valor--pendencias', ['color' => 'var(--pe-texto-2)']];
        yield 'A analisar (#a3232b)' => ['.pe-eq-kpi-valor--analisar', ['color' => 'var(--pe-perigo)']];
        yield 'rolagem da tabela (L215)' => ['.pe-eq-rolagem', ['overflow-x' => 'auto']];
        yield 'subtítulo do colaborador' => ['.pe-eq-sub', ['font-size' => '12px', 'color' => 'var(--pe-mudo)']];
        yield 'selo da situação' => ['.pe-eq-selo', ['display' => 'inline-flex', 'align-items' => 'center', 'height' => '22px', 'padding' => '0 8px', 'border-radius' => '3px', 'color' => '#fff', 'font-size' => '12px', 'font-weight' => 'bold']];
        yield 'selo Em jornada' => ['.pe-eq-selo--jornada', ['background' => '#1e7b45']];
        yield 'selo Em intervalo' => ['.pe-eq-selo--intervalo', ['background' => '#0a6fa0']];
        yield 'selo Sem registro' => ['.pe-eq-selo--sem', ['background' => '#8a6200']];
        yield 'selo Encerrada' => ['.pe-eq-selo--encerrada', ['background' => '#5b7383']];
        yield 'banco em negrito' => ['.pe-tab td.pe-eq-banco', ['font-weight' => 'bold']];
        yield 'escala' => ['.pe-eq-escala', ['height' => '30px', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'font' => 'inherit', 'font-size' => '12.5px', 'color' => 'var(--pe-texto)']];
        yield 'barra (L216)' => ['.pe-eq-barra', ['display' => 'flex', 'align-items' => 'center', 'gap' => '10px', 'flex-wrap' => 'wrap', 'padding' => '10px 16px', 'background' => 'var(--pe-th-bg)', 'border-top' => '1px solid var(--pe-borda)', 'border-bottom' => '1px solid var(--pe-borda)']];
        yield 'título da barra' => ['.pe-eq-barra-titulo', ['flex' => '1 1 200px', 'font-size' => '14px']];
        yield 'Abonar selecionadas' => ['.pe-eq-btn-abonar', ['height' => '30px', 'padding' => '0 11px', 'border' => '1px solid var(--pe-ok-forte)', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'color' => 'var(--pe-ok-cor)', 'font' => 'inherit', 'font-size' => '12.5px', 'cursor' => 'pointer']];
        yield 'linha da lista (L217)' => ['.pe-eq-just', ['display' => 'flex', 'align-items' => 'center', 'gap' => '10px 14px', 'flex-wrap' => 'wrap', 'padding' => '9px 16px', 'border-bottom' => '1px solid var(--pe-div)', 'font-size' => '13.5px', 'cursor' => 'pointer']];
        yield 'marca' => ['.pe-eq-just-marca', ['width' => '16px', 'height' => '16px']];
        yield 'data da lista' => ['.pe-eq-just-data', ['font-variant-numeric' => 'tabular-nums']];
        yield 'subtítulo da lista' => ['.pe-eq-just-sub', ['color' => 'var(--pe-mudo)', 'font-size' => '12.5px', 'flex' => '1', 'min-width' => '120px']];
        yield 'lista vazia (L218)' => ['.pe-eq-just-vazia', ['padding' => '18px', 'text-align' => 'center', 'font-size' => '13.5px', 'color' => 'var(--pe-mudo)']];
        yield 'Tentar de novo do painel que falhou (botão de borda azul, L196)' => ['.pe-painel-tentar', ['height' => '30px', 'padding' => '0 11px', 'border' => '1px solid var(--pe-azul)', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'color' => 'var(--pe-link)', 'font' => 'inherit', 'font-size' => '12.5px', 'cursor' => 'pointer', 'display' => 'flex', 'align-items' => 'center', 'gap' => '6px']];
        yield 'Tentar de novo, mouse (#e5f1f7, L196)' => ['.pe-painel-tentar:hover', ['background' => 'var(--pe-info-bg)']];
        yield 'Tentar de novo, foco do teclado (o da tela)' => ['.pe-painel-tentar:focus-visible', ['outline' => '2px solid var(--pe-link)', 'outline-offset' => '2px']];
    }

    #[TestDox('Os tokens do "Tentar de novo" valem o hex do botão do desenho (L196) no tema claro')]
    public function testTokensDoTentarDeNovo(): void
    {
        $tokens = self::declaracoes(self::semMedia(self::css()), '.pe-page,.pe-folha');

        self::assertSame(
            ['#0a6fa0', '#fff', '#0a6fa0', '#e5f1f7'],
            array_map(static fn (string $t): ?string => $tokens[$t] ?? null, ['--pe-azul', '--pe-card', '--pe-link', '--pe-info-bg']),
        );
    }

    #[TestDox('Os tokens usados no lugar dos hex do desenho valem o hex no tema claro')]
    public function testTokensNoTemaClaro(): void
    {
        $tokens = self::declaracoes(self::semMedia(self::css()), '.pe-page,.pe-folha');

        self::assertSame(
            ['#e6edf2', '#fff', '#4a6373', '#1e7b45', '#0a6fa0', '#8a6200', '#34505f', '#a3232b', '#5b7383', '#d3dde4', '#12242f', '#eef3f6', '#145a32'],
            array_map(static fn (string $t): ?string => $tokens[$t] ?? null, [
                '--pe-div', '--pe-card', '--pe-texto-3', '--pe-ok-forte', '--pe-link', '--pe-num-pend', '--pe-texto-2', '--pe-perigo',
                '--pe-mudo', '--pe-borda', '--pe-texto', '--pe-th-bg', '--pe-ok-cor',
            ]),
        );
    }

    #[TestDox('O bloco da E5 fica no fim do arquivo, com o cabeçalho, e não tem regra de celular (a tabela rola para o lado)')]
    public function testBlocoDaE5(): void
    {
        $css = self::css();
        $inicio = strpos($css, 'Aba Equipe (etapa E5, L210 a L219 do desenho)');
        self::assertIsInt($inicio);
        $bloco = substr($css, $inicio);

        self::assertStringNotContainsString('@media', $bloco);
        self::assertStringNotContainsString('pe-eq', substr($css, 0, $inicio), 'nada da E5 fora do bloco');
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $bloco, 'sem travessão');
    }

    private static function css(): string
    {
        $css = file_get_contents(self::CSS);
        self::assertIsString($css);

        return $css;
    }

    private static function semMedia(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        return (string) preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css);
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
