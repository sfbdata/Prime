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
            'master.js.recompensas.menu_geral' => 'Usar a regra geral',
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
            'master.js.recompensas.menu_lancamento' => 'Lançar crédito ou estorno',
            'master.js.recompensas.menu_extrato' => 'Ver extrato deste indicador',
            'master.js.recompensas.menu_csv' => 'Baixar extrato (CSV)',
            'master.js.recompensas.tipo.credito' => 'Crédito manual',
            'master.js.recompensas.tipo.estorno' => 'Estorno',
            'master.js.recompensas.extrato_todos' => 'Mostrar todos',
            'master.js.recompensas.erro_valor' => 'Informe o valor do crédito (use negativo para estornar).',
            'master.recompensas.extrato.titulo' => 'Extrato de pagamentos e liberações',
            'master.recompensas.extrato.exportar' => 'Exportar CSV',
            'master.recompensas.extrato.vazio' => 'Nenhum pagamento ou liberação ainda.',
            'master.recompensas.lancamento.titulo' => 'Lançar crédito ou estorno',
            'master.recompensas.lancamento.valor' => 'Valor (R$)',
            'master.recompensas.lancamento.dica' => 'Use valor negativo para estornar',
            'master.recompensas.lancamento.observacao' => 'Observação (opcional)',
            'master.recompensas.lancamento.lancar' => 'Lançar',
        ];
        foreach ($esperado as $chave => $texto) {
            self::assertSame($texto, self::catalogo($chave), $chave);
        }
    }

    #[TestDox('F3: textos do antifraude no catálogo, como no desenho (situações, menu, confirmação, diálogo APAGAR)')]
    public function testTextosDoAntifraude(): void
    {
        $esperado = [
            'master.js.recompensas.sit.bloqueado' => 'Bloqueado',
            'master.js.recompensas.sit.analise_uma' => 'Em análise ({n} suspeita)',
            'master.js.recompensas.sit.analise_varias' => 'Em análise ({n} suspeitas)',
            'master.js.recompensas.sit.alerta' => 'Alerta: {n} contas em 7 dias',
            'master.js.recompensas.menu_aprovar' => 'Revisar suspeitas e aprovar',
            'master.js.recompensas.menu_bloquear' => 'Bloquear recompensa',
            'master.js.recompensas.menu_desbloquear' => 'Desbloquear recompensa',
            'master.js.recompensas.menu_apagar' => 'Apagar da lista',
            'master.js.recompensas.aprovar_pergunta' => 'Aprovar e contar estas contas?',
            'master.js.recompensas.erro_apagar' => 'Digite APAGAR para confirmar.',
            'master.js.recompensas.apagado' => '{nome} foi removido da lista. O extrato continua guardado.',
            'master.js.recompensas.motivo.escritorio' => 'mesmo escritório de quem indicou',
            'master.js.recompensas.motivo.cnpj' => 'mesmo CNPJ',
            'master.js.recompensas.motivo.dominio' => 'mesmo domínio de e-mail',
            'master.recompensas.suspeitas_mudou' => 'A lista de suspeitas mudou. Recarregue o painel e revise de novo.',
            'master.recompensas.apagar.titulo' => 'Apagar indicador da lista',
            'master.recompensas.apagar.rotulo' => 'Digite APAGAR para confirmar',
            'master.recompensas.apagar.dica' => 'Ele sai da lista de recompensas; o extrato continua guardado.',
            'master.recompensas.apagar.apagar' => 'Apagar',
        ];
        foreach ($esperado as $chave => $texto) {
            self::assertSame($texto, self::catalogo($chave), $chave);
        }
        preg_match_all("/\\bT\\('([a-z][a-z0-9_.]+)'/", self::js(), $js);
        preg_match_all("/'(master\\.recompensas\\.apagar\\.[a-z0-9_]+)'\\s*\\|\\s*trans/", self::arquivo('templates/master/_recompensas.html.twig'), $twig);
        foreach (array_unique([...$js[1], ...$twig[1]]) as $chave) {
            if (!str_ends_with($chave, '.')) {
                self::catalogo($chave);
            }
        }
        self::assertStringContainsString("T('master.js.recompensas.motivo.' + m)", self::js());
    }

    #[TestDox('F3: o JS manda o corpo certo às três rotas, confirma antes de aprovar, trava duplo clique e não decide regra no navegador')]
    public function testAntifraudeNoJs(): void
    {
        $js = self::js();

        self::assertStringContainsString("pedir(urlDoUsuario(raiz.dataset.urlAprovar, id), 'POST', { vistas: u.suspeitas })", $js);
        self::assertStringContainsString("pedir(urlDoUsuario(raiz.dataset.urlBloqueio, id), 'POST', { bloqueado: !u.bloqueado })", $js);
        self::assertStringContainsString("pedir(urlDoUsuario(raiz.dataset.urlApagar, u.userId), 'POST', { confirmacao: palavra })", $js);
        self::assertStringContainsString('if (!window.confirm(texto)) return;', $js);
        self::assertStringContainsString("palavra.toUpperCase() !== 'APAGAR'", $js);
        self::assertStringContainsString('apg.enviar.disabled = true;', $js);
        self::assertStringContainsString('pagar: u => u.liberado && u.saldoCentavos > 0 && !u.bloqueado,', $js);
        self::assertStringContainsString("menu.caixa.querySelector('[data-mrc-menu-acao=\"aprovar\"]').hidden = u.suspeitas === 0;", $js);
        self::assertStringContainsString("u.situacao === 'analise'", $js);
        self::assertStringNotContainsString('u.email', substr($js, (int) strpos($js, 'function dicaDasSuspeitas'), 300), 'o title das suspeitas não traz e-mail');
        $twig = self::arquivo('templates/master/_recompensas.html.twig');
        foreach (['master_recompensas_suspeitas_aprovar', 'master_recompensas_bloqueio', 'master_recompensas_apagar'] as $rota) {
            self::assertStringContainsString("path('" . $rota . "', {id: 0})", $twig);
        }
        self::assertCount(0, array_filter(['modal-dialog', 'select2', 'class="box"'], static fn (string $p): bool => str_contains($twig, $p)));
    }

    #[TestDox('F3: cores do desenho: alerta em vermelho, menu âmbar (suspeitas e bloqueio) e vermelho (apagar), botão Apagar #a3232b')]
    public function testCoresDoAntifraude(): void
    {
        $css = self::arquivo('public/css/master-recompensas.css');

        self::assertStringContainsString('.mrc-sit.mrc-sit-alerta { color: var(--mrc-vermelho); }', $css);
        self::assertStringContainsString('.mrc-menu-item.mrc-menu-item-aviso { color: var(--mrc-ambar); }', $css);
        self::assertStringContainsString('.mrc-menu-item.mrc-menu-item-perigo { color: var(--mrc-vermelho); }', $css);
        self::assertStringContainsString('.mrc-dlg-salvar.mrc-dlg-perigo { background: #a3232b; }', $css);
        self::assertStringContainsString('--mrc-ambar: #9a5b00;', $css);
        self::assertStringContainsString('--mrc-vermelho: #a3232b;', $css);
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

    #[TestDox('o JS fala com o servidor com o token no header, abre pelo evento mp:secao e pelo hash, e só tem as ações das fatias entregues')]
    public function testContratoDoJs(): void
    {
        $js = self::js();

        self::assertStringContainsString("'X-CSRF-Token': TOKEN", $js);
        self::assertStringContainsString("e.detail.sec === 'recomp'", $js);
        self::assertStringContainsString('/[#&]sec=recomp\\b/', $js);
        self::assertStringContainsString("pedir(urlDaCondicao(u.userId), 'DELETE')", $js);
        self::assertStringContainsString("pedir(raiz.dataset.urlRegras, 'POST', corpo)", $js);
        $codigo = substr($js, (int) strpos($js, '(() => {'));
        foreach (['Simular indicação', 'Fechamento do mês', 'Avisar saldo'] as $fora) {
            self::assertStringNotContainsStringIgnoringCase($fora, $codigo, 'fica para as fatias seguintes: ' . $fora);
        }
        $twig = self::arquivo('templates/master/_recompensas.html.twig');
        self::assertSame(11, substr_count($twig, 'data-mrc-menu-acao='), 'o menu ⋮ tem condição, dar baixa, lançamento, dados para pagamento, copiar e-mail, revisar suspeitas, extrato, CSV, regra geral, bloqueio e apagar');
        self::assertStringContainsString('data-sec-externa', self::arquivo('templates/master/painel.html.twig'));
    }

    #[TestDox('F2: o diálogo gera um UUID ao abrir, manda no lançamento, bloqueia o duplo clique e não calcula saldo nem regra no navegador')]
    public function testLancamentoNoJs(): void
    {
        $js = self::js();

        self::assertStringContainsString('chave = novaChave();', $js);
        self::assertStringContainsString('window.crypto.randomUUID()', $js);
        self::assertStringContainsString("'POST', { valor, observacao: lan.obs.value.trim(), chave })", $js);
        self::assertMatchesRegularExpression('/function lancar\(\) \{\s+const u = indicador\(alvo\);\s+if \(!u \|\| ocupado\) return;/', $js);
        self::assertStringContainsString("lan.enviar.disabled = true;", $js);
        self::assertStringContainsString("pedir(urlDoUsuario(raiz.dataset.urlLancamento, u.userId), 'POST'", $js);
        self::assertStringContainsString("raiz.dataset.urlExtrato + (extratoDe === null ? '' : '?usuario=' + extratoDe)", $js);
        self::assertStringContainsString("window.location.assign(raiz.dataset.urlCsv + '?usuario=' + id)", $js);
        self::assertStringNotContainsString('localStorage', $js);
        self::assertStringNotContainsString('innerHTML = ' . "u.", $js);
    }

    #[TestDox('F2 e F4: extrato e diálogo seguem o desenho: grade de 5 colunas (forma e comprovante na F4), chip, botão Exportar CSV, ícones e sem modal do Bootstrap')]
    public function testExtratoNoDesenho(): void
    {
        $css = self::arquivo('public/css/master-recompensas.css');
        $twig = self::arquivo('templates/master/_recompensas.html.twig');

        self::assertStringContainsString('.mrc-extrato-linha { display: grid; grid-template-columns: 150px minmax(200px, 1fr) 150px 110px 150px; gap: 8px; align-items: center; padding: 9px 18px;', $css);
        self::assertStringContainsString('.mrc-chip { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 9px; border-radius: 2px;', $css);
        self::assertStringContainsString('class="bi bi-receipt"', $twig);
        self::assertStringContainsString('bi-filetype-csv', $twig);
        self::assertStringContainsString('bi bi-plus-slash-minus', $twig);
        self::assertStringContainsString('<textarea rows="3" maxlength="255" data-mrc-lan-obs>', $twig);
        self::assertStringNotContainsString('modal-dialog', $twig);
        self::assertStringNotContainsString('Avisar', $twig, 'os botões Avisar entram na F7');
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
            'src/Recompensa/Entity/LancamentoDeRecompensa.php', 'src/Recompensa/UseCase/LancarCreditoOuEstornoUseCase.php',
            'src/Recompensa/UseCase/ConsultarExtratoDeRecompensasUseCase.php', 'src/Recompensa/DTO/ExtratoEmCsv.php',
            'src/Recompensa/DTO/LancarCreditoOuEstornoInput.php', 'migrations/Version20261026152000.php', 'migrations/Version20261026152100.php',
            'src/Recompensa/Service/SuspeitaDeConta.php', 'src/Recompensa/Entity/AprovacaoDeContaDeRecompensa.php',
            'src/Recompensa/UseCase/AprovarSuspeitasDoIndicadorUseCase.php', 'src/Recompensa/UseCase/BloquearOuDesbloquearRecompensaUseCase.php',
            'src/Recompensa/UseCase/ApagarIndicadorDaListaUseCase.php', 'src/Recompensa/DTO/ApagarIndicadorDaListaInput.php',
            'src/Recompensa/DTO/BloquearOuDesbloquearRecompensaInput.php', 'src/Recompensa/DTO/IndicadorDeRecompensaOutput.php',
        ] as $caminho) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', self::arquivo($caminho), $caminho);
        }
    }
}
