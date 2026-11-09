<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Valores do CSS do desenho 16 para a Folha de Ponto (etapa E2), regra a regra, em `public/css/ponto-eletronico.css`.
 * O valor é o ponto do acerto (listra, azul ao passar o mouse, medidas da barra, selos e o cartão do celular): o teste
 * funcional confere as classes no HTML; este confere o que cada classe faz.
 */
#[Group('ponto')]
#[CoversNothing]
final class FolhaDoDesenhoCssTest extends TestCase
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
        yield 'cartão (L144)' => ['.pe-folha', ['background' => 'var(--pe-card)', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'box-shadow' => '0 1px 3px var(--pe-sombra)']];
        yield 'barra (L145)' => ['.pe-folha-barra', ['display' => 'flex', 'align-items' => 'center', 'gap' => '10px', 'flex-wrap' => 'wrap', 'padding' => '9px 16px', 'background' => 'var(--pe-azul)', 'color' => 'var(--pe-sobre-azul)', 'position' => 'relative']];
        yield 'ícone da barra' => ['.pe-folha-barra > .bi', ['font-size' => '17px']];
        yield 'título da barra' => ['.pe-folha-titulo', ['font-size' => '16px', 'font-weight' => 'bold']];
        yield 'lista do mês' => ['.pe-folha-mes', ['height' => '30px', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'background' => '#fff', 'color' => '#12242f', 'font' => 'inherit', 'font-size' => '13px', 'padding' => '0 6px']];
        yield 'espaçador' => ['.pe-folha-espaco', ['flex' => '1']];
        yield 'botão da barra (L146)' => ['.pe-folha-btn', ['height' => '30px', 'padding' => '0 11px', 'border' => '1px solid rgba(255, 255, 255, .65)', 'border-radius' => '3px', 'background' => 'transparent', 'color' => '#fff', 'font' => 'inherit', 'font-size' => '13px', 'cursor' => 'pointer', 'display' => 'flex', 'align-items' => 'center', 'gap' => '7px']];
        yield 'botão da barra no hover' => ['.pe-folha-btn:hover', ['background' => 'rgba(255, 255, 255, .16)']];
        yield 'rolagem (L148)' => ['.pe-folha-rolagem', ['overflow-x' => 'auto']];
        yield 'tabela (L33)' => ['.pe-tab', ['width' => '100%', 'border-collapse' => 'collapse', 'font-size' => '14px']];
        yield 'cabeçalho (L34)' => ['.pe-tab th', ['text-align' => 'left', 'padding' => '10px 12px', 'font-size' => '12px', 'letter-spacing' => '.3px', 'color' => 'var(--pe-texto-2)', 'background' => 'var(--pe-th-bg)', 'border-bottom' => '1px solid var(--pe-borda)', 'white-space' => 'nowrap']];
        yield 'célula (L35)' => ['.pe-tab td', ['padding' => '9px 12px', 'border-bottom' => '1px solid var(--pe-div)', 'white-space' => 'nowrap', 'font-variant-numeric' => 'tabular-nums']];
        yield 'listra (L36)' => ['.pe-lin:nth-child(odd)', ['background' => 'var(--pe-listra)']];
        yield 'linha no hover (L37)' => ['.pe-lin:hover td', ['background' => '#3b8fc2', 'color' => '#fff']];
        yield 'botões no hover (L38)' => ['.pe-lin:hover td button', ['color' => '#fff']];
        yield 'saldos no hover (L39)' => ['.pe-lin:hover td.pe-sm,.pe-lin:hover .pe-pl', ['color' => '#fff']];
        yield '"+" (L41)' => ['.pe-mais', ['width' => '24px', 'height' => '24px', 'border' => 'none', 'background' => 'transparent', 'color' => 'var(--pe-mudo)', 'cursor' => 'pointer', 'padding' => '0', 'font-size' => '15px', 'border-radius' => '3px']];
        yield '"+" no hover (L42)' => ['.pe-mais:hover', ['background' => 'rgba(255, 255, 255, .25)']];
        yield 'selo do saldo dia (L156)' => ['.pe-tag', ['display' => 'inline-flex', 'align-items' => 'center', 'gap' => '5px', 'height' => '22px', 'padding' => '0 7px', 'border' => '1px solid var(--pe-tag-bd)', 'border-radius' => '3px', 'background' => 'var(--pe-tag-bg)', 'color' => 'var(--pe-tag-cor)', 'font-size' => '12px', 'white-space' => 'nowrap']];
        yield 'selo vermelho' => ['.pe-tag--erro', ['--pe-tag-bd' => 'var(--pe-erro-bd)', '--pe-tag-bg' => 'var(--pe-erro-bg)', '--pe-tag-cor' => 'var(--pe-erro-cor)']];
        yield 'selo azul' => ['.pe-tag--info', ['--pe-tag-bd' => 'var(--pe-info-bd)', '--pe-tag-bg' => 'var(--pe-info-bg)', '--pe-tag-cor' => 'var(--pe-info-cor)']];
        yield 'selo âmbar' => ['.pe-tag--alerta', ['--pe-tag-bd' => 'var(--pe-alerta-bd)', '--pe-tag-bg' => 'var(--pe-incompleto-bg)', '--pe-tag-cor' => 'var(--pe-alerta-cor)']];
        yield 'saldo mês (L157)' => ['.pe-sm', ['font-weight' => 'bold']];
        yield 'verde' => ['.pe-cor--pos', ['color' => 'var(--pe-ok-forte)']];
        yield 'vermelho' => ['.pe-cor--neg', ['color' => 'var(--pe-perigo)']];
        yield 'zero' => ['.pe-cor--zero', ['color' => 'var(--pe-texto-2)']];
        yield 'selo da justificativa (L158)' => ['.pe-just', ['display' => 'inline-flex', 'align-items' => 'center', 'gap' => '5px', 'height' => '22px', 'padding' => '0 8px', 'border-radius' => '3px', 'color' => '#fff', 'font-size' => '12px', 'font-weight' => 'bold', 'white-space' => 'nowrap']];
        yield 'Abonada' => ['.pe-just--abonada', ['background' => '#1e7b45']];
        yield 'Em análise' => ['.pe-just--analise', ['background' => '#0a6fa0']];
        yield 'Recusada' => ['.pe-just--recusada,.pe-just--nao-abonada', ['background' => '#a3232b']];
        yield 'folha vazia (L160)' => ['.pe-tab.pe-resp td.pe-folha-vazia', ['padding' => '18px', 'color' => 'var(--pe-mudo)', 'text-align' => 'center']];
    }

    #[TestDox('Os tokens da Folha são os hex do desenho e valem também fora da página (ficha do colaborador)')]
    public function testTokensDaFolha(): void
    {
        $tokens = self::declaracoes(self::semMedia(self::css()), '.pe-page,.pe-folha');

        self::assertSame('#eef3f6', $tokens['--pe-th-bg'] ?? null);
        self::assertSame('#eef2f5', $tokens['--pe-listra'] ?? null);
        self::assertSame('#1e7b45', $tokens['--pe-ok-forte'] ?? null);
        self::assertSame('#fff3cd', $tokens['--pe-incompleto-bg'] ?? null);
        self::assertSame('#a3232b', $tokens['--pe-perigo'] ?? null);
        self::assertSame('#34505f', $tokens['--pe-texto-2'] ?? null);
        self::assertSame('#5b7383', $tokens['--pe-mudo'] ?? null);
        self::assertSame('#e6edf2', $tokens['--pe-div'] ?? null);
        self::assertSame('#f1c4c9', $tokens['--pe-erro-bd'] ?? null);
        self::assertSame('#fdecee', $tokens['--pe-erro-bg'] ?? null);
        self::assertSame('#7f1c23', $tokens['--pe-erro-cor'] ?? null);
        self::assertSame('#9fc3d8', $tokens['--pe-info-bd'] ?? null);
        self::assertSame('#e5f1f7', $tokens['--pe-info-bg'] ?? null);
        self::assertSame('#0b4f75', $tokens['--pe-info-cor'] ?? null);
        self::assertSame('#e0c36a', $tokens['--pe-alerta-bd'] ?? null);
        self::assertSame('#7a5200', $tokens['--pe-alerta-cor'] ?? null);
    }

    #[TestDox('Celular (até 760 px): o cabeçalho some e cada dia vira um cartão de quatro colunas com o nome do campo')]
    public function testCelular(): void
    {
        $media = self::media(self::css(), '@media (max-width: 760px)');

        $esperado = [
            '.pe-tab.pe-resp thead' => ['display' => 'none'],
            '.pe-tab.pe-resp,.pe-tab.pe-resp tbody' => ['display' => 'block'],
            '.pe-tab.pe-resp tr' => ['display' => 'grid', 'grid-template-columns' => 'repeat(4, minmax(0, 1fr))', 'gap' => '8px 6px', 'padding' => '10px 12px', 'border-bottom' => '1px solid var(--pe-div)'],
            '.pe-tab.pe-resp td' => ['display' => 'block', 'padding' => '0', 'border' => 'none', 'white-space' => 'normal', 'font-size' => '13px', 'min-width' => '0'],
            '.pe-tab.pe-resp td[data-r]::before' => ['content' => 'attr(data-r)', 'display' => 'block', 'font-size' => '10.5px', 'font-weight' => 'bold', 'color' => 'var(--pe-mudo)', 'letter-spacing' => '.2px'],
            '.pe-tab.pe-resp td.pe-larga' => ['grid-column' => 'span 2'],
            '.pe-tab.pe-resp td.pe-toda' => ['grid-column' => '1 / -1'],
            '.pe-tab.pe-resp td.pe-vazia' => ['display' => 'none'],
        ];
        foreach ($esperado as $seletor => $valores) {
            $declaracoes = self::declaracoes($media, $seletor);
            foreach ($valores as $propriedade => $valor) {
                self::assertSame($valor, $declaracoes[$propriedade] ?? null, sprintf('%s { %s }', $seletor, $propriedade));
            }
        }
    }

    #[TestDox('D15: listra e azul ao passar o mouse são só da linha da Folha, nunca da tabela genérica')]
    public function testListraSoNaFolha(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', self::css());

        self::assertDoesNotMatchRegularExpression('/\.pe-tab[^{,]*\btr\b[^{,]*:(?:nth-child|hover)/', $css);
        self::assertDoesNotMatchRegularExpression('/\.pe-tab[^{,]*tbody[^{,]*:(?:nth-child|hover)/', $css);
        self::assertMatchesRegularExpression('/\.pe-lin:nth-child\(odd\)\s*\{/', $css, 'a listra é da linha da Folha');
        self::assertArrayNotHasKey('overflow', self::declaracoes(self::semMedia(self::css()), '.pe-folha'), 'o cartão da folha não corta (L144)');
    }

    private static function css(): string
    {
        $css = file_get_contents(self::CSS);
        self::assertIsString($css);

        return $css;
    }

    /** O CSS sem comentários e sem os blocos `@media`, para achar a regra de fora. */
    private static function semMedia(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        return (string) preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css);
    }

    private static function media(string $css, string $cabecalho): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        self::assertSame(1, preg_match('/' . preg_quote($cabecalho, '/') . '\s*\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/', $css, $achado), $cabecalho);

        return $achado[1];
    }

    /**
     * Declarações da regra cujo seletor (sem espaços em volta das vírgulas) é exatamente `$seletor`.
     *
     * @return array<string, string>
     */
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
