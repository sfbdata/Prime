<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Contas por consumo" em /master > Armazenamento (pedido M3 do Farlei, 24/11): realce da linha ao passar o mouse,
 * busca e ordem. Lido como FOLHA no padrão de PainelCodigoEOrdemDasContasJsTest (sem node no PHPUnit): o contrato do
 * public/js/master/painel.js, do public/css/master.css e do catálogo.
 */
#[CoversNothing]
final class PainelRankingDoArmazenamentoJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/painel.js');
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/css/master.css');
    }

    private static function catalogo(string $chave): string
    {
        $no = CatalogoPtBr::carregar();
        foreach (explode('.', $chave) as $parte) {
            self::assertIsArray($no, $chave);
            self::assertArrayHasKey($parte, $no, $chave);
            $no = $no[$parte];
        }
        self::assertIsString($no, $chave);

        return $no;
    }

    #[TestDox('a linha realça como as Contas (.mp-lin); nome, % e faixa ficam brancos, a barra e o botão ficam fora do .mp-hv')]
    public function testRealceDaLinha(): void
    {
        $js = self::js();
        self::assertStringContainsString('\'<div class="mp-rank mp-lin" style="--cor:\' + cor + \'"><span class="mp-hv" title="', $js);
        self::assertStringContainsString('<div class="mp-barra-8"><div style="--w:', $js, 'a barra não leva .mp-hv: o realce apagaria o preenchimento');
        self::assertStringContainsString('\'<b class="mp-hv">\' + (sem ? \'\' : c.espaco.percentual + \'%\') + \'</b><span class="mp-hv">\' + esc(rotulo) + \'</span><button type="button" class="mp-rank-btn" data-lim-arm="', $js);

        $css = self::css();
        self::assertStringContainsString('.mp-lin:hover { background: #2b7ab0 !important;', $css, 'o mesmo realce das outras listas do Master');
        self::assertStringContainsString('.mp .mp-rank.mp-lin:hover > .mp-rank-btn:not(:hover) { background: #fff; color: #1f6f9c; }', $css, '"Editar limite" continua visível na linha azul');
    }

    #[TestDox('busca no cabeçalho do cartão com a mesma regra das Contas (casaNaBusca), sem misturar com a busca das Contas')]
    public function testBusca(): void
    {
        $js = self::js();
        self::assertStringContainsString("rBusca: '', rOrd: 'cons'", $js, 'estado próprio; padrão: maior consumo');
        self::assertStringContainsString('<input data-r-busca value="\' + esc(S.rBusca) + \'"', $js);
        self::assertStringContainsString("if (e.target.matches('[data-r-busca]')) { S.rBusca = e.target.value; renderRanking(); }", $js);
        self::assertStringContainsString('const b = S.rBusca.toLowerCase().trim();', $js, 'a busca ignora maiúsculas e espaços nas pontas, como nas Contas');
        self::assertStringContainsString("D.contas.contas.filter(c => c.situacao !== QUARENTENA && (!S.rPlano || c.espaco.planoCodigo === S.rPlano) && (!S.rSit || situacaoNoRanking(c) === S.rSit) && (!b || casaNaBusca(c, b)))", $js, 'filtros das colunas Plano e Situação (27/11) junto da busca');
        self::assertStringContainsString("T(b || S.rPlano || S.rSit ? 'master.js.contas.nenhuma' : 'master.js.arm.nenhuma')", $js, 'busca ou filtro sem resultado diz "Nenhuma conta encontrada."');
        self::assertStringContainsString('<span class="mp-rank-plano mp-hv" title="', $js, 'coluna Plano, logo depois do nome');
        self::assertStringContainsString("if (t.matches('[data-r-plano]')) { S.rPlano = t.value; return renderRanking(); }", $js);
        self::assertStringContainsString("if (t.matches('[data-r-sit]')) { S.rSit = t.value; return renderRanking(); }", $js);

        $css = self::css();
        self::assertStringContainsString('.mp-ranking > .mp-cab-simples { padding: 13px 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }', $css);
        self::assertStringContainsString('.mp-ranking > .mp-cab-simples > b { flex: 1; }', $css);
    }

    #[TestDox('ordem: maior consumo (padrão), nome A-Z e situação (faixa mais grave primeiro); empate pelo nº')]
    public function testOrdem(): void
    {
        $js = self::js();
        self::assertSame(
            ['Ordem: maior consumo', 'Ordem: nome (A-Z)', 'Ordem: situação', 'Ordenar contas por consumo'],
            array_map(self::catalogo(...), ['master.js.arm.ordem_cons', 'master.js.arm.ordem_nome', 'master.js.arm.ordem_sit', 'master.js.arm.ordenar']),
        );
        foreach (['cons', 'nome', 'sit'] as $chave) {
            self::assertStringContainsString("['" . $chave . "', () => T('master.js.arm.ordem_" . $chave . "')]", $js);
        }
        self::assertStringContainsString("if (t.matches('[data-r-ord]')) { S.rOrd = t.value; return renderRanking(); }", $js);
        self::assertStringContainsString('cons: (a, b) => consumo(b) - consumo(a) || b.bytes - a.bytes,', $js, 'a ordem que já existia: % da cota e, sem limite, pelo uso');
        self::assertStringContainsString("nome: (a, b) => a.nome.localeCompare(b.nome, 'pt-BR'),\n    sit: (a, b) =>", $js, 'nome A-Z dentro do COMPARA_RANKING');
        self::assertStringContainsString("sit: (a, b) => gravidade(b) - gravidade(a) || a.nome.localeCompare(b.nome, 'pt-BR'),", $js);
        self::assertStringContainsString('const gravidade = c => (c.espaco.percentual === null ? -1 : ORDEM_FAIXA[c.espaco.faixa] || 0);', $js, 'sem limite vai para o fim');
        self::assertStringContainsString('(COMPARA_RANKING[S.rOrd] || COMPARA_RANKING.cons)(x, y) || x.id - y.id);', $js);
    }
}
