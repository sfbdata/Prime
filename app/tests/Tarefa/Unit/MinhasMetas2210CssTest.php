<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Minhas Metas com os valores dos estilos inline do dc (desenho 04 de 22/10): moldura (L49), cabeçalho (L50-55),
 * divisor (L61), indicadores (L64), barra (L66-78), metas do grupo (L86-99), vazio (L107), trilho (L111-127), janela
 * "Configurar caixa" (L133) e o aviso (L132). O fundo e o respiro ficam presos em .mm-body (decisão do dono de 22/10:
 * nada muda no resto do sistema, nem na Equipe, que usa o mesmo .mm-page).
 */
#[CoversNothing]
#[Group('tarefa')]
final class MinhasMetas2210CssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/minhas-metas.css';

    private static function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS));
    }

    private static function regra(string $seletor): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/m', self::css(), $m), "regra {$seletor} não encontrada");

        return $m[1];
    }

    #[TestDox('Tokens do tema claro com o valor do desenho; o tema escuro troca cada um')]
    public function testTokens(): void
    {
        $tokens = ['--mm-pagina' => '#f3f6f8', '--mm-meta-borda' => '#e3eaef', '--mm-kpi-desc' => '#7a8e9b', '--mm-subtitulo' => '#5b7383',
            '--mm-grupo-borda' => '#dfe7ed', '--mm-grupo-cabeca-bg' => '#f5f8fa', '--mm-grupo-cabeca-borda' => '#e6edf2', '--mm-controle-borda' => '#e2e9ee',
            '--mm-pilula-atrasada' => '#a3232b', '--mm-pilula-atrasada-bg' => '#fbe7e7', '--mm-pilula-breve' => '#8a5a12', '--mm-pilula-breve-bg' => '#fbf1dc',
            '--mm-pilula-revisao' => '#0a6fa0', '--mm-pilula-revisao-bg' => '#e5f1f7', '--mm-pilula-prazo' => '#1e7b45', '--mm-pilula-prazo-bg' => '#e6f4ec'];
        $claro  = self::regra('.mm-page');
        $escuro = self::regra('[data-bs-theme="dark"] .mm-page');
        foreach ($tokens as $nome => $valor) {
            self::assertMatchesRegularExpression('/' . preg_quote($nome, '/') . ':\s*' . preg_quote($valor, '/') . ';/', $claro, $nome);
            self::assertMatchesRegularExpression('/' . preg_quote($nome, '/') . ':/', $escuro, $nome . ' no escuro');
        }
    }

    #[TestDox('O fundo e o respiro da moldura do sistema só mudam sob .mm-body; nenhum seletor solto de .app-main ou .app-content')]
    public function testFundoSoNestaPagina(): void
    {
        self::assertStringContainsString('background: #f3f6f8;', self::regra('.mm-body .app-main'));
        $soltos = preg_match_all('/^(?!\.mm-body |\[data-bs-theme="dark"\] \.mm-body |body\.layout-fixed \.app-main:has\(\.mm-page\))[^\n{]*\.app-(main|content)/m', self::css());
        self::assertSame(0, $soltos, 'todo seletor da moldura do sistema começa por .mm-body');
        self::assertSame(0, preg_match('/^(body|html|\.app-main)\s*\{/m', self::css()));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function regras(): iterable
    {
        yield from self::regrasDoTopoEDaLista();
        yield from self::regrasDoTrilhoDaJanelaEDoAviso();
    }

    /** @return iterable<string, array{string, list<string>}> */
    private static function regrasDoTopoEDaLista(): iterable
    {
        yield 'moldura (L49)' => ['.mm-body .mm-page', ['padding: 24px 22px 32px;', 'flex-direction: column;', 'gap: 16px;', 'max-width: 1600px;', 'margin: 0 auto;']];
        yield 'cabeçalho (L50)' => ['.mm-topo', ['display: flex;', 'align-items: flex-end;', 'gap: 16px;']];
        yield 'coluna do título (L51)' => ['.mm-topo > .mm-cabecalho', ['flex: 1;', 'min-width: 260px;', 'flex-direction: column;', 'gap: 8px;']];
        yield 'traço azul do H1 (L53)' => ['.mm-page .mm-cabecalho .mm-titulo::after', ['background: var(--mm-num);']];
        yield '"Atualizado às" (L54)' => ['.mm-atualizado', ['font-size: 14px;', 'color: var(--mm-subtitulo);', 'gap: 7px;']];
        yield 'filtro e resultado' => ['.mm-filtro', ['flex-direction: column;', 'gap: 16px;']];
        yield 'divisor SUAS METAS (L61)' => ['.mm-divisor-suas', ['margin: 2px 0 -6px;', 'gap: 10px;']];
        yield 'grade dos indicadores (dc 31/10 L82)' => ['.mm-kpis', ['grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));', 'gap: 16px;']];
        yield 'indicador (dc 31/10 L83)' => ['.mm-kpi', ['position: relative;', 'overflow: hidden;', 'flex-direction: column;', 'border: 1px solid var(--mm-grupo-borda);', 'border-bottom: 3px solid var(--mm-kpi-cor);', 'border-radius: 4px;', 'padding: 20px 22px 22px;', 'min-height: 160px;', 'transition: box-shadow .2s, transform .2s;']];
        yield 'hover do indicador (dc 31/10 L83)' => ['.mm-kpi:hover', ['box-shadow: 0 8px 22px rgba(18,36,47,.08);', 'transform: translateY(-2px);']];
        yield 'cor de Atrasadas (VIS)' => ['.mm-kpi.tom-danger', ['--mm-kpi-cor: #a3232b;', '--mm-kpi-anel: rgba(163,35,43,.14);', '--mm-kpi-icone-bg: #fbe7e7;']];
        yield 'cor de Vencem em 7 dias (VIS)' => ['.mm-kpi.tom-warn', ['--mm-kpi-cor: #c08a12;', '--mm-kpi-anel: rgba(192,138,18,.16);', '--mm-kpi-icone-bg: #fbf1dc;']];
        yield 'cor de Aguardando revisão (VIS)' => ['.mm-kpi.tom-accent', ['--mm-kpi-cor: #0a6fa0;', '--mm-kpi-anel: rgba(10,111,160,.14);', '--mm-kpi-icone-bg: #e5f1f7;']];
        yield 'cor de Sem prazo (VIS)' => ['.mm-kpi.tom-cinza', ['--mm-kpi-cor: #7d909d;', '--mm-kpi-anel: rgba(125,144,157,.16);', '--mm-kpi-icone-bg: #eef2f5;']];
        yield 'anel tracejado' => ['.mm-kpi > .mm-kpi-anel', ['right: -34px;', 'top: -34px;', 'width: 140px;', 'height: 140px;', 'border-radius: 50%;', 'border: 1px dashed var(--mm-kpi-anel);', 'pointer-events: none;']];
        yield 'ícone do indicador' => ['.mm-kpi > .mm-kpi-icone', ['right: 22px;', 'top: 20px;', 'width: 46px;', 'height: 46px;', 'border-radius: 10px;', 'background: var(--mm-kpi-icone-bg);', 'color: var(--mm-kpi-cor);', 'font-size: 20px;', 'box-shadow: 0 2px 6px var(--mm-kpi-anel);']];
        yield 'rótulo do indicador' => ['.mm-kpi-rotulo', ['font-size: 12px;', 'font-weight: 700;', 'letter-spacing: 2px;', 'color: var(--mm-kpi-rotulo);', 'padding-right: 60px;']];
        yield 'traço do rótulo' => ['.mm-kpi-traco', ['width: 22px;', 'height: 2px;', 'background: var(--mm-kpi-cor);', 'margin: 6px 0 10px;']];
        yield 'número do indicador' => ['.mm-kpi-valor', ['font-size: 42px;', 'line-height: 1;', 'font-variant-numeric: tabular-nums;']];
        yield 'descrição do indicador' => ['.mm-kpi-sub', ['font-size: 13px;', 'color: var(--mm-subtitulo);', 'margin-top: auto;', 'padding-top: 14px;']];
        yield 'cartão da barra (L66)' => ['.mm-barra-cartao', ['border: 1px solid var(--mm-grupo-borda);', 'border-radius: 4px;', 'padding: 14px 18px;', 'gap: 14px;']];
        yield 'separador da barra (L74)' => ['.mm-barra-sep', ['width: 1px;', 'height: 28px;', 'background: var(--mm-controle-borda);']];
        yield 'aba inativa sem hover (L71)' => ['.mm-aba:hover', ['color: var(--mm-aba-texto);']];
        yield 'faixa das metas do grupo (L86)' => ['.mm-grupo-itens', ['flex-direction: column;', 'padding: 6px 6px 0;', 'background: var(--mm-pagina);']];
        yield 'meta (L87)' => ['.mm-meta', ['align-items: center;', 'gap: 18px;', 'padding: 18px 22px;', 'border-bottom: 1px solid var(--mm-meta-borda);', 'margin: 0 0 6px;', 'border-radius: 3px;', 'background: var(--mm-card);']];
        yield 'corpo da meta (L88)' => ['.mm-meta-corpo', ['flex-direction: column;', 'gap: 8px;']];
        yield 'segunda linha (L90)' => ['.mm-meta-linha2', ['gap: 6px 18px;', 'flex-wrap: wrap;']];
        yield 'lado (L97)' => ['.mm-meta-lado', ['gap: 6px;', 'min-width: 120px;']];
        yield 'ATRASADA' => ['.mm-meta-lado > .mm-pilula.tom-danger', ['color: var(--mm-pilula-atrasada);', 'background: var(--mm-pilula-atrasada-bg);']];
        yield 'VENCE EM BREVE' => ['.mm-meta-lado > .mm-pilula.tom-warn', ['color: var(--mm-pilula-breve);', 'background: var(--mm-pilula-breve-bg);']];
        yield 'EM REVISÃO' => ['.mm-meta-lado > .mm-pilula.tom-accent', ['color: var(--mm-pilula-revisao);', 'background: var(--mm-pilula-revisao-bg);']];
        yield 'NO PRAZO e CONCLUÍDA' => ['.mm-meta-lado > .mm-pilula.tom-ok', ['color: var(--mm-pilula-prazo);', 'background: var(--mm-pilula-prazo-bg);']];
        yield 'vazio (L107)' => ['.mm-vazio-caixa', ['padding: 40px;', 'text-align: center;', 'font-size: 16px;']];
    }

    /** @return iterable<string, array{string, list<string>}> */
    private static function regrasDoTrilhoDaJanelaEDoAviso(): iterable
    {
        yield 'trilho (L111)' => ['.mm-trilho', ['flex-direction: column;', 'gap: 16px;', 'position: sticky;']];
        yield 'cartões do trilho (L112, L119)' => ['.mm-trilho > .mm-cartao', ['border: 1px solid var(--mm-grupo-borda);', 'box-shadow: 0 2px 10px rgba(18,36,47,.05);']];
        yield '"últimos 30 dias" (L113)' => ['.mm-andamento-periodo', ['font-size: 12px;', 'color: var(--mm-subtitulo);']];
        yield '"X de Y" (L114)' => ['.mm-andamento-numero', ['margin-top: 12px;', 'gap: 8px;']];
        yield '"concluídas" (L114)' => ['.mm-andamento-numero > .mm-andamento-periodo', ['font-size: 13px;']];
        yield 'barra do andamento (L115)' => ['.mm-progresso-barra', ['height: 7px;', 'background: var(--mm-grupo-cabeca-borda);', 'margin-top: 10px;']];
        yield 'legenda (L116)' => ['.mm-fatia', ['font-size: 12.5px;', 'color: var(--mm-grupo-texto);']];
        yield 'cabeçalho do Precisa de atenção (L120)' => ['.mm-trilho .mm-cartao-cabeca', ['padding: 13px 16px;', 'border-bottom-color: var(--mm-grupo-cabeca-borda);']];
        yield 'ícone do item de atenção (L123)' => ['.mm-atencao-icone', ['font-size: 16px;', 'margin-top: 2px;']];
        yield 'chip da etapa sem hover (L60)' => ['.mm-esteira-chip:hover', ['background: #e5f1f7;']];
        yield 'fundo da janela (L133)' => ['.mm-cx-modal > .mm-cx-fundo', ['position: fixed;', 'z-index: 9100;', 'background: rgba(18, 36, 47, .38);']];
        yield 'centro da janela (L133)' => ['.mm-cx-modal > .mm-cx-centro', ['z-index: 9101;', 'padding: 12px;', 'pointer-events: none;']];
        yield 'caixa da janela (L133)' => ['.mm-cx-centro > .mm-cx-caixa', ['width: min(460px, 100%);', 'border-radius: 4px;', 'box-shadow: 0 20px 50px rgba(18, 36, 47, .28);']];
        yield 'pilha dos avisos (L132)' => ['.mm-body .app-content-header > .container-fluid', ['position: fixed;', 'left: 50%;', 'bottom: 22px;', 'flex-direction: column;', 'gap: 8px;']];
        yield 'aviso do sistema (L132)' => ['.mm-body .app-content-header .alert', ['position: relative;', 'font-size: 13.5px;', 'padding: 10px 16px;', 'border-radius: 3px;']];
        yield 'aviso de sucesso (L132)' => ['.mm-body .app-content-header .alert-success', ['background: #12242f;', 'color: #fff;']];
        yield 'ícone do aviso de sucesso (L132)' => ['.mm-body .app-content-header .alert-success > .bi', ['color: #7fe0a8;', 'margin-right: 6px;']];
        yield 'aviso de erro (paleta do dc)' => ['.mm-body .app-content-header .alert:not(.alert-success)', ['background: #fbe7e7;', 'color: #a3232b;']];
        yield 'ícone do aviso de erro' => ['.mm-body .app-content-header .alert:not(.alert-success) > .bi', ['color: #a3232b;']];
    }

    /** @param list<string> $declaracoes */
    #[DataProvider('regras')]
    #[TestDox('$seletor tem os valores do desenho')]
    public function testValoresDoDesenho(string $seletor, array $declaracoes): void
    {
        $regra = self::regra($seletor);
        foreach ($declaracoes as $d) {
            self::assertStringContainsString($d, $regra, $seletor);
        }
    }

    #[TestDox('O X do aviso só some no de sucesso; o de erro fica até a pessoa fechar')]
    public function testSoOSucessoEscondeOX(): void
    {
        self::assertStringContainsString('display: none;', self::regra('.mm-body .app-content-header .alert-success > .btn-close'));
        self::assertSame(0, preg_match('/^\.mm-body \.app-content-header \.alert > \.btn-close/m', self::css()), 'o X não pode sumir de todo aviso');
    }

    #[TestDox('O que o desenho não tem saiu da folha: alternância cartões/lista, ⋮ do cartão, Pessoas, halo, faixa e estado ativo do indicador, risco da concluída, borda sumida no último item de atenção')]
    public function testSemOQueODesenhoNaoTem(): void
    {
        foreach (['.mm-modos', '.mm-modo', '.mm-menu', '.mm-pessoa', '.mm-kpi-halo', '.mm-kpi-faixa', '.mm-kpi.is-ativo', '.mm-meta-acoes', '.mm-atencao-vazio', 'line-through', '.mm-atencao-item:last-of-type', '.mm-sem-trilho', 'modal-dialog'] as $saiu) {
            self::assertStringNotContainsString($saiu, self::css(), $saiu);
        }
    }
}
