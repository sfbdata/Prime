<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Página da meta com os valores dos estilos inline do dc (desenho 04B de 22/10): moldura e colunas (L34, L47-48,
 * L76), cabeçalho (L37-39), cartão (L49-54), faixa DESPACHO (L56-58), linha do tempo (L70-71), Acompanhar (L83-84),
 * DETALHES (L85), janelas (o componente da devolução, L106) e o aviso (L176). O fundo e o respiro ficam presos em
 * .meta-body (decisão do dono de 22/10: nada muda no resto do sistema).
 */
#[CoversNothing]
#[Group('tarefa')]
final class MetaDetalheCssTest extends TestCase
{
    private const DETALHE  = __DIR__ . '/../../../public/css/meta-detalhe.css';
    private const DESPACHO = __DIR__ . '/../../../public/css/meta-despacho.css';

    private static function regra(string $arquivo, string $seletor): string
    {
        $css = (string) file_get_contents($arquivo);
        self::assertSame(1, preg_match('/^' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/m', $css, $m), "regra {$seletor} não encontrada");

        return $m[1];
    }

    #[TestDox('Tokens do tema claro com o valor do desenho; o tema escuro troca')]
    public function testTokens(): void
    {
        $raiz = self::regra(self::DETALHE, ':root');
        foreach (['--meta-pagina: #f3f6f8;', '--meta-borda: #dfe7ed;', '--meta-divisa-forte: #e6edf2;', '--meta-campo-borda: #d3dde4;', '--meta-demanda-texto: #243845;', '--meta-demanda-fundo: #f9fbfc;', '--meta-hover-item: #f5f8fa;', '--meta-texto: #34505f;', '--meta-mudo: #7a8e9b;', '--meta-azul: #0a6fa0;'] as $d) {
            self::assertStringContainsString($d, $raiz);
        }
        self::assertStringContainsString('--meta-pagina:', self::regra(self::DETALHE, '[data-bs-theme="dark"]'));
        self::assertStringContainsString('--despacho-chip-borda: #dfe7ed;', self::regra(self::DESPACHO, ':root'));
    }

    #[TestDox('O fundo da página e o respiro da moldura do sistema só mudam sob .meta-body; nenhum seletor solto de .app-main')]
    public function testFundoSoNestaPagina(): void
    {
        self::assertStringContainsString('background: var(--meta-pagina);', self::regra(self::DETALHE, '.meta-body .app-main'));
        $css = (string) file_get_contents(self::DETALHE);
        self::assertSame(0, preg_match('/^(?!\.meta-body )[^\n{]*\.app-(main|content)/m', $css), 'todo seletor da moldura do sistema começa por .meta-body');
        self::assertSame(0, preg_match('/^(body|html|\.app-main)\b/m', $css));
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function regras(): iterable
    {
        yield 'moldura (L34)' => [self::DETALHE, '.meta-moldura', ['max-width: 1280px;', 'margin: 0 auto;', 'padding: 18px 18px 40px;', 'flex-direction: column;', 'gap: 14px;']];
        yield 'título h1 (L37)' => [self::DETALHE, '.meta-titulo', ['margin: 0;', 'font-size: 28px;', 'font-weight: normal;', 'gap: 10px;']];
        yield 'link da trilha (L22, L39)' => [self::DETALHE, '.meta-trilha > a', ['color: var(--meta-azul);', 'text-decoration: none;']];
        yield 'hover da trilha (L22)' => [self::DETALHE, '.meta-trilha > a:hover', ['color: #0b5f86;', 'text-decoration: underline;']];
        yield 'colunas (L47)' => [self::DETALHE, '.meta-colunas', ['display: flex;', 'flex-wrap: wrap;', 'gap: 14px;', 'align-items: flex-start;']];
        yield 'cartão da meta (L48)' => [self::DETALHE, '.meta-colunas > .meta-principal', ['flex: 999 1 520px;', 'border: 1px solid var(--meta-borda);', 'border-radius: 3px;', 'overflow: hidden;', 'min-width: 0;']];
        yield 'lateral (L76)' => [self::DETALHE, '.meta-colunas > .meta-lateral', ['flex: 1 1 300px;', 'min-width: 0;', 'flex-direction: column;', 'gap: 10px;']];
        yield 'barra do título (L49)' => [self::DETALHE, '.meta-principal > .meta-principal-titulo', ['padding: 14px 18px;', 'border-bottom: 1px solid var(--meta-divisa-forte);', 'gap: 10px;']];
        yield 'título (L49)' => [self::DETALHE, '.meta-principal-titulo > b', ['flex: 1;', 'font-size: 16px;', 'letter-spacing: .3px;', 'text-transform: uppercase;']];
        yield 'faixa aguardando (L50)' => [self::DETALHE, '.meta-aguardando', ['margin: 14px 18px 0;', 'padding: 12px 14px;']];
        yield 'demanda (L51)' => [self::DETALHE, '.meta-demanda', ['padding: 14px 18px;', 'gap: 8px;']];
        yield 'texto da demanda (L54)' => [self::DETALHE, '.meta-demanda-texto', ['font-size: 14px;', 'line-height: 1.6;', 'color: var(--meta-demanda-texto);', 'background: var(--meta-demanda-fundo);', 'padding: 12px 14px;']];
        yield 'histórico no mesmo cartão (L56)' => [self::DETALHE, '.meta-principal > .meta-historico', ['border-top: 1px solid var(--meta-divisa-forte);']];
        yield 'acompanhar (L83)' => [self::DETALHE, '.meta-lateral > .meta-acompanhar', ['height: 36px;', 'border: 1px solid var(--meta-campo-borda);', 'border-radius: 3px;', 'background: var(--meta-fundo);', 'color: var(--meta-texto);', 'font-size: 13.5px;', 'gap: 7px;']];
        yield 'acompanhando (L83)' => [self::DETALHE, '.meta-lateral > .meta-acompanhar.is-ativo', ['color: var(--meta-azul);']];
        yield 'hover do acompanhar (L83)' => [self::DETALHE, '.meta-lateral > .meta-acompanhar:hover', ['border-color: var(--meta-azul);']];
        yield 'só você vê (L84)' => [self::DETALHE, '.meta-lateral > .meta-so-voce', ['font-size: 11.5px;', 'color: var(--meta-mudo);', 'padding: 0 2px;', 'margin-top: -4px;']];
        yield 'hover do prazo em todo estado (L85)' => [self::DETALHE, '.meta-detalhes-prazo[role="button"]:hover', ['filter: brightness(.96);']];
        yield 'hover dos responsáveis (L85)' => [self::DETALHE, '.meta-detalhes-responsaveis[role="button"]:hover', ['background: var(--meta-hover-item);']];
        yield 'fundo da janela (L106)' => [self::DETALHE, '.md-modal > .md-modal-fundo', ['position: fixed;', 'background: rgba(18, 36, 47, .35);']];
        yield 'caixa da janela (L106)' => [self::DETALHE, '.md-modal-centro > .md-modal-caixa', ['width: min(480px, 100%);', 'border-radius: 3px;', 'box-shadow: 0 20px 50px rgba(18, 36, 47, .25);']];
        yield 'cabeçalho da janela (L106)' => [self::DETALHE, '.md-modal-caixa > .md-modal-cabeca', ['background: #0a6fa0;', 'color: #fff;', 'padding: 12px 16px;', 'gap: 8px;']];
        yield 'X da janela (L106)' => [self::DETALHE, '.md-modal-cabeca > .md-modal-x', ['width: 28px;', 'height: 28px;', 'background: rgba(255, 255, 255, .15);']];
        yield 'rodapé da janela (L106)' => [self::DETALHE, '.md-modal-caixa > .md-modal-pe', ['padding: 10px 16px;', 'border-top: 1px solid var(--meta-divisa);', 'justify-content: flex-end;', 'gap: 8px;']];
        yield 'confirmar da janela (L106)' => [self::DETALHE, '.md-modal-pe > .md-confirmar', ['height: 34px;', 'border-radius: 3px;', 'background: #0a6fa0;', 'font-size: 13.5px;', 'font-weight: bold;']];
        yield 'aviso do sistema (L176)' => [self::DETALHE, '.meta-body .app-content-header .alert', ['position: fixed;', 'left: 50%;', 'bottom: 22px;', 'background: #12242f;', 'font-size: 13.5px;', 'padding: 10px 16px;', 'border-radius: 3px;']];
        yield 'faixa DESPACHO (L57)' => [self::DESPACHO, '.despacho-cabeca-bloco', ['padding: 12px 18px;', 'background: var(--despacho-fundo-cabeca);', 'border-bottom: 1px solid var(--despacho-divisa);', 'gap: 8px;']];
        yield 'formulário (L58)' => [self::DESPACHO, '.despacho-bloco', ['padding: 12px 18px;']];
        yield 'campo do despacho (L60)' => [self::DESPACHO, '.despacho-texto-campo', ['border: 1px solid var(--despacho-borda);', 'padding: 10px 11px;', 'color: var(--despacho-titulo);', 'font-size: 14px;', 'line-height: 1.5;']];
        yield 'lista (L70)' => [self::DESPACHO, '.despacho-lista', ['padding: 0 18px 14px;']];
        yield 'marcador (L71)' => [self::DESPACHO, '.despacho-marca', ['position: relative;', 'width: 30px;', 'flex-direction: column;', 'align-items: center;']];
        yield 'traço vertical (L71)' => [self::DESPACHO, '.despacho-marca > .despacho-traco', ['flex: 1;', 'width: 2px;', 'background: var(--despacho-chip-borda);', 'margin-top: 2px;']];
        yield 'ícone acima do traço (L71)' => [self::DESPACHO, '.despacho-icone', ['z-index: 1;', 'width: 30px;', 'height: 30px;']];
    }

    /** @param list<string> $declaracoes */
    #[DataProvider('regras')]
    #[TestDox('$seletor tem os valores do desenho')]
    public function testValoresDoDesenho(string $arquivo, string $seletor, array $declaracoes): void
    {
        $regra = self::regra($arquivo, $seletor);
        foreach ($declaracoes as $d) {
            self::assertStringContainsString($d, $regra, $seletor);
        }
    }

    #[TestDox('Sem o brilho de foco do Bootstrap no campo do despacho: o campo não usa .form-control')]
    public function testCampoSemFormControl(): void
    {
        $form = (string) file_get_contents(__DIR__ . '/../../../templates/tarefa/_despacho_formulario.html.twig');
        self::assertStringContainsString('class="despacho-texto-campo"', $form);
        self::assertStringNotContainsString('form-control', $form);
        self::assertStringNotContainsString('card-body', $form);
    }
}
