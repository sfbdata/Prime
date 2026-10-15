<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E8 da frente de fidelidade — folha do CSS e do JS da Lista do Expediente contra o desenho de 13/10
 * (`03 - EXPEDIENTE Lista 1.1.dc.html`). Teste de folha não vê cascata nem comportamento: a medição na tela
 * (spec §13) é a prova. Aqui ficam o que não pode regredir em silêncio: o escopo (nada vaza da `.exp-body`),
 * os valores do desenho, os menus SÓ com ações que existem e a pergunta da exclusão de marcador.
 */
final class ExpedienteListaDesenhoFolhaTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../';

    private static function ler(string $arquivo): string
    {
        return (string) file_get_contents(self::DIR . $arquivo);
    }

    private static function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', self::ler('public/css/expediente-lista.css'));
    }

    #[TestDox('escopo: toda regra do expediente-lista.css mora sob .exp-body (ou é o menu/aviso flutuante .exp-ctx/.exp-aviso, ou keyframes)')]
    public function testNadaVazaDaPagina(): void
    {
        // `[{}]` antes do seletor: pega também as regras de dentro de @media.
        preg_match_all('/(?:^|[{}])\s*([^{}@]+)\{/', self::css(), $m);
        foreach ($m[1] as $grupo) {
            foreach (explode(',', $grupo) as $sel) {
                $sel = trim($sel);
                if ($sel === '' || preg_match('/^(\d+%|from|to)/', $sel)) {
                    continue;
                }
                self::assertMatchesRegularExpression('/^(\.exp-body|\[data-bs-theme="dark"\] \.exp-body|\.exp-ctx|\.exp-aviso)/', $sel, 'seletor fora do escopo: ' . $sel);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function valores(): iterable
    {
        yield 'hover da linha #3b8fc2 (dc L.31)' => ['--ex-linha-hover:  #3b8fc2;'];
        yield 'faixa do hover #1f6f9c' => ['--ex-linha-barra:  #1f6f9c;'];
        yield 'prioridade normal #eef2f5/#1f3644 (dc L.431)' => ['--ex-prio-normal-bg: #eef2f5;  --ex-prio-normal-tx: #1f3644;'];
        yield 'prioridade "alta" (= Prioridade do sistema) #fdf0e1/#9a5208' => ['--ex-prio-alta-bg:   #fdf0e1;  --ex-prio-alta-tx:   #9a5208;'];
        yield 'urgente #fbe7e7/#a3232b' => ['--ex-prio-urg-bg:    #fbe7e7;  --ex-prio-urg-tx:    #a3232b;'];
        yield 'situação ativo #1e7b45 / ponto #2fa35f (dc L.432)' => ['--ex-sit-ativo-tx:   #1e7b45;  --ex-sit-ativo-pt:   #2fa35f;'];
        yield 'situação arquivado #34505f / ponto #62798a' => ['--ex-sit-arq-tx:     #34505f;  --ex-sit-arq-pt:     #62798a;'];
        yield 'cabeçalho da tabela #f4f7f9' => ['--ex-cab-tabela:   #f4f7f9;'];
        yield 'barra do acervo #f7f9fb' => ['--ex-barra:        #f7f9fb;'];
        yield 'Nova Pasta #e2e9ee' => ['--ex-botao:        #e2e9ee;'];
        yield 'colunas do desenho (96 · 120 · 220 · 76 · 190 · 116)' => ['col:nth-child(5) { width: 220px !important; }'];
        // E8b: Cliente e Ação em 2fr:1.5fr. `calc` com % é ignorado no <col>; em percentual, as duas dividem a sobra
        // das colunas fixas na razão 40:30 (= 2:1.5), medido na tela.
        yield 'Cliente 2fr (dc L.146)' => ['col:nth-child(2) { width: 40% !important; }'];
        yield 'Ação 1.5fr (dc L.146)' => ['col:nth-child(3) { width: 30% !important; }'];
        yield 'período num bloco só, 34px, fundo do campo (dc L.109)' => ['.exp-body .pasta-filtros-periodo { height: 34px; min-width: 120px; max-width: 250px; gap: 2px; padding: 0 4px; border-radius: 4px; background: var(--ex-campo); }'];
        yield '"a" entre as datas #7d909d (dc L.109)' => ['--ex-periodo-sep:  #7d909d;'];
    }

    #[DataProvider('valores')]
    #[TestDox('valor do desenho: $_dataName')]
    public function testValoresDoDesenho(string $trecho): void
    {
        self::assertStringContainsString($trecho, self::ler('public/css/expediente-lista.css'));
    }

    #[TestDox('hover da linha: fundo azul, faixa interna de 4px, tudo branco — e a lápide (pasta excluída) fica de fora')]
    public function testHoverDaLinha(): void
    {
        $css = self::css();
        self::assertStringContainsString('tr.pasta-row-link:not(.pasta-excluida):hover > td {', $css);
        self::assertStringContainsString('box-shadow: inset 4px 0 0 var(--ex-linha-barra), 0 4px 12px rgba(10,111,160,.16);', $css);
        self::assertMatchesRegularExpression('/:hover > td \*[^{]*\{\s*color: #fff !important;/', $css);
    }

    #[TestDox('situação: o ponto é ::before guiado por data-situacao (o duplo clique reescreve o texto do selo)')]
    public function testSituacaoPeloAtributo(): void
    {
        $css = self::css();
        self::assertStringContainsString('.exp-body .js-toggle-situacao[data-situacao="ativo"]::before', $css);
        self::assertStringContainsString('.exp-body .js-toggle-situacao[data-situacao="arquivado"]::before', $css);
        $js = self::ler('templates/expediente/index.html.twig');
        self::assertStringContainsString("badge.dataset.situacao = res.data.novaSituacao;", $js, 'premissa: o duplo clique atualiza o atributo');
    }

    #[TestDox('menu da linha e do marcador: só ações que já existem; nada de F2, Trancar, Destacar nem Duplicar')]
    public function testMenusSoComAcoesExistentes(): void
    {
        $js = self::ler('public/js/expediente-lista.js');
        foreach (["'Abrir pasta'", "'Abrir em nova aba'", "'Alterar responsável'", "'Alterar marcador'", "'Copiar número'", "'Copiar nome'",
                  "'Ver pastas do marcador'", "'Novo marcador'", "'Novo sub-marcador'", "'Editar'", "'Excluir marcador'"] as $item) {
            self::assertStringContainsString("rotulo: " . $item, $js);
        }
        foreach (['Trancar', 'Destacar', 'Duplicar', 'F2', 'localStorage'] as $fora) {
            self::assertDoesNotMatchRegularExpression('/rotulo: \'[^\']*' . $fora . '/', $js, $fora . ' não tem backend: fica fora');
        }
        self::assertStringContainsString("if (!excluida && (chip || marcar))", $js, 'lápide é somente leitura: sem "Alterar"');
        self::assertStringContainsString('chip.click()', $js, 'Alterar responsável aciona o chip de sempre');
        self::assertStringContainsString('marcar.click()', $js, 'Alterar marcador aciona o botão de sempre');
        self::assertDoesNotMatchRegularExpression('/innerHTML\s*=/', $js, 'só textContent');
    }

    #[TestDox('revisão: a ação do item roda DEPOIS do clique (senão o menu de responsável abria e fechava no mesmo clique); aviso por região viva criada na carga; "Copiar nome" sem o "+N"')]
    public function testCorrecoesDaRevisao(): void
    {
        $js = self::ler('public/js/expediente-lista.js');
        self::assertStringContainsString("fechar(false);\n                // Depois que ESTE clique terminar de subir", $js);
        self::assertStringContainsString('setTimeout(it.acao, 0);', $js);
        self::assertStringNotContainsString("fechar(false);\n                it.acao();", $js);
        self::assertStringContainsString("vivo.setAttribute('role', 'status');", $js);
        self::assertStringContainsString("aviso.setAttribute('aria-hidden', 'true');", $js);
        self::assertStringContainsString("resumo.getAttribute('data-clientes').split('||').join('; ')", $js);
        self::assertStringContainsString("if (e.key === 'Tab') { e.preventDefault(); fechar(true); return; }", $js, 'Tab devolve o foco a quem abriu');

        $css = self::css();
        self::assertStringContainsString('.exp-body .expediente-menu-link.active:not(:hover):not(:focus-visible):not(:has(:focus-visible)) .expediente-btn-acao', $css, 'selecionado: botões só no hover/foco');
        self::assertStringContainsString('background: var(--ex-azul) !important; color: #fff !important;', $css, 'hover dos botões do trilho branco de fato');

        $t = self::ler('templates/expediente/index.html.twig');
        self::assertStringContainsString("item.querySelector(':scope > a .expediente-item-nome, :scope > .expediente-item-nome')", $t, 'renomear atualiza o nome, não o ponto de cor');
        self::assertStringContainsString('<i class="bi bi-folder-fill" aria-hidden="true"></i>\' + escHtml(primeiro.nome)', $t, 'chip refeito igual ao do Twig');
        self::assertStringNotContainsString('pastasSubs', $t, 'sem somar pastas que podem se repetir entre sub-marcadores');
    }

    #[TestDox('menu: teclado (setas, Home/End, Esc devolve o foco, Tab fecha) e a tecla de menu abre junto do elemento')]
    public function testMenuTeclado(): void
    {
        $js = self::ler('public/js/expediente-lista.js');
        foreach (["e.key === 'Escape'", "e.key === 'ArrowDown'", "e.key === 'ArrowUp'", "e.key === 'Home'", "e.key === 'End'", "e.key === 'Tab'"] as $tecla) {
            self::assertStringContainsString($tecla, $js);
        }
        self::assertStringContainsString("if (!x && !y) {", $js);
        self::assertStringContainsString("b.setAttribute('role', 'menuitem');", $js);
    }

    #[TestDox('excluir marcador: janela da E7 (confirmarPs), texto do desenho, "Excluir mesmo assim", e os sub-marcadores afetados (decisão do dono)')]
    public function testExclusaoDeMarcador(): void
    {
        $t = self::ler('templates/expediente/index.html.twig');
        self::assertStringContainsString("var pergunta = perguntaDeExclusaoDoMarcador(item, nome);", $t);
        self::assertStringContainsString("? window.confirmarPs(pergunta)", $t);
        self::assertStringContainsString("resposta.then(function (sim) {\n            if (sim) { excluirMarcadorConfirmado(id, token, btnExcluir); }", $t, 'o DELETE só no "sim"');
        self::assertStringContainsString("titulo: 'Excluir o marcador \"' + nome + '\"?'", $t);
        self::assertStringContainsString("rotulo: 'Excluir mesmo assim'", $t);
        self::assertStringContainsString("'Esta ação é irreversível. '", $t);
        self::assertStringContainsString("deixam de ter este marcador", $t, 'vários marcadores por pasta: não "ficam sem marcador"');
        // Regra do dono (15/10): sem travessão em texto que o usuário vê; os nomes vão entre parênteses.
        self::assertStringContainsString("sub-marcador(es) (' + nomes.join(', ') + ') também são excluídos,'", $t);
        self::assertStringContainsString("' com o que houver abaixo deles, e as pastas marcadas com eles deixam de tê-los.'", $t, 'cascata inteira, sem somar pastas repetidas');
        self::assertDoesNotMatchRegularExpression('/if \(!confirm\(msg\)\)/', $t);

        preg_match('/function perguntaDeExclusaoDoMarcador\(item, nome\) \{.*?\n    \}/s', $t, $m);
        self::assertNotEmpty($m, 'a função da pergunta existe');
        preg_match_all("/'[^'\\n]*'/", $m[0], $literais);
        foreach ($literais[0] as $literal) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $literal, 'travessão em texto da pergunta: ' . $literal);
        }
        self::assertStringContainsString("moldura: 'alerta'", $m[0], 'a exclusão usa a moldura de alerta do desenho');
    }

    #[TestDox('período: "a" entre as datas, como o desenho (dc L.109), sem travessão (regra do dono de 15/10)')]
    public function testSeparadorDoPeriodo(): void
    {
        $f = self::ler('templates/pasta/_filtros.html.twig');
        self::assertStringContainsString('<span class="pasta-filtros-periodo-sep" aria-hidden="true">a</span>', $f);
        self::assertStringNotContainsString('pasta-filtros-periodo-sep">–', $f);
    }

    #[TestDox('moldura de alerta (dc 03 L.259-271): faixa vermelha, círculo com ⚠, título e botões centralizados; só quando pedida')]
    public function testMolduraDeAlertaDaExclusao(): void
    {
        $js = self::ler('public/js/pasta-dialogos.js');
        self::assertStringContainsString("fundo.classList.toggle('is-alerta', o.moldura === 'alerta');", $js, 'opcional: a Pasta segue com a janela da E7');
        self::assertStringContainsString('.filter(visivel)', $js, 'o Tab não para no X escondido da moldura de alerta');

        $j = (string) preg_replace('/\s+/', ' ', self::ler('templates/_partials/_janela_confirmacao.html.twig'));
        foreach ([
            '--cf-al-faixa: #c0392b;', '--cf-al-circulo: #fbecec;', '--cf-al-titulo: #12242f;', '--cf-al-texto: #34505f;',
            '--cf-al-ok-hv: #a3232b;', '--cf-al-cancelar: #e2e9ee;', '--cf-al-cancelar-tx: #1f3644;', '--cf-al-cancelar-hv: #d3dde4;',
            '.ps-cf-fundo.is-alerta { background: rgba(14,37,51,.38); }',
            'width: min(430px, 100%); box-shadow: 0 26px 60px -20px rgba(12,48,72,.45);',
            'border-top: 5px solid var(--cf-al-faixa);',
            'width: 64px; height: 64px; border-radius: 50%; background: var(--cf-al-circulo);',
            'font-size: 17px; font-weight: 700; color: var(--cf-al-titulo); text-align: center;',
            '.ps-cf-fundo.is-alerta .ps-cf-fechar { display: none; }',
            'height: 38px; padding: 0 18px; font-size: 14px;',
        ] as $trecho) {
            self::assertStringContainsString($trecho, $j);
        }
    }
}
