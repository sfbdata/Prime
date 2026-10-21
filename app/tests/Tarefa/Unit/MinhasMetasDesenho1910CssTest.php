<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Minhas Metas contra o desenho de 19/10 (`docs/design/redesign-19-10-26/04 - Minhas Metas 1.0.dc.html`).
 * Mapa por ID: `docs/specs/fidelidade-designer-1310-checklist-minhas-metas.md`.
 *
 * - LIS-01/TRI-01: grade lista + trilho de 340px, gap 16 (o sticky segue em 118px).
 * - LIS-02/03/04: cada grupo é um cartão; cabeçalho 15/20, #f5f8fa, só a borda de baixo, 12px/700/1.4px;
 *   contador 11px/700 em #e2e9ee, raio 3; ponto 7px.
 * - LIS-05/06: Atrasadas #a3232b e Vencem em 7 dias #c08a12 com token próprio do grupo; os tokens
 *   `--mm-danger-dot`/`--mm-warn-dot` (traço do H1 e outras peças) não mudam.
 * - LIS-15/18/33, LIS-34, TRI-09, MENU-01, CAB-01/04, FIL-03.
 *
 * Teste de folha não vê cascata nem posição: o que a tela mostra é o smoke do dono.
 */
#[CoversNothing]
final class MinhasMetasDesenho1910CssTest extends TestCase
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

    private function token(string $nome): string
    {
        self::assertSame(1, preg_match('/\.mm-page \{[^}]*' . preg_quote($nome, '/') . ':\s*([^;]+);/', $this->css(), $m), 'token ' . $nome . ' no tema claro');

        return trim($m[1]);
    }

    /** @param list<string> $declaracoes */
    private function temTudo(string $seletor, array $declaracoes): void
    {
        $regra = $this->regras($seletor);
        foreach ($declaracoes as $d) {
            [$prop, $valor] = array_map('trim', explode(':', $d, 2));
            self::assertMatchesRegularExpression(
                '/(^|[\s;{])' . preg_quote($prop, '/') . ':\s*' . preg_quote($valor, '/') . '\s*;/',
                $regra,
                $seletor . ' deveria ter ' . $d,
            );
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function tokens(): iterable
    {
        yield 'cartão do grupo: borda' => ['--mm-grupo-borda', '#dfe7ed'];
        yield 'cabeçalho do grupo: fundo' => ['--mm-grupo-cabeca-bg', '#f5f8fa'];
        yield 'cabeçalho do grupo: borda de baixo' => ['--mm-grupo-cabeca-borda', '#e6edf2'];
        yield 'cabeçalho do grupo: texto' => ['--mm-grupo-texto', '#34505f'];
        yield 'contador do grupo: fundo' => ['--mm-grupo-contagem-bg', '#e2e9ee'];
        yield 'grupo Atrasadas' => ['--mm-grupo-atrasadas-cor', '#a3232b'];
        yield 'grupo Vencem em 7 dias' => ['--mm-grupo-proximas-cor', '#c08a12'];
        yield 'linha: pasta e criador' => ['--mm-linha-info', '#4a6373'];
        yield 'linha: "criada"' => ['--mm-criada-texto', '#7d909d'];
        yield 'vazio: borda' => ['--mm-vazio-borda', '#d6e0e7'];
        yield 'vazio: texto' => ['--mm-vazio-texto', '#5b7383'];
        yield 'atenção: hover do item' => ['--mm-atencao-hover', '#eef6fb'];
        yield 'marcador desligado' => ['--mm-marcador', '#98a9b4'];
        yield 'marcador ligado' => ['--mm-marcador-ligado', '#0a6fa0'];
        yield 'subtítulo' => ['--mm-subtitulo', '#5b7383'];
        yield 'busca e selects: borda' => ['--mm-controle-borda', '#e2e9ee'];
        yield 'busca: lupa' => ['--mm-busca-icone', '#5b7383'];
        yield 'nº da pasta' => ['--mm-num', '#0a6fa0'];
        // As cores de grupo têm token próprio: estes dois seguem como eram (traço do H1, pílulas, legenda).
        yield 'danger-dot intocado' => ['--mm-danger-dot', '#c0392f'];
        yield 'warn-dot intocado' => ['--mm-warn-dot', '#c8952a'];
    }

    #[DataProvider('tokens')]
    #[TestDox('token do desenho de 19/10: $nome = $valor')]
    public function testTokens(string $nome, string $valor): void
    {
        self::assertSame($valor, $this->token($nome));
    }

    #[TestDox('LIS-01/TRI-01: grade minmax(0,1fr) 340px, gap 16; o trilho segue sticky a 118px')]
    public function testGrade(): void
    {
        $this->temTudo('.mm-arranjo', ['grid-template-columns: minmax(0, 1fr) 340px', 'gap: 16px']);
        self::assertMatchesRegularExpression('/position:\s*sticky;[^}]*top:\s*calc\(var\(--header-height\) \+ var\(--subnav-height\) \+ 16px\)/', $this->regras('.mm-trilho'));
    }

    #[TestDox('LIS-02: grupos empilhados com 12px; cada grupo é um cartão #fff, borda, raio 4, sombra e recorte')]
    public function testGrupoEmCartao(): void
    {
        $this->temTudo('.mm-coluna-lista', ['display: flex', 'flex-direction: column', 'gap: 12px']);
        $this->temTudo('.mm-grupo', [
            'background: var(--mm-card)',
            'border: 1px solid var(--mm-grupo-borda)',
            'border-radius: 4px',
            'box-shadow: 0 2px 10px rgba(18,36,47,.05)',
            'overflow: hidden',
        ]);
    }

    #[TestDox('LIS-03/04: cabeçalho do grupo e contador como o desenho; ponto 7px')]
    public function testCabecalhoDoGrupo(): void
    {
        $this->temTudo('.mm-grupo-cabeca', [
            'padding: 15px 20px',
            'background: var(--mm-grupo-cabeca-bg)',
            'border: 0',
            'border-bottom: 1px solid var(--mm-grupo-cabeca-borda)',
            'gap: 8px',
            'font-size: 12px',
            'font-weight: 700',
            'letter-spacing: 1.4px',
            'color: var(--mm-grupo-texto)',
        ]);
        self::assertDoesNotMatchRegularExpression('/border-top:/', $this->regras('.mm-grupo-cabeca'), 'só a borda de baixo');
        $this->temTudo('.mm-grupo-cabeca > .mm-grupo-nome', ['font-size: 12px', 'letter-spacing: 1.4px']);
        $this->temTudo('.mm-grupo-cabeca > .mm-grupo-contagem', [
            'font-size: 11px',
            'font-weight: 700',
            'padding: 1px 6px',
            'border-radius: 3px',
            'background: var(--mm-grupo-contagem-bg)',
            'color: var(--mm-grupo-texto)',
        ]);
        $this->temTudo('.mm-grupo-cabeca > .mm-dot', ['width: 7px', 'height: 7px']);
        self::assertStringNotContainsString('button.mm-grupo-cabeca', $this->css(), 'nenhum grupo recolhe (desenho 04, P:163)');
        self::assertStringNotContainsString('.mm-grupo-seta', $this->css());
    }

    #[TestDox('LIS-05/06: a cor do grupo vem do token do grupo e pinta a faixa da linha; o hover continua vencendo')]
    public function testCorDoGrupo(): void
    {
        $cores = [
            'atrasadas'  => ['atrasadas', '#a3232b'],
            'proximas'   => ['proximas', '#c08a12'],
            'em_revisao' => ['em-revisao', '#0a6fa0'],
            'depois'     => ['depois', '#1e7b45'],
            'sem_prazo'  => ['sem-prazo', '#7d909d'],
            'concluidas' => ['concluidas', '#1e7b45'],
        ];
        foreach ($cores as $chave => [$token, $cor]) {
            $this->temTudo('.mm-grupo-' . $chave, ['--mm-grupo-cor: var(--mm-grupo-' . $token . '-cor)']);
            self::assertSame($cor, $this->token('--mm-grupo-' . $token . '-cor'), 'desenho 04, P:154: ' . $chave);
            self::assertSame(2, substr_count($this->css(), '--mm-grupo-' . $token . '-cor:'), 'token claro e escuro: ' . $chave);
        }
        foreach (['danger' => 'danger-dot', 'warn' => 'warn-dot', 'accent' => 'accent', 'cinza' => 'cinza-dot', 'ok' => 'ok-dot'] as $tom => $reserva) {
            $regra = $this->regras('.mm-meta.tom-' . $tom);
            self::assertStringContainsString('box-shadow: inset 4px 0 0 var(--mm-grupo-cor, var(--mm-' . $reserva . '))', $regra, 'faixa da linha na cor do grupo (dc L88): ' . $tom);
            self::assertStringNotContainsString('border-left', $regra, $tom);
        }
        // Tema escuro: um verde só (No prazo, Concluídas e o ponto/faixa do andamento), 7,7:1 sobre o cartão.
        foreach (['--mm-grupo-depois-cor', '--mm-grupo-concluidas-cor', '--mm-faixa-andamento'] as $verde) {
            self::assertSame(1, preg_match('/\[data-bs-theme="dark"\] \.mm-page \{[^}]*' . preg_quote($verde, '/') . ':\s*([^;]+);/', $this->css(), $m), $verde . ' no escuro');
            self::assertSame('#6ddaa2', trim($m[1]), $verde . ' no escuro');
        }
        self::assertDoesNotMatchRegularExpression('/background:/', $this->regras('.mm-meta.tom-ok'), 'fundo branco; só a atrasada tem fundo (P:162)');
        self::assertGreaterThan(strpos($this->css(), '.mm-meta.tom-warn'), strpos($this->css(), '.mm-meta:hover {'), 'hover depois dos tons');
        self::assertStringContainsString('var(--mm-danger-dot)', $this->regras('.mm-page .mm-titulo::after'), 'o traço do H1 não mudou de token');
    }

    #[TestDox('LIS-15: a linha mostra o cursor de clique')]
    public function testLinhaClicavel(): void
    {
        $this->temTudo('.mm-meta', ['cursor: pointer']);
    }

    #[TestDox('LIS-18/33: 2ª linha 12.5px #4a6373, nº da pasta negrito azul; "criada" 11.5px #7d909d')]
    public function testLinhaDaMeta(): void
    {
        $this->temTudo('.mm-meta-info', ['font-size: 12.5px', 'color: var(--mm-linha-info)', 'gap: 5px']);
        $this->temTudo('.mm-meta-pasta-num', ['font-weight: 700', 'color: var(--mm-num)']);
        $this->temTudo('.mm-meta-datas', ['font-size: 11.5px', 'color: var(--mm-criada-texto)']);
    }

    #[TestDox('LIS-34: vazio numa caixa só: #fff, borda #d6e0e7, raio 4, padding 40, centralizada, #5b7383')]
    public function testVazio(): void
    {
        $this->temTudo('.mm-vazio-caixa', [
            'background: var(--mm-card)',
            'border: 1px solid var(--mm-vazio-borda)',
            'border-radius: 4px',
            'padding: 40px',
            'text-align: center',
            'color: var(--mm-vazio-texto)',
        ]);
    }

    #[TestDox('TRI-09: item de "Precisa de atenção" de largura inteira, flex, gap 10, 11/16, borda #eef2f5, hover #eef6fb')]
    public function testItemDeAtencao(): void
    {
        $this->temTudo('.mm-atencao-item', [
            'display: flex',
            'gap: 10px',
            'width: 100%',
            'padding: 11px 16px',
            'border-bottom: 1px solid var(--mm-div)',
            'text-decoration: none',
            'cursor: pointer',
        ]);
        $this->temTudo('.mm-atencao-item:hover', ['background: var(--mm-atencao-hover)']);
        self::assertSame('#eef2f5', $this->token('--mm-div'));
        $this->temTudo('.mm-atencao-texto', ['display: flex', 'flex-direction: column', 'gap: 3px']);
    }

    #[TestDox('MENU-01: marcador desligado #98a9b4, ligado #0a6fa0')]
    public function testMarcador(): void
    {
        $this->temTudo('.mm-marcador', ['color: var(--mm-marcador)']);
        $this->temTudo('.mm-marcador.is-marcada', ['color: var(--mm-marcador-ligado)']);
    }

    #[TestDox('CAB-01/04: H1 36px negrito -.5px #12242f com sombra, 18px até "Atualizado"; subtítulo 13.5px #5b7383; só sob .mm-cabecalho (a Equipe não muda)')]
    public function testCabecalho(): void
    {
        $this->temTudo('.mm-page .mm-cabecalho .mm-titulo', [
            'font-size: 36px',
            'font-weight: 700',
            'letter-spacing: -.5px',
            'color: var(--mm-text)',
            'text-shadow: 0 2px 6px rgba(10,111,160,.12)',
        ]);
        self::assertSame('#12242f', $this->token('--mm-text'));
        $this->temTudo('.mm-page .mm-cabecalho .mm-cabecalho-linha', ['display: flex', 'gap: 18px']);
        $this->temTudo('.mm-page .mm-cabecalho .mm-subtitulo', ['font-size: 13.5px', 'color: var(--mm-subtitulo)', 'margin: 8px 0 0']);
        $this->temTudo('.mm-page .mm-titulo', ['font-size: 1.5rem']);   // o .mm-titulo geral (Equipe) segue como era
    }

    #[TestDox('FIL-03: busca e selects de 36px, raio 4, borda #e2e9ee, só dentro da barra desta tela; o anel de foco continua')]
    public function testBusca(): void
    {
        $this->temTudo('.mm-barra-cartao .filtro-busca', ['flex: 1 1 220px', 'min-width: 180px', 'max-width: none']);
        $this->temTudo('.mm-barra-cartao .filtro-busca .bi-search', ['color: var(--mm-busca-icone)']);
        foreach (['.mm-barra-cartao .filtro-busca input.js-filtro-busca', '.mm-barra-cartao .filtro-select'] as $seletor) {
            $this->temTudo($seletor, ['height: 36px', 'border: 1px solid var(--mm-controle-borda)', 'border-radius: 4px', 'font-size: 13px']);
            self::assertDoesNotMatchRegularExpression('/(^|[\s;])background:/', $this->regras($seletor), 'background-color, para não apagar a seta do select');
            $this->temTudo($seletor . ':focus', ['box-shadow: 0 0 0 3px var(--mm-foco-anel)']);
        }
        self::assertStringNotContainsString('outline: none', $this->regras('.mm-barra-cartao .filtro-select:focus'));
    }

    #[TestDox('FIL-03/MM-FIL-11: busca, Prioridade e Prazo numa linha só a partir de 768px; o form desce inteiro em vez de quebrar os campos; selects pelo conteúdo')]
    public function testFiltrosNumaLinhaSo(): void
    {
        $this->temTudo('.mm-barra-cartao > .filtro-barra', ['flex: 1 1 auto', 'min-width: 0']);
        self::assertSame(
            1,
            preg_match('/@media \(min-width:\s*768px\)\s*\{\s*\.mm-barra-cartao > \.filtro-barra\s*\{\s*min-width:\s*auto;\s*\}\s*\.mm-barra-cartao \.filtro-linha\s*\{\s*flex-wrap:\s*nowrap;\s*\}/', $this->css()),
            'em tela larga a linha dos campos não quebra e o form não encolhe abaixo do conteúdo',
        );
        $this->temTudo('.mm-barra-cartao .filtro-select', ['flex: 0 0 auto', 'width: auto', 'min-width: 120px', 'max-width: none']);
        $this->temTudo('.mm-barra-cartao .filtro-busca', ['min-width: 180px']);
    }

    #[TestDox('Desenho 04 (P:79): "Limpar filtros" é botão de texto #0a6fa0, sem borda, 13px, 34px, na linha dos selects; os chips (fora do desenho) não aparecem, só nesta barra')]
    public function testLimparFiltros(): void
    {
        self::assertSame('#0a6fa0', $this->token('--mm-limpar'));
        $this->temTudo('.mm-barra-cartao .filtro-limpar', ['height: 34px', 'border: none', 'background: transparent', 'color: var(--mm-limpar)', 'font-size: 13px', 'text-decoration: none']);
        $this->temTudo('.mm-barra-cartao .filtro-limpar:hover', ['color: var(--mm-limpar)']);
        $this->temTudo('.mm-barra-cartao .filtro-chip', ['display: none']);
        $this->temTudo('.mm-barra-cartao .filtro-chips', ['margin-top: 0']);
        $this->temTudo('.mm-barra-cartao > .filtro-barra', ['display: flex', 'align-items: center']);
        self::assertStringNotContainsString('.filtro-limpar', (string) preg_replace('/\.mm-barra-cartao \.filtro-limpar/', '', $this->css()), 'nenhuma regra de .filtro-limpar fora desta barra');
    }
}
