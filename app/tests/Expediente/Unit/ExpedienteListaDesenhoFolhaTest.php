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
        preg_match_all('/(?:^|\})\s*([^{}@]+)\{/', self::css(), $m);
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
        self::assertStringContainsString("sub-marcador(es) — ' + nomes.join(', ') + ' — também são excluídos'", $t);
        self::assertDoesNotMatchRegularExpression('/if \(!confirm\(msg\)\)/', $t);
    }
}
