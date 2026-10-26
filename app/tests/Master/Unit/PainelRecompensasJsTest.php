<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Seção Recompensas do /master, F1 (desenho 05 VS 25.10.26, L306-338 e L854-964), lida como FOLHA no padrão de
 * PainelPlanosELimitesJsTest: sem navegador no PHPUnit, prova-se o contrato do recompensas.js, do parcial Twig, do CSS
 * e do catálogo com o desenho. O texto mora no catálogo (master.recompensas.* e master.js.recompensas.*).
 */
#[CoversNothing]
final class PainelRecompensasJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function arquivo(string $caminho): string
    {
        return (string) file_get_contents(self::RAIZ . $caminho);
    }

    private static function js(): string
    {
        return self::arquivo('public/js/master/recompensas.js');
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

    #[TestDox('toda chave T(...) do recompensas.js e toda chave |trans do parcial existem no catálogo; as chaves montadas (filtros) também')]
    public function testChavesNoCatalogo(): void
    {
        preg_match_all("/\\bT\\('([a-z][a-z0-9_.]+)'/", self::js(), $js);
        preg_match_all("/'(master\\.recompensas\\.[a-z0-9_.]+)'\\s*\\|\\s*trans/", self::arquivo('templates/master/_recompensas.html.twig'), $twig);

        self::assertGreaterThan(25, count(array_unique($js[1])));
        self::assertGreaterThan(25, count(array_unique($twig[1])));
        foreach (array_unique([...$js[1], ...$twig[1]]) as $chave) {
            if (str_ends_with($chave, '.')) {
                continue; // prefixo montado no código (filtros), conferido abaixo
            }
            self::catalogo($chave);
        }
        foreach (['todos', 'pagar', 'lib', 'nao', 'priv'] as $filtro) {
            self::catalogo('master.js.recompensas.filtro.' . $filtro);
        }
        self::assertStringContainsString("T('master.js.recompensas.filtro.' + f)", self::js());
    }

    #[TestDox('textos do desenho: KPIs, filtros, situações, colunas, regras, nota e janela da condição individual')]
    public function testTextosDoDesenho(): void
    {
        $esperado = [
            'master.js.secao.recomp' => 'Recompensas',
            'master.recompensas.titulo' => 'Regras do programa Indique e ganhe',
            'master.js.recompensas.ativo' => 'Programa ativo',
            'master.js.recompensas.pausado' => 'Programa pausado',
            'master.js.recompensas.kpi.indicadores' => 'INDICADORES',
            'master.js.recompensas.kpi.contas' => 'CONTAS ABERTAS',
            'master.js.recompensas.kpi.liberados' => 'LIBERADOS',
            'master.js.recompensas.kpi.a_pagar' => 'A PAGAR',
            'master.js.recompensas.kpi.ja_pago' => 'JÁ PAGO',
            'master.js.recompensas.filtro.todos' => 'Todos',
            'master.js.recompensas.filtro.pagar' => 'A pagar',
            'master.js.recompensas.filtro.lib' => 'Liberados',
            'master.js.recompensas.filtro.nao' => 'Ainda não liberados',
            'master.js.recompensas.filtro.priv' => '★ Condição individual',
            'master.js.recompensas.sit.liberado' => 'Liberado',
            'master.js.recompensas.sit.carencia' => '{n} em carência',
            'master.js.recompensas.sit.andamento' => 'Em andamento',
            'master.js.recompensas.priv_n' => '{n} com condição individual · não mudam com a regra geral',
            'master.js.recompensas.menu_condicao' => 'Condição individual (★)',
            'master.js.recompensas.menu_geral' => 'Voltar à regra geral',
            'master.recompensas.campo_valor' => 'RECOMPENSA POR ESCRITÓRIO (R$)',
            'master.recompensas.campo_libera' => 'LIBERA COM (ESCRITÓRIOS ATIVOS)',
            'master.recompensas.campo_carencia' => 'CARÊNCIA (DIAS DE USO ATIVO)',
            'master.recompensas.campo_alerta' => 'ALERTA ANTIFRAUDE (CONTAS EM 7 DIAS)',
            'master.recompensas.so_com_pasta' => 'Só conta escritório com pastas abertas',
            'master.recompensas.unico' => 'Pagamento único por escritório liberado · recorrente só para parceiros ★',
            'master.recompensas.indicadores' => 'Indicadores',
            'master.recompensas.busca' => 'Nome, e-mail ou escritório',
            'master.recompensas.vazio' => 'Nenhum indicador neste filtro.',
            'master.recompensas.col_usuario' => 'USUÁRIO',
            'master.recompensas.col_ativos' => 'ATIVOS P/ LIBERAR',
            'master.recompensas.col_por_conta' => 'POR CONTA',
            'master.recompensas.col_acoes' => 'AÇÕES',
            'master.recompensas.dialogo.titulo' => 'Condição individual do indicador',
            'master.recompensas.dialogo.recorrente' => 'Parceiro recorrente (crédito mensal)',
            'master.recompensas.dialogo.mensal' => 'POR MÊS, POR ESCRITÓRIO ATIVO (R$)',
            'master.recompensas.dialogo.salvar' => 'Salvar condição',
        ];
        foreach ($esperado as $chave => $texto) {
            self::assertSame($texto, self::catalogo($chave), $chave);
        }
    }

    #[TestDox('o painel registra a seção externa "recomp" logo depois de "ind", com o ícone bi-gift')]
    public function testSecaoNoPainel(): void
    {
        $js = self::arquivo('public/js/master/painel.js');
        $ind = strpos($js, "if (EXTERNAS.ind) SECS.push(['ind'");
        $recomp = strpos($js, "if (EXTERNAS.recomp) SECS.push(['recomp', T('master.js.secao.recomp'), 'bi-gift']);");
        $notif = strpos($js, 'if (EXTERNAS.notif)');

        self::assertNotFalse($ind);
        self::assertNotFalse($recomp);
        self::assertTrue($ind < $recomp && $recomp < $notif);
        self::assertStringContainsString(
            '<div class="mp-secao-externa" data-sec-externa="recomp" hidden>{% include \'master/_recompensas.html.twig\' ignore missing %}</div>',
            self::arquivo('templates/master/painel.html.twig'),
        );
    }

    #[TestDox('o JS fala com o servidor com o token no header, abre pelo evento mp:secao e pelo hash, e só tem as ações da F1')]
    public function testContratoDoJs(): void
    {
        $js = self::js();

        self::assertStringContainsString("'X-CSRF-Token': TOKEN", $js);
        self::assertStringContainsString("e.detail.sec === 'recomp'", $js);
        self::assertStringContainsString('/[#&]sec=recomp\\b/', $js);
        self::assertStringContainsString("pedir(urlDaCondicao(u.userId), 'DELETE')", $js);
        self::assertStringContainsString("pedir(raiz.dataset.urlRegras, 'POST', corpo)", $js);
        $codigo = substr($js, (int) strpos($js, '(() => {'));
        foreach (['Dar baixa', 'Simular indicação', 'Fechamento do mês', 'extrato'] as $fora) {
            self::assertStringNotContainsStringIgnoringCase($fora, $codigo, 'fica para as fatias seguintes: ' . $fora);
        }
        $twig = self::arquivo('templates/master/_recompensas.html.twig');
        self::assertSame(2, substr_count($twig, 'data-mrc-menu-acao='), 'o menu ⋮ só tem Condição individual e Voltar à regra geral');
        self::assertStringContainsString('data-sec-externa', self::arquivo('templates/master/painel.html.twig'));
    }

    #[TestDox('CSS com os valores do desenho: grade da lista, KPIs, janela e cores da chave e do menu')]
    public function testCssDoDesenho(): void
    {
        $css = self::arquivo('public/css/master-recompensas.css');

        self::assertStringContainsString('.mrc-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));', $css);
        self::assertStringContainsString('grid-template-columns: minmax(170px, 1.6fr) 64px 64px 116px 96px 96px 96px 104px 190px; gap: 8px; align-items: center; padding: 9px 18px;', $css);
        self::assertStringContainsString('.mrc-tabela { min-width: 1130px; }', $css);
        self::assertStringContainsString('.mrc-chave-trilho { width: 32px; height: 18px; border-radius: 9px;', $css);
        self::assertStringContainsString('.mrc-menu { position: fixed; z-index: 9601; width: 260px;', $css);
        self::assertStringContainsString('.mrc-dlg { pointer-events: auto; width: min(440px, 100%); max-height: 92vh;', $css);
        self::assertStringContainsString('.mrc-dlg-cabeca { flex: none; background: #0a6fa0; color: #fff; padding: 12px 16px;', $css);
        self::assertStringContainsString('--mrc-verde: #1e7b45;', $css);
        self::assertStringNotContainsString('modal-dialog', self::arquivo('templates/master/_recompensas.html.twig'), 'sem modal do Bootstrap');
    }

    #[TestDox('nenhum arquivo novo tem travessão (— ou –)')]
    public function testSemTravessao(): void
    {
        foreach ([
            'public/js/master/recompensas.js', 'public/css/master-recompensas.css', 'templates/master/_recompensas.html.twig',
            'src/Master/Controller/RecompensasDoMasterController.php', 'src/Recompensa/Entity/ProgramaDeRecompensa.php',
            'src/Recompensa/Entity/CondicaoDeRecompensa.php', 'src/Recompensa/DTO/AlterarRegrasDoProgramaInput.php',
            'src/Recompensa/DTO/DefinirCondicaoIndividualInput.php', 'src/Recompensa/UseCase/ConsultarPainelDeRecompensasUseCase.php',
        ] as $caminho) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', self::arquivo($caminho), $caminho);
        }
    }
}
