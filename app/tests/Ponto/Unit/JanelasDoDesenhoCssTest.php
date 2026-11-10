<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Valores do CSS do desenho 16 para os campos das janelas da E4 ("Justificar falta", "Registro de esquecimento" e
 * "Editar justificativa") e para o motivo na aba, em `public/css/ponto-eletronico.css`. A moldura é a da E3
 * (AbasDoDesenhoCssTest); o funcional (JanelasDoPontoTelaTest) confere as classes no HTML.
 */
#[Group('ponto')]
#[CoversNothing]
final class JanelasDoDesenhoCssTest extends TestCase
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
        yield 'Horário e Data (L277, L281)' => ['.pe-janela-entrada', ['height' => '36px', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'padding' => '0 10px', 'font' => 'inherit', 'font-size' => '14px', 'font-weight' => 'normal']];
        yield 'seleção (L290, a da janela de assinar a folha)' => ['.pe-janela-select', ['height' => '34px', 'width' => '100%', 'box-sizing' => 'border-box', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'padding' => '0 8px', 'font' => 'inherit', 'font-size' => '13.5px', 'font-weight' => 'normal']];
        yield 'atestado (L283)' => ['.pe-janela-arquivo', ['font' => 'inherit', 'font-size' => '13px', 'font-weight' => 'normal']];
        yield 'caixa de marcar (L308, "Cadastro facial")' => ['.pe-janela-marca', ['display' => 'flex', 'align-items' => 'flex-start', 'gap' => '8px', 'padding' => '9px 11px', 'background' => 'var(--pe-rodape-bg)', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px']];
        yield 'marca da caixa (L308)' => ['.pe-janela-marca > input', ['width' => '16px', 'height' => '16px', 'margin' => '2px 0 0']];
        yield 'dois campos lado a lado' => ['.pe-janela-par', ['display' => 'grid', 'grid-template-columns' => 'repeat(2, minmax(0, 1fr))', 'gap' => '11px']];
        yield 'aviso amarelo (L118)' => ['.pe-janela-aviso', ['border' => '1px solid var(--pe-alerta-bd)', 'border-radius' => '3px', 'background' => 'var(--pe-alerta-bg)', 'color' => 'var(--pe-alerta-cor)']];
        yield 'grade do calendário' => ['.pe-cal-grade', ['display' => 'grid', 'grid-template-columns' => 'repeat(7, minmax(0, 1fr))', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px']];
        yield 'dia do calendário' => ['.pe-cal-dia', ['height' => '30px', 'border-radius' => '3px', 'font' => 'inherit', 'cursor' => 'pointer']];
        yield 'dia escolhido: o azul do desenho' => ['.pe-cal-dia[aria-pressed="true"],.pe-cal-dia[aria-pressed="true"]:hover', ['background' => 'var(--pe-azul)', 'color' => '#fff', 'font-weight' => 'bold']];
        yield 'motivo na aba (L184)' => ['.pe-just-mot', ['font-size' => '12px', 'color' => 'var(--pe-mudo)']];
        // Desvio necessário (E4): a janela da falta, com o calendário, passa da altura da tela. Centraliza quando cabe e
        // rola quando não cabe; com `margin: 0` o topo sumia sem rolagem (1366x768 e celular).
        yield 'janela alta rola (desvio necessário)' => ['.pe-janela', ['margin' => 'auto']];
    }

    #[TestDox('Bloco escondido some mesmo com display próprio (o par em grade, a caixa em flex)')]
    public function testEscondidoSome(): void
    {
        self::assertSame(['display' => 'none !important'], self::declaracoes(self::semMedia(self::css()), '.pe-janela [hidden]'));
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
