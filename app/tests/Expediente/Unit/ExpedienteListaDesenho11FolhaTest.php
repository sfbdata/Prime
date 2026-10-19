<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E8d da frente de fidelidade: as 30 divergências da auditoria da Lista do Expediente contra o desenho 1.1
 * (`03 - EXPEDIENTE Lista 1.1.dc.html`, "dc L.n"), por ID AUD. Teste de folha trava os VALORES e as regras
 * que não podem voltar em silêncio; a prova de aparência é o Playwright (`e2e/tests/expediente-lista-visual.spec.js`)
 * e a medição na tela registrada na spec (§16).
 */
final class ExpedienteListaDesenho11FolhaTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../';

    private static function ler(string $arquivo): string
    {
        return (string) file_get_contents(self::DIR . $arquivo);
    }

    /** CSS da Lista sem comentários e com os espaços colapsados: o teste lê regra, não formatação. */
    private static function css(): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', self::ler('public/css/expediente-lista.css'));

        return (string) preg_replace('/\s+/', ' ', $css);
    }

    private static function js(): string
    {
        return self::ler('public/js/expediente-lista.js');
    }

    private static function bloco(string $css, string $seletor): string
    {
        $i = strpos($css, $seletor . ' {');
        self::assertNotFalse($i, 'regra ausente: ' . $seletor);

        return substr($css, $i, (int) strpos($css, '}', $i) - $i);
    }

    #[TestDox('AUD-17 a AUD-24: cabeçalho de 40px com os ícones e as setas do dc, ordem padrão marcada, SITUAÇÃO centralizada, NUP a 20px, marcador como cadastrado, botão de marcador do dc')]
    public function testCabecalhoECelulas(): void
    {
        $css = self::css();
        self::assertStringContainsString('letter-spacing: 1.3px; line-height: 14px;', self::bloco($css, '.exp-body #tabelaPastas thead th'));
        self::assertStringContainsString('.exp-body #tabelaPastas thead th:last-child { text-align: center; }', $css);
        self::assertStringContainsString('.exp-body #tabelaPastas thead .sort-icon { font-size: 10px; opacity: 1; margin-left: 6px; color: var(--ex-seta-ordem);', $css);
        self::assertStringContainsString('--ex-seta-ordem: #a7b6c0;', $css);
        self::assertStringContainsString('.exp-body #tabelaPastas tbody .marcador-principal, .exp-body #tabelaPastas tbody .marcadores-extra { text-transform: none; }', $css);
        $tag = self::bloco($css, '.exp-body .js-mover-para');
        self::assertStringContainsString('border: 1px solid var(--ex-borda-tag) !important;', $tag);
        self::assertStringContainsString('background: var(--ex-card) !important; color: var(--ex-rotulo) !important;', $tag);
        self::assertStringContainsString('box-shadow: none !important;', $tag);
        self::assertStringContainsString('--ex-borda-tag: #d8e1e7;', $css);

        $tabela = self::ler('templates/pasta/_tabela.html.twig');
        foreach (['bi-upc', 'bi-person', 'bi-bank', 'bi-flag me-1', 'bi-person-badge', 'bi-check2-square', 'bi-tags', 'bi-circle-half'] as $icone) {
            self::assertStringContainsString('<i class="bi ' . $icone, $tabela, 'ícone do cabeçalho do dc: ' . $icone);
        }
        self::assertStringNotContainsString('bi-briefcase', $tabela);
        self::assertStringNotContainsString('bi-arrow-down-up', $tabela);
        self::assertStringContainsString("{% set ordColVis = ordCol ?: 'nup' %}", $tabela, 'sem ordenação escolhida, o cabeçalho mostra a padrão (NUP)');
        self::assertStringContainsString("{% set ordDirVis = ordCol ? ordDir : 'desc' %}", $tabela);
        self::assertStringContainsString("'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand'", $tabela);
        self::assertStringNotContainsString('\\F148', $tabela, 'o content fixo antigo sobrepunha o ícone do servidor');
        self::assertStringNotContainsString('ps-3', $tabela, 'o !important do ps-3 vencia os 20px do NUP');
        self::assertStringContainsString('<i class="bi bi-tag" aria-hidden="true"></i>', $tabela);
    }

    #[TestDox('AUD-14: resumo com o intervalo de pastas da página ("1 de 50", "51 de 100") e espaços largos em volta do ponto')]
    public function testResumoComIntervalo(): void
    {
        $tabela = self::ler('templates/pasta/_tabela.html.twig');
        self::assertStringContainsString('{%- set primeiraDaPagina = (pagination.current_page - 1) * pagination.per_page + 1 %}', $tabela);
        self::assertStringContainsString("(primeiraDaPagina + pastas|length - 1)|number_format(0, ',', '.') }}&#8195;·&#8195;Total:", $tabela);
        self::assertStringNotContainsString('{{ pagination.current_page }} de {{ max(pagination.total_pages, 1) }}', $tabela);
    }

    #[TestDox('AUD-28: menu de botão direito do dc (título cinza de 12px com divisória, sem faixa azul, itens 8×14px, 250/220px) e os rótulos e ícones dele, sem separadores na linha')]
    public function testMenusDeContexto(): void
    {
        $css = self::css();
        $titulo = self::bloco($css, '.exp-ctx-titulo');
        self::assertStringContainsString('padding: 7px 14px 8px; border-bottom: 1px solid var(--ex-div-linha, #eef2f5); font-size: 12px; color: var(--ex-menu-titulo, #62798a);', $titulo);
        self::assertStringNotContainsString('background', $titulo, 'sem a faixa azul dos menus suspensos');
        self::assertStringNotContainsString('uppercase', $titulo);
        self::assertStringContainsString('width: 250px;', self::bloco($css, '.exp-ctx'));
        self::assertStringContainsString('.exp-ctx--marcador { width: 220px; }', $css);
        self::assertStringContainsString('padding: 8px 14px; border: 0; border-radius: 0;', self::bloco($css, '.exp-ctx-item'));

        $js = self::js();
        self::assertStringContainsString("abrir(editando ? '' : 'Pasta ' + nup + (temNome ? ' · ' + nome : ''), lista, x, y, espelho || linha, editando ? 'editando' : null);", $js);
        self::assertStringContainsString("window.ExpMenu.abrir(el.dataset.nome + ' · ' + (el.dataset.n || '0') + ' pasta(s)', lista, x, y, el, 'marcador');", self::ler('public/js/expediente-trilho.js'));
        foreach (["icone: 'bi-person-badge'", "icone: 'bi-funnel'", "icone: 'bi-plus-lg'", "rotulo: 'Copiar número da pasta'", "rotulo: 'Copiar nome do cliente'"] as $trecho) {
            self::assertStringContainsString($trecho, $js . self::ler('public/js/expediente-trilho.js'));
        }
        self::assertStringNotContainsString('bi-person-gear', $js);
        // E12: com Trancar e Destacar, o menu da linha tem os separadores do dc (L.357 e L.359).
        $linha = substr($js, (int) strpos($js, 'function menuDaLinha'), 4000);
        self::assertSame(2, substr_count($linha, "lista.push('-');"));
    }

    #[TestDox('AUD-01/02/30: 28px nas laterais, 26px até o título, 18px até o conteúdo; avisos do sistema preservados; o rótulo solto do celular sai')]
    public function testMargens(): void
    {
        $css = self::css();
        self::assertStringContainsString('.exp-body .app-content-header:not(:has(.alert)) { padding: 0; }', $css, 'com aviso, o cabeçalho de avisos fica');
        self::assertStringContainsString('.exp-body section.content-header { padding-top: 26px; }', $css);
        self::assertStringContainsString('.exp-body section.content { padding-top: 18px; }', $css);
        self::assertStringContainsString('@media (min-width: 768px) { .exp-body .app-content, .exp-body .app-content-header { padding-left: 28px; padding-right: 28px; }', $css);
        self::assertStringContainsString('.exp-body section.content > .container-fluid > .d-md-none:has(> #labelPainelAtivo) { display: none !important; }', $css);
    }

    #[TestDox('AUD-08 a AUD-13, AUD-16: faixa do dc (filtros, Visualizar e paginação na mesma linha; resumo embaixo), filtros com cara de botão e estado ativo, situação na barra, setas da paginação')]
    public function testFaixaDeFiltros(): void
    {
        $css = self::css();
        foreach ([
            '.exp-body .exp-painel { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: flex-end; }',
            '.exp-body .exp-painel > #pastas-resultado, .exp-body #pastas-resultado > #pastasView, .exp-body #pastasView > .exp-topo { display: contents; }',
            '.exp-body .exp-painel > .pasta-filtros { flex: 1 1 504px; padding: 16px 22px 0; margin: 0 !important; }',
            '.exp-body .exp-topo > .exp-controles { flex: 0 1 auto; margin-left: -12px; padding: 16px 22px 0 0;',
            '.exp-body .exp-topo > .exp-resumo { flex: 0 0 100%; padding: 12px 22px; }',
            '.exp-body .pasta-filtros-linha { flex-wrap: nowrap; }',
            '.exp-body .pasta-filtros-busca { flex: 2.2 1 0; min-width: 70px; max-width: none; }',
            '.exp-body .exp-filtro--resp { flex: 1.3 1 0; min-width: 70px; }',
            '.exp-body .exp-filtro--prio { flex: 1 1 0; min-width: 60px; }',
            '.exp-body .exp-filtro.is-ativo .pasta-filtros-select, .exp-body .pasta-filtros-busca.is-ativo .form-control, .exp-body .pasta-filtros-periodo.is-ativo { background-color: var(--ex-campo-ativo); }',
            '--ex-campo-ativo: #e3f1f8;',
            '.exp-body .exp-filtro--prio[data-valor="urgente"] .exp-filtro-bandeira { color: #c0392b; }',
            '.exp-body .exp-situacao[data-valor="ativo"] .exp-situacao-select { background-color: var(--ex-sit-ativo-bg); }',
            '--ex-sit-ativo-bg: #e4f4ea;',
            '.exp-body .exp-vis-item[aria-checked="true"] .exp-vis-ponto { background: var(--ex-azul); }',
            '.exp-body .pagination .page-item.disabled .page-link { color: var(--ex-seta-ordem); background: transparent; cursor: default; }',
            '.exp-body .pasta-filtros-periodo .pasta-data-campo .form-control { font-size: clamp(11px, .75vw, 13px); color: var(--ex-filtro-tx); }',
            '.exp-body #pastas-resultado.pastas-carregando .pastas-tabela-wrap,',
        ] as $trecho) {
            self::assertStringContainsString($trecho, $css);
        }
        $situacao = self::bloco($css, '.exp-body .exp-situacao-select');
        self::assertStringContainsString('height: 36px;', $situacao);
        self::assertStringContainsString('font-size: 14px; font-weight: 700;', $situacao);
        $vis = self::bloco($css, '.exp-body .exp-visualizar-botao');
        self::assertStringContainsString('background: var(--ex-campo); color: var(--ex-azul); font: inherit; font-size: 13px; font-weight: 700;', $vis);

        $js = self::js();
        self::assertStringContainsString('function marcarFiltros()', $js);
        self::assertStringContainsString('Array.prototype.forEach.call(form.elements, function (el) {', $js, 'a situação mora fora do <form>');
        self::assertStringContainsString("recolher = document.getElementById('expedienteSidebarToggleBtn')", $js, 'Painel de marcadores usa o botão de sempre');

        $index = self::ler('templates/expediente/index.html.twig');
        self::assertStringNotContainsString("form.querySelectorAll('select.js-pasta-filtro')", $index);
        self::assertStringContainsString('form.elements.namedItem(remover.dataset.campo)', $index);
        self::assertStringContainsString("Array.prototype.filter.call(f.elements, function (el) { return el.matches('.js-pasta-busca, .js-pasta-filtro'); })", $index, '"Limpar tudo" zera também a situação');

        $pag = self::ler('templates/expediente/_paginacao.html.twig');
        foreach (['bi-chevron-double-left', 'bi-chevron-left', 'bi-chevron-right', 'bi-chevron-double-right'] as $seta) {
            self::assertStringContainsString('<i class="bi ' . $seta . '" aria-hidden="true"></i>', $pag);
        }
        self::assertStringNotContainsString('&laquo;', $pag);
    }

    #[TestDox('AUD-25 a AUD-27: avatar e responsável do dc SÓ na Lista; os parciais compartilhados com a Pasta ficam como estavam')]
    public function testResponsavelSoNaLista(): void
    {
        $css = self::css();
        self::assertStringContainsString('.exp-body #pastasView .pasta-resp-chip .resp-avatar-ini, .exp-body #pastasView .pasta-resp-chip .resp-avatar-img { background: var(--ex-avatar); font-size: 10px; }', $css);
        self::assertStringContainsString('--ex-avatar: #0a6fa0;', $css);
        self::assertStringContainsString('.exp-body #pastasView .pasta-resp-chip .resp-avatar-vazio { background: var(--ex-avatar-vazio-bg); color: var(--ex-avatar-vazio-tx);', $css);

        $js = self::js();
        self::assertStringContainsString("return nome.trim().split(/\\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase();", $js, 'dc `ini2`: duas primeiras palavras');
        self::assertStringContainsString("document.querySelectorAll('#pastasView .pasta-resp-chip')", $js, 'só os chips da Lista');
        self::assertStringContainsString("vazio.textContent = '?';", $js);

        // Decisão do dono: nada global muda.
        self::assertStringContainsString('partes|first|slice(0, 1) ~ partes|last|slice(0, 1)', self::ler('templates/_partials/_avatar_usuario.html.twig'));
        self::assertStringContainsString('.resp-avatar-ini { background: linear-gradient(135deg, #1a3a5c, #2563a8);', self::ler('templates/pasta/_resp_estilo.html.twig'));
        self::assertStringContainsString("<i class=\"bi {{ naPasta ? 'bi-person-dash' : 'bi-person' }}\"></i>", self::ler('templates/pasta/_resp_chip.html.twig'));
    }

    #[TestDox('AUD-29 (decisão do dono): de 901 a 1280px Cliente e Ação legíveis (piso 1262px) com a rolagem só dentro da tabela; até 900px, Cliente com 220px')]
    public function testResponsividade(): void
    {
        $css = self::css();
        self::assertStringContainsString('@media (min-width: 901px) and (max-width: 1280px) { .exp-body #tabelaPastas { min-width: 1262px; } .exp-body #tabelaPastas [data-col="reu"] { display: none; } }', $css);
        self::assertStringContainsString('@media (max-width: 900px) { .exp-body #tabelaPastas { min-width: 742px; }', $css);
    }

    #[TestDox('tema escuro: todo token novo da rodada tem par no escuro')]
    public function testTemaEscuro(): void
    {
        $css = self::css();
        $escuro = self::bloco($css, '[data-bs-theme="dark"] .exp-body');
        foreach (['--ex-seta-ordem', '--ex-borda-tag', '--ex-borda-menu', '--ex-menu-titulo', '--ex-menu-hover', '--ex-perigo-hover', '--ex-filtro-tx', '--ex-campo-ativo', '--ex-bandeira', '--ex-sit-ativo-bg', '--ex-avatar', '--ex-avatar-vazio-bg'] as $token) {
            self::assertStringContainsString($token . ':', $escuro, 'sem par no escuro: ' . $token);
        }
        // E12: o chip do marcador usa a cor do marcador (texto) sobre ela a 10% (`--mk-cor`), não mais o pastel de fundo.
    }

    #[TestDox('título da barra (Acervo geral): a caixa tem o tamanho do conteúdo, o realce do hover não estica até o Nova Pasta')]
    public function testTituloDaBarraDoTamanhoDoConteudo(): void
    {
        $titulo = self::bloco(self::css(), '.exp-body .exp-barra-titulo');
        self::assertStringContainsString('flex: 0 1 auto; min-width: 0;', $titulo);
        self::assertStringContainsString('margin-right: auto;', $titulo);
        self::assertStringNotContainsString('flex: 1;', $titulo);
    }

    #[TestDox('menu de botão direito também nos modos Lista e Cartões, e F2 na linha simples focada')]
    public function testMenuDaLinhaNosModosListaECartoes(): void
    {
        $js = self::js();
        self::assertStringContainsString("e.target.closest('.exp-simples-linha[data-pasta-id], .exp-cartao[data-pasta-id]')", $js);
        self::assertStringContainsString("e.target.closest('.exp-simples-linha[data-pasta-id]')", $js);
        self::assertStringContainsString('function linhaDaTabela(item)', $js);
    }

    #[TestDox('VS 18.10.26: colunas por usuário com a subfrase do dc, hover do chip do responsável na linha azul, textos do dc')]
    public function testDesenhoVs181026(): void
    {
        $js = self::js();
        self::assertStringContainsString("t.getAttribute('data-chave-colunas')", $js);
        self::assertStringContainsString("{ icone: 'bi-person-lock', texto: 'Ajuste só seu: não muda para o resto da equipe' }", $js);
        self::assertStringContainsString("'BlueJus I.A · '", $js);
        $css = self::css();
        self::assertStringContainsString('> td .pasta-resp-chip:hover:not(.resp-avatar):not(.pasta-favorita-estrela) { background: rgba(255,255,255,.2) !important; border-color: rgba(255,255,255,.65) !important; }', $css);
        self::assertStringContainsString('placeholder="Buscar pasta, cliente, ação ou processo"', self::ler('templates/pasta/_filtros.html.twig'));
    }
}
