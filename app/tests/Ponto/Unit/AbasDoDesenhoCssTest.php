<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Valores do CSS do desenho 16 para o cartão de abas e a moldura de janela (etapa E3), regra a regra, em
 * `public/css/ponto-eletronico.css`. O teste funcional confere as classes no HTML; este confere o que cada classe faz.
 */
#[Group('ponto')]
#[CoversNothing]
final class AbasDoDesenhoCssTest extends TestCase
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
        yield 'cartão (L166)' => ['.pe-abas', ['background' => 'var(--pe-card)', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'overflow' => 'hidden', 'box-shadow' => '0 1px 3px var(--pe-sombra)']];
        yield 'barra de abas (L167)' => ['.pe-abas-barra', ['display' => 'flex', 'gap' => '2px', 'overflow-x' => 'auto', 'background' => 'var(--pe-th-bg)', 'border-bottom' => '1px solid var(--pe-borda)']];
        yield 'aba (L168)' => ['.pe-aba', ['padding' => '12px 14px 9px', 'border' => 'none', 'border-bottom' => '3px solid transparent', 'background' => 'transparent', 'color' => 'var(--pe-texto-2)', 'font' => 'inherit', 'font-size' => '13.5px', 'font-weight' => 'bold', 'cursor' => 'pointer', 'white-space' => 'nowrap', 'display' => 'flex', 'align-items' => 'center', 'gap' => '7px']];
        yield 'aba ativa (L759)' => ['.pe-aba[aria-selected="true"]', ['border-bottom-color' => 'var(--pe-azul)', 'background' => 'var(--pe-card)', 'color' => 'var(--pe-link)']];
        yield 'número da aba' => ['.pe-aba-num', ['min-width' => '18px', 'height' => '18px', 'padding' => '0 4px', 'box-sizing' => 'border-box', 'border-radius' => '3px', 'background' => 'var(--pe-azul)', 'color' => '#fff', 'font-size' => '11px', 'display' => 'inline-flex', 'align-items' => 'center', 'justify-content' => 'center']];
        yield 'número das pendências' => ['.pe-aba-num--pend', ['background' => 'var(--pe-num-pend)']];
        yield 'lista vazia (L175, L190)' => ['.pe-abas-vazio', ['padding' => '22px', 'text-align' => 'center', 'font-size' => '13.5px', 'color' => 'var(--pe-mudo)']];
        yield 'texto das pendências (L172)' => ['.pe-pend-texto', ['padding' => '10px 16px', 'font-size' => '12.5px', 'color' => 'var(--pe-mudo)', 'border-bottom' => '1px solid var(--pe-div)']];
        yield 'lista das pendências (L173)' => ['.pe-pend-lista', ['max-height' => '360px', 'overflow-y' => 'auto']];
        yield 'linha da pendência (L174)' => ['.pe-pend', ['display' => 'flex', 'align-items' => 'center', 'gap' => '6px 14px', 'flex-wrap' => 'wrap', 'padding' => '8px 16px', 'border-bottom' => '1px solid var(--pe-div)']];
        yield 'data da pendência' => ['.pe-pend-data', ['font-size' => '13.5px', 'font-variant-numeric' => 'tabular-nums']];
        yield 'o que falta' => ['.pe-pend-falta', ['font-size' => '13.5px', 'color' => 'var(--pe-corpo)']];
        yield 'escritório' => ['.pe-pend-escritorio', ['flex' => '1', 'min-width' => '0', 'overflow' => 'hidden', 'text-overflow' => 'ellipsis', 'white-space' => 'nowrap', 'font-size' => '12.5px', 'color' => 'var(--pe-mudo)']];
        yield 'enviar registro' => ['.pe-pend-btn', ['margin-left' => 'auto', 'height' => '30px', 'padding' => '0 11px', 'border' => '1px solid var(--pe-alerta-btn)', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'color' => 'var(--pe-alerta-cor)', 'font' => 'inherit', 'font-size' => '12.5px', 'cursor' => 'pointer', 'display' => 'flex', 'align-items' => 'center', 'gap' => '6px']];
        yield 'enviar registro, mouse' => ['.pe-pend-btn:hover', ['background' => 'var(--pe-incompleto-bg)']];
        yield 'selo da data (L183)' => ['.pe-just-data', ['display' => 'inline-block', 'padding' => '3px 8px', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'background' => 'var(--pe-hover-sec)', 'font-size' => '12.5px', 'font-weight' => 'bold']];
        yield 'célula do tipo (L184)' => ['.pe-tab td.pe-just-celtipo', ['white-space' => 'normal', 'min-width' => '170px']];
        yield 'selo do tipo' => ['.pe-just-tipo', ['display' => 'inline-block', 'padding' => '2px 8px', 'border-radius' => '3px', 'background' => 'var(--pe-tipo-bg)', 'color' => 'var(--pe-tipo-cor)', 'font-size' => '11.5px', 'font-weight' => 'bold']];
        yield 'subtítulo do tipo' => ['.pe-just-sub', ['font-size' => '12.5px', 'color' => 'var(--pe-mudo)', 'margin-top' => '3px']];
        yield 'células de texto (L186)' => ['.pe-just-cel', ['color' => 'var(--pe-texto-2)']];
        yield 'ações (L187)' => ['.pe-tab td.pe-just-acoes', ['text-align' => 'right']];
        yield 'cadeado' => ['.pe-just-trava', ['color' => 'var(--pe-trava)', 'font-size' => '16px']];
        yield 'botões da análise' => ['.pe-just-btn', ['height' => '28px', 'padding' => '0 9px', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'font' => 'inherit', 'font-size' => '12.5px', 'cursor' => 'pointer']];
        yield 'Abonar' => ['.pe-just-btn--abonar', ['border-color' => 'var(--pe-ok-forte)', 'color' => 'var(--pe-ok-cor)']];
        yield 'Abonar, mouse' => ['.pe-just-btn--abonar:hover', ['background' => 'var(--pe-ok-bg)']];
        yield 'Recusar' => ['.pe-just-btn--recusar', ['border-color' => 'var(--pe-perigo)', 'color' => 'var(--pe-erro-cor)']];
        yield 'Recusar, mouse' => ['.pe-just-btn--recusar:hover', ['background' => 'var(--pe-erro-bg)']];
        yield 'lixeira' => ['.pe-just-icone', ['width' => '28px', 'height' => '28px', 'margin-left' => '4px', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'color' => 'var(--pe-perigo)', 'cursor' => 'pointer', 'padding' => '0']];
        yield 'lixeira, mouse' => ['.pe-just-icone:hover', ['background' => 'var(--pe-erro-bg)']];
        yield 'fundo da janela (L270)' => ['.pe-janela-fundo', ['position' => 'fixed', 'inset' => '0', 'z-index' => '2147483100', 'background' => 'rgba(11, 53, 80, .55)', 'display' => 'flex', 'align-items' => 'center', 'justify-content' => 'center', 'padding' => '14px', 'overflow-y' => 'auto']];
        yield 'janela fechada' => ['.pe-janela-fundo[hidden]', ['display' => 'none']];
        yield 'janela (L271)' => ['.pe-janela', ['width' => '100%', 'max-width' => '480px', 'border-radius' => '3px', 'box-shadow' => '0 18px 50px rgba(0, 0, 0, .35)', 'overflow' => 'hidden', 'animation' => 'pe-sobe .2s ease both']];
        yield 'barra da janela (L272)' => ['.pe-janela-topo', ['display' => 'flex', 'align-items' => 'center', 'gap' => '9px', 'padding' => '12px 16px', 'background' => 'var(--pe-azul)', 'color' => 'var(--pe-sobre-azul)']];
        yield 'fechar' => ['.pe-janela-fechar', ['width' => '28px', 'height' => '28px', 'border' => 'none', 'border-radius' => '3px', 'background' => 'transparent', 'color' => '#fff', 'font-size' => '17px', 'cursor' => 'pointer']];
        yield 'corpo (L292)' => ['.pe-janela-corpo', ['padding' => '14px 16px', 'display' => 'flex', 'flex-direction' => 'column', 'gap' => '11px']];
        yield 'rótulo (L294)' => ['.pe-janela-rotulo', ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '5px', 'font-size' => '12px', 'font-weight' => 'bold', 'color' => 'var(--pe-texto-3)']];
        yield 'campo (L294)' => ['.pe-janela-campo', ['border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'padding' => '8px 10px', 'font' => 'inherit', 'font-size' => '14px', 'font-weight' => 'normal', 'resize' => 'vertical']];
        yield 'erro (L312)' => ['.pe-janela-erro', ['padding' => '0 16px 10px', 'font-size' => '13px', 'color' => 'var(--pe-perigo)']];
        yield 'rodapé (L313)' => ['.pe-janela-rodape', ['display' => 'flex', 'justify-content' => 'flex-end', 'gap' => '8px', 'padding' => '10px 16px', 'background' => 'var(--pe-rodape-bg)', 'border-top' => '1px solid var(--pe-div)']];
        yield 'Cancelar (L315)' => ['.pe-janela-cancelar', ['height' => '36px', 'padding' => '0 14px', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'color' => 'var(--pe-texto-2)', 'font-size' => '14px']];
        yield 'ação (L316)' => ['.pe-janela-acao', ['height' => '36px', 'padding' => '0 16px', 'border' => 'none', 'border-radius' => '3px', 'background' => 'var(--pe-azul)', 'color' => '#fff', 'font-size' => '14px']];
        yield 'ação, mouse' => ['.pe-janela-acao:hover', ['background' => 'var(--pe-link-hover)']];
    }

    #[TestDox('Tokens novos da E3 são os hex do desenho')]
    public function testTokensDaE3(): void
    {
        $tokens = self::declaracoes(self::semMedia(self::css()), '.pe-page,.pe-folha');

        self::assertSame('#8a6200', $tokens['--pe-num-pend'] ?? null);
        self::assertSame('#bfeaf7', $tokens['--pe-tipo-bg'] ?? null);
        self::assertSame('#0b3550', $tokens['--pe-tipo-cor'] ?? null);
        self::assertSame('#5b6a9a', $tokens['--pe-trava'] ?? null);
        self::assertSame('#f7f9fb', $tokens['--pe-rodape-bg'] ?? null);
        self::assertSame('#d9a300', $tokens['--pe-alerta-btn'] ?? null);
        self::assertSame('#fff3cd', $tokens['--pe-incompleto-bg'] ?? null);
    }

    #[TestDox('Celular (até 760 px): o escritório da pendência some (bjpOculta); a tabela das justificativas não vira cartão')]
    public function testCelular(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', self::css());
        self::assertSame(1, preg_match('/@media \(max-width: 760px\)\s*\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/', $css, $achado));

        self::assertSame(['display' => 'none !important'], self::declaracoes($achado[1], '.pe-oculta'));
        self::assertStringNotContainsString('pe-just-tab', $css, 'a tabela das justificativas não tem regra de celular: rola para o lado');
        self::assertDoesNotMatchRegularExpression('/\.pe-just-lin[^{]*:(?:nth-child|hover)/', $css, 'sem listra nem azul ao passar o mouse (D15)');
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
