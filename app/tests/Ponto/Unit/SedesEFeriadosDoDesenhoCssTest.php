<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Valores do CSS do desenho 16 para as seções de sedes e feriados da aba Configurações (etapa E6, L253 a L258, e o
 * aviso flutuante da L266), regra a regra, em `public/css/ponto-eletronico.css`. O teste funcional confere as classes
 * no HTML; este confere o que cada classe faz. Cor só por token `--pe-*` (o tema escuro troca o token), menos o branco
 * sobre o azul e o aviso, que é igual nos dois temas.
 */
#[Group('ponto')]
#[CoversNothing]
final class SedesEFeriadosDoDesenhoCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/ponto-eletronico.css';
    private const CABECALHO = 'Sedes e feriados (etapa E6, L227 a L260 do desenho)';

    /** @param array<string, string> $esperado */
    #[DataProvider('regras')]
    #[TestDox('A regra $seletor tem os valores do desenho')]
    public function testRegraComOsValoresDoDesenho(string $seletor, array $esperado): void
    {
        $declaracoes = self::declaracoes(self::semComentarios(self::bloco()), $seletor);

        foreach ($esperado as $propriedade => $valor) {
            self::assertSame($valor, $declaracoes[$propriedade] ?? null, sprintf('%s { %s }', $seletor, $propriedade));
        }
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function regras(): iterable
    {
        yield 'seção (L253, L256)' => ['.pe-sf-secao', ['padding' => '12px 16px', 'border-top' => '1px solid var(--pe-div)']];
        yield 'botão de contorno (L253, L254)' => ['.pe-sf-contorno', ['height' => '30px', 'padding' => '0 11px', 'border' => '1px solid var(--pe-azul)', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'color' => 'var(--pe-link)', 'font' => 'inherit', 'font-size' => '12.5px', 'cursor' => 'pointer', 'display' => 'inline-flex', 'align-items' => 'center', 'gap' => '6px', 'white-space' => 'nowrap']];
        yield 'campo (L254, L257)' => ['.pe-sf-campo', ['height' => '34px', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'padding' => '0 8px', 'font' => 'inherit', 'font-size' => '13.5px', 'font-weight' => 'normal', 'color' => 'var(--pe-texto)', 'background' => 'var(--pe-card)', 'box-sizing' => 'border-box', 'width' => '100%']];
        yield 'cabeça das sedes (L253)' => ['.pe-sedes-cabeca', ['display' => 'flex', 'align-items' => 'center', 'gap' => '10px', 'flex-wrap' => 'wrap', 'margin-bottom' => '8px']];
        yield 'título das sedes' => ['.pe-sedes-titulo', ['flex' => '1 1 160px', 'font-size' => '14px']];
        yield 'rolagem da tabela (L254)' => ['.pe-sedes-rolagem', ['overflow-x' => 'auto']];
        yield 'célula do nome' => ['.pe-sede-cel-nome', ['min-width' => '150px']];
        yield 'células da coordenada' => ['.pe-sede-cel-coord', ['min-width' => '110px']];
        yield 'célula do raio' => ['.pe-sede-cel-raio', ['min-width' => '80px']];
        yield 'ações à direita' => ['.pe-tab td.pe-sede-acoes', ['text-align' => 'right']];
        yield 'remover sede' => ['.pe-sede-remover', ['width' => '30px', 'height' => '30px', 'border' => '1px solid var(--pe-borda)', 'border-radius' => '3px', 'background' => 'var(--pe-card)', 'color' => 'var(--pe-perigo)', 'cursor' => 'pointer', 'padding' => '0']];
        yield 'erro da linha (sistema)' => ['.pe-tab td.pe-sede-erro', ['font-size' => '13px', 'color' => 'var(--pe-perigo)', 'white-space' => 'normal']];
        yield 'título dos feriados (L256)' => ['.pe-fer-titulo', ['display' => 'block', 'font-size' => '14px', 'margin-bottom' => '8px']];
        yield 'texto dos feriados' => ['.pe-fer-texto', ['font-size' => '12.5px', 'color' => 'var(--pe-mudo)', 'margin-bottom' => '8px']];
        yield 'grade do formulário (L257)' => ['.pe-fer-form', ['display' => 'grid', 'grid-template-columns' => 'repeat(auto-fit, minmax(140px, 1fr))', 'gap' => '8px', 'align-items' => 'end']];
        yield 'rótulo' => ['.pe-fer-rotulo', ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '5px', 'font-size' => '12px', 'font-weight' => 'bold', 'color' => 'var(--pe-texto-3)']];
        yield '"Repete todo ano" (molde da L245)' => ['.pe-fer-repete', ['display' => 'flex', 'align-items' => 'center', 'gap' => '8px', 'font-size' => '13px', 'color' => 'var(--pe-texto-2)', 'align-self' => 'end', 'min-height' => '34px']];
        yield 'caixa de seleção' => ['.pe-fer-repete > input', ['width' => '16px', 'height' => '16px']];
        yield 'Adicionar' => ['.pe-fer-adicionar', ['height' => '34px', 'border' => 'none', 'border-radius' => '3px', 'background' => 'var(--pe-azul)', 'color' => '#fff', 'font' => 'inherit', 'font-size' => '13px', 'cursor' => 'pointer']];
        yield 'linha da lista (L258)' => ['.pe-fer-lin', ['display' => 'flex', 'align-items' => 'center', 'gap' => '10px', 'padding' => '7px 0', 'border-bottom' => '1px solid var(--pe-div)', 'font-size' => '13px']];
        yield 'nome' => ['.pe-fer-nome', ['flex' => '1']];
        yield 'abrangência' => ['.pe-fer-abrangencia', ['color' => 'var(--pe-mudo)']];
        yield 'remover feriado' => ['.pe-fer-remover', ['width' => '26px', 'height' => '26px', 'border' => 'none', 'background' => 'transparent', 'color' => 'var(--pe-perigo)', 'cursor' => 'pointer']];
        yield 'aviso flutuante (L266)' => ['.pe-page .pe-sf-aviso', ['position' => 'fixed', 'left' => '50%', 'bottom' => '22px', 'transform' => 'translateX(-50%)', 'z-index' => '2147483000', 'max-width' => 'calc(100vw - 28px)', 'background' => '#1e7b45', 'color' => '#fff', 'padding' => '11px 16px', 'border-radius' => '3px', 'box-shadow' => '0 8px 24px rgba(18, 36, 47, .25)', 'font-size' => '14px']];
        yield 'aviso de erro' => ['.pe-page .pe-sf-aviso--erro', ['background' => '#a3232b']];
    }

    #[TestDox('O bloco novo tem o cabeçalho da etapa, só cores por token e nenhum hover (o desenho não tem)')]
    public function testBlocoDaEtapa(): void
    {
        $bloco = self::semComentarios(self::bloco());

        self::assertStringNotContainsString(':hover', $bloco);
        preg_match_all('/#[0-9a-fA-F]{3,6}\b/', (string) preg_replace('/\.pe-page \.pe-sf-aviso[^{]*\{[^}]*\}/', '', $bloco), $hex);
        self::assertSame(['#fff'], array_values(array_unique($hex[0])), 'fora do aviso, só o branco sobre o azul do "Adicionar"');
        self::assertDoesNotMatchRegularExpression('/(^|\})\s*(?!\.pe-|@keyframes|from|to)[^{}\s][^{}]*\{/', $bloco, 'todo seletor com o prefixo da tela');
    }

    private static function bloco(): string
    {
        $css = (string) file_get_contents(self::CSS);
        $inicio = strpos($css, self::CABECALHO);
        self::assertIsInt($inicio, 'o bloco da E6 tem o cabeçalho combinado');

        return substr($css, (int) strrpos(substr($css, 0, $inicio), '/*'));
    }

    private static function semComentarios(string $css): string
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
