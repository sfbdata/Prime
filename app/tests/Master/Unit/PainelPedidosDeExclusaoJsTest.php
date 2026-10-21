<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Bloco "Pedidos de exclusão de conta" do public/js/master/painel.js (desenho 05 - Perfis 1.2, linhas 148 e
 * 679), lido como FOLHA: não há navegador no PHPUnit, então o que se prova é o contrato do JS e do CSS com o
 * desenho e com a regra do dono (quarentena de 365 dias: nada de "irreversível" nem do confirm/prompt do desenho).
 */
final class PainelPedidosDeExclusaoJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/painel.js');
    }

    private static function bloco(): string
    {
        $js = self::js();
        $inicio = strpos($js, 'function renderPedidosDeExclusao()');
        self::assertNotFalse($inicio, 'o bloco existe');
        $fim = strpos($js, "\n  }\n", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('textos do desenho: título com o contador, subtítulo, linha "por · data · motivo" e os dois botões; âncora #pedidos-exclusao')]
    public function testTextosDoDesenho(): void
    {
        $bloco = self::bloco();

        self::assertStringContainsString("Pedidos de exclusão de conta · ' + num(peds.length) + '</b>", $bloco);
        self::assertStringContainsString('contas suspensas aguardando sua decisão', $bloco);
        self::assertStringContainsString("esc(quemPediu(p)) + ' · ' + esc(dataHora(p.criadoEm)) + ' · ' + esc(p.motivo)", $bloco);
        self::assertStringContainsString('<i class="bi bi-trash3"></i>', $bloco);
        self::assertStringContainsString('>Reativar</button>', $bloco);
        // Rótulo decidido pelo dono (spec perfis-1.2-contas C4 e §3): o texto diz o que acontece, a quarentena.
        self::assertStringContainsString('>Excluir conta</button>', $bloco);
        self::assertStringNotContainsString('Excluir definitivamente', self::js());
        self::assertStringContainsString('id="pedidos-exclusao"', $bloco);
        self::assertStringContainsString("p.pedidoPorNome + (p.pedidoPorEmail ? ' (' + p.pedidoPorEmail + ')' : '')", self::js(), 'por = nome (e-mail), como no desenho');
    }

    #[TestDox('sem pedidos o bloco some (exTem): o invólucro fica hidden e vazio')]
    public function testSomeSemPedidos(): void
    {
        $bloco = self::bloco();

        self::assertStringContainsString('host.hidden = peds.length === 0;', $bloco);
        self::assertStringContainsString("peds.length === 0 ? ''", $bloco);
        self::assertStringContainsString('<div id="mp-pedidos-exclusao" hidden></div>', self::js());
    }

    #[TestDox('Reativar e Excluir abrem as MESMAS confirmações do menu da conta; sem confirm/prompt e sem "irreversível"')]
    public function testMesmosFluxosDoMenu(): void
    {
        $js = self::js();

        self::assertStringContainsString("const ped = t.closest('[data-pedido-op]'); if (ped) { e.stopPropagation(); fecharMenuDaConta(); return pedirAcaoNaConta(ped.dataset.pedidoOp, +ped.dataset.contaId); }", $js);
        self::assertStringContainsString('data-pedido-op="reativar"', self::bloco());
        self::assertStringContainsString('data-pedido-op="excluir"', self::bloco());
        foreach (['confirm(', 'prompt(', 'irreversível', 'não pode ser desfeita'] as $proibido) {
            self::assertStringNotContainsString($proibido, $js, $proibido);
        }
    }

    #[TestDox('depois de reativar ou excluir com sucesso a linha sai e o contador refaz; suspender não decide o pedido')]
    public function testLinhaSaiDepoisDoSucesso(): void
    {
        $js = self::js();

        self::assertStringContainsString("if (tipo !== 'suspender') { D.contas.pedidosExclusao = pedidosDeExclusao().filter(p => p.contaId !== c.id); c.pedidoPendente = false; }", $js);
        self::assertStringContainsString("if (S.sec === 'contas') { renderPedidosDeExclusao(); renderContasLista(); }", $js);
        $sucesso = strpos($js, 'if (json && r.ok && j.conta) {');
        $filtro = strpos($js, "if (tipo !== 'suspender') { D.contas.pedidosExclusao");
        $erro = strpos($js, 'avisar(false, json && !r.ok ? j.erro : null);');
        self::assertNotFalse($sucesso);
        self::assertTrue($sucesso < $filtro && $filtro < $erro, 'a linha só sai no ramo de sucesso');
    }

    #[TestDox('tabela de contas: com pedido pendente a situação aparece "Suspensa a pedido" (vermelho da Suspensa do desenho), não "Ativa"; decidido, volta a seguir a conta')]
    public function testSituacaoSuspensaAPedido(): void
    {
        $js = self::js();

        self::assertStringContainsString("const ST = { Ativa: '#1e7b45', Suspensa: '#a3232b', 'Suspensa a pedido': '#a3232b', 'Em quarentena': '#5b7383' };", $js);
        self::assertStringContainsString("const situacaoVisivel = c => (c.pedidoPendente && c.situacao === 'Ativa' ? A_PEDIDO : c.situacao);", $js);
        self::assertStringContainsString("const A_PEDIDO = 'Suspensa a pedido';", $js);
        self::assertStringContainsString("const sit = situacaoVisivel(c), cor = ST[sit] || '#4a6373';", $js);
        self::assertStringContainsString("'<span style=\"--cor:' + cor + '\"><i></i>' + esc(sit) + '</span>'", $js, 'mesma etiqueta (ponto + texto na cor) das outras situações');
    }

    #[TestDox('cores e medidas exatas do desenho no master.css')]
    public function testCssDoDesenho(): void
    {
        $css = (string) file_get_contents(self::RAIZ . 'public/css/master.css');

        foreach ([
            '.mp-pedidos { background: #fff; border: 1px solid #f1c4c9; border-radius: 4px; overflow: hidden; margin-bottom: 14px; }',
            '.mp-pedidos-cab { display: flex; align-items: center; gap: 10px; padding: 12px 20px; background: #fdf1f2; border-bottom: 1px solid #f6dcdf; }',
            '.mp-pedidos-cab > .bi { color: #a3232b; }',
            '.mp-pedidos-cab > span { font-size: 12px; color: #842029; }',
            '.mp-pedido { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 10px 20px; border-bottom: 1px solid #f6e6e8; font-size: 13px; }',
            '.mp .mp-pedido-reativar { height: 30px; padding: 0 11px; border: 1px solid #c5d2db; border-radius: 3px; background: #fff; color: #1e7b45;',
            '.mp .mp-pedido-excluir { height: 30px; padding: 0 11px; border: none; border-radius: 3px; background: #a3232b; color: #fff; font: inherit; font-size: 12.5px; font-weight: bold;',
        ] as $regra) {
            self::assertStringContainsString($regra, $css);
        }
    }
}
