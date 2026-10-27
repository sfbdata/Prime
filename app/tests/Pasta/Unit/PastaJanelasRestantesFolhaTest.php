<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote S6-JS do Ciclo 6, lido como FOLHA (não há navegador no PHPUnit): o que se prova é o
 * contrato do código-fonte. O efeito na tela (o X no canto, o arraste, a janela no lugar do
 * `confirm`) é smoke do dono.
 *
 * - PAS-022r: o X de fechar também no menu do Registro, no ⋮ da observação e na lista do
 *   responsável — o mesmo `botaoFechar` dos popovers, fechando como o Esc.
 * - PAS-023r: o arraste (`PsArrastar`) também nos menus sem tarja, nos do Registro e da
 *   observação, na lista do responsável, no drawer do Histórico e no painel da Timeline.
 * - PAS-034b: Restaurar, "Número não confere", Favoritos e "Descartar alterações" pela janela e
 *   pelo aviso da tela (`confirmarPs`/`avisarPs`), não pelo `confirm`/`alert` do navegador.
 * - C6-PAS-09 / C6-EDT-09: os dois comentários do cabeçalho que mentiam.
 */
#[CoversNothing]
final class PastaJanelasRestantesFolhaTest extends TestCase
{
    private const CSS       = __DIR__ . '/../../../public/css/pasta-show.css';
    private const PASTA_JS  = __DIR__ . '/../../../public/js/pasta-show.js';
    private const DIALOGOS  = __DIR__ . '/../../../public/js/pasta-dialogos.js';
    private const CABECALHO = __DIR__ . '/../../../templates/pasta/_cabecalho.html.twig';
    private const SHOW      = __DIR__ . '/../../../templates/pasta/show.html.twig';
    private const ANOTACOES = __DIR__ . '/../../../templates/pasta/_dados_anotacoes.html.twig';
    private const OBS       = __DIR__ . '/../../../templates/pasta/_detalhes_obs.html.twig';
    private const RESP_MENU = __DIR__ . '/../../../templates/pasta/_resp_menu.html.twig';
    private const RESP_JS   = __DIR__ . '/../../../templates/pasta/_resp_script.html.twig';

    private static function ler(string $arquivo): string
    {
        $conteudo = file_get_contents($arquivo);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    private static function trecho(string $fonte, string $inicio, string $fim): string
    {
        $a = strpos($fonte, $inicio);
        self::assertNotFalse($a, "marcador {$inicio} existe");
        $b = strpos($fonte, $fim, $a + strlen($inicio));
        self::assertNotFalse($b, "marcador {$fim} existe depois de {$inicio}");

        return substr($fonte, $a, $b - $a);
    }

    #[TestDox('PAS-022r: os três menus que não são .ps-pop ganham o X do botaoFechar na carga e a cada abertura, e o X fecha como o Esc')]
    public function testXNosMenusQueNaoSaoPopover(): void
    {
        $js = self::ler(self::PASTA_JS);

        // Ligado no boot, depois dos popovers.
        $boot = self::trecho($js, "document.addEventListener('DOMContentLoaded', function () {", '/* ── 1. Indicador das abas');
        self::assertGreaterThan(strpos($boot, 'popovers();'), strpos($boot, 'janelasSemPopover();'));

        self::assertStringContainsString("var MENUS_COM_X = '#psRegMenu, .ps-obs-menu, #pastaRespMenu';", $js);
        $bloco = self::trecho($js, 'function fecharComoEsc() {', '/* Onde o arraste vale nesta tela');
        self::assertStringContainsString("document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));", $bloco);
        self::assertStringContainsString('document.querySelectorAll(MENUS_COM_X).forEach(function (menu) { botaoFechar(menu, fecharComoEsc); });', $bloco, 'os menus da carga');
        // O ⋮ da observação também nasce pelo JS (observação nova): o X entra na abertura.
        self::assertStringContainsString("attributeFilter: ['hidden', 'aria-hidden']", $bloco);
        self::assertStringContainsString('attributeOldValue: true', $bloco);
        self::assertStringContainsString('if (abriu && janela.matches(MENUS_COM_X)) { botaoFechar(janela, fecharComoEsc); }', $bloco);

        // A faixa da busca do responsável é a tarja dele: X branco ali.
        $x = self::trecho($js, 'function botaoFechar(', 'window.PsPopovers = {');
        self::assertStringContainsString("if (!tarja) { tarja = painel.querySelector(':scope > .pasta-resp-busca-wrap'); }", $x);
        self::assertStringContainsString('(tarja || painel).appendChild(botao);', $x);

        // O contrato do "fecha como o Esc": cada script fecha o SEU menu no Esc do document.
        self::assertStringContainsString("if (regAberto && e.key === 'Escape') { fecharMenuReg(true); }", self::ler(self::SHOW));
        self::assertStringContainsString("if (menuAberto && e.key === 'Escape') { fecharMenuObs(true); }", self::ler(self::OBS));
        self::assertStringContainsString("if (e.key === 'Escape') { fecharMenu(); return; }", self::ler(self::RESP_JS));
    }

    #[TestDox('PAS-022r: o X tem onde morar — o título é o 1º filho dos menus do Registro e da observação (os dois moldes), a busca é o 1º filho do menu do responsável')]
    public function testHospedeirosDoX(): void
    {
        self::assertMatchesRegularExpression(
            '/<div class="ps-reg-menu" id="psRegMenu" role="menu" hidden>\s*<div class="ps-processo-menu-titulo js-reg-menu-titulo">/',
            self::ler(self::ANOTACOES),
        );
        $obs = self::ler(self::OBS);
        self::assertMatchesRegularExpression('/<div class="ps-obs-menu" id="psObsMenu\$\{data\.id\}" role="menu" hidden>\s*<div class="ps-processo-menu-titulo">/', $obs, 'o molde do JS');
        self::assertMatchesRegularExpression('/<div class="ps-obs-menu" id="psObsMenu\{\{ obs\.id \}\}" role="menu" hidden>\s*<div class="ps-processo-menu-titulo">/', $obs, 'o do servidor');
        self::assertMatchesRegularExpression(
            '/<div class="pasta-resp-menu" id="pastaRespMenu" hidden(\{% if not naPasta %\} role="dialog" aria-label="\{\{ \'pasta_resp\.resp\.escolher\'\|trans \}\}"\{% endif %\})?>\s*(\{#.*?#\}\s*)?<div class="pasta-resp-busca-wrap">/s',
            self::ler(self::RESP_MENU),
        );

        $css = self::ler(self::CSS);
        self::assertStringContainsString(".ps-reg-menu,\n.ps-obs-menu {\n    --ps-pop-x-bg:", $css, 'o X cinza tem as cores');
        self::assertStringContainsString("[data-bs-theme=\"dark\"] .ps-reg-menu,\n[data-bs-theme=\"dark\"] .ps-obs-menu {\n    --ps-pop-x-bg:", $css, 'par escuro');
        self::assertStringContainsString('.ps-obs-menu:has(> .ps-pop-x) > .ps-processo-menu-titulo { padding: 10px 40px 9px 10px; }', $css, 'o título abre espaço para o X');
        self::assertStringContainsString('.ps-body .pasta-resp-busca-wrap:has(> .ps-pop-x) { padding-right: 46px; }', $css, 'o campo não fica por baixo do X');
    }

    #[TestDox('PAS-023r: PsArrastar nas alças novas — menu sem tarja (fundo e título de seção), Registro, observação, responsável, Histórico e Timeline; reabre na âncora')]
    public function testArrasteNosAlvosNovos(): void
    {
        $js = self::ler(self::PASTA_JS);

        $util = self::trecho($js, 'window.PsArrastar = (function () {', 'return { ligar: ligar, zerar: zerar };');
        self::assertStringContainsString('if (opcoes.aceita && !opcoes.aceita(e, cab)) { return; }', $util);

        $uso = self::trecho($js, 'function arrasteDasJanelas()', '/* ── 4. Atalhos do trilho');
        self::assertStringContainsString("cabecalho: '.ps-pop.is-aberto',", $uso);
        self::assertStringContainsString("if (painel.querySelector(':scope > .ps-pop-tarja')) { return false; }", $uso, 'com tarja, a alça é a tarja (regra de antes)');
        // Só o 1º filho (título de seção) arrasta, como o desenho; o fundo do painel não.
        self::assertStringContainsString("return e.target === primeiro && primeiro.classList.contains('ps-pop-secao');", $uso);
        self::assertStringNotContainsString('e.target === painel', $uso);
        self::assertStringContainsString("primeiro.classList.contains('ps-pop-secao')", $uso);
        self::assertStringContainsString("cabecalho: '#psRegMenu:not([hidden]) > .ps-processo-menu-titulo, .ps-obs-menu:not([hidden]) > .ps-processo-menu-titulo'", $uso);
        self::assertStringContainsString("cabecalho: '#pastaRespMenu:not([hidden]) > .pasta-resp-busca-wrap'", $uso);
        self::assertStringContainsString("cabecalho: '.ps-drawer.is-aberto > .ps-drawer-cab, .ps-tl.is-aberto > .ps-tl-cab'", $uso);
        // A regra antiga continua de pé.
        self::assertStringContainsString("cabecalho: '.ps-pop.is-aberto > .ps-pop-tarja'", $uso);

        // Reabre junto do gatilho: a abertura observada zera o deslocamento das cinco janelas.
        self::assertStringContainsString("var JANELAS_OBSERVADAS = '#psRegMenu, .ps-obs-menu, #pastaRespMenu, #psHistorico, #psTimeline';", $js);
        $obs = self::trecho($js, 'function janelasSemPopover() {', '/* Onde o arraste vale nesta tela');
        self::assertStringContainsString('var abriu = m.oldValue !== null && !janela.hasAttribute(m.attributeName);', $obs);
        self::assertStringContainsString('var fechou = m.oldValue === null && janela.hasAttribute(m.attributeName);', $obs);
        self::assertStringContainsString('if (!abriu && !fechou) { return; }', $obs, 'zera também ao fechar');
        self::assertStringContainsString('window.PsArrastar.zerar(janela);', $obs);

        $css = self::ler(self::CSS);
        self::assertStringContainsString('.ps-pop:not(:has(> .ps-pop-tarja)) > .ps-pop-secao:first-child { cursor: grab; touch-action: none; user-select: none; }', $css);
        self::assertStringNotContainsString('.ps-pop:not(:has(> .ps-pop-tarja)) { cursor: grab; }', $css, 'o fundo do painel não é alça');
        self::assertStringContainsString('.ps-body .pasta-resp-busca-wrap { cursor: grab; touch-action: none; }', $css);
    }

    #[TestDox('PAS-034b: Restaurar, "Número não confere" e Favoritos pela janela e pelo aviso da tela; nenhum confirm/alert nativo nesses trechos')]
    public function testCabecalhoSemConfirmNemAlert(): void
    {
        $cab = self::ler(self::CABECALHO);
        $catalogo = CatalogoPtBr::carregar()['pasta_cabecalho'];

        // Restaurar: o form não pergunta no onsubmit; o script segura e pergunta na janela.
        $form = self::trecho($cab, "<form method=\"post\" action=\"{{ path('pasta_restaurar'", '</form>');
        self::assertStringNotContainsString('onsubmit', $form);
        self::assertStringContainsString('class="js-restaurar-pasta" data-nup="{{ pasta.nup }}"', $form);
        $restaurar = self::trecho($cab, "var form = document.querySelector('form.js-restaurar-pasta');", '})();');
        self::assertStringContainsString('e.preventDefault();', $restaurar);
        self::assertStringContainsString('window.confirmarPs({', $restaurar);
        self::assertStringContainsString("titulo: BJ_T.t('pasta_cabecalho.js.cabecalho.restaurar_pergunta', { nup: form.dataset.nup }),", $restaurar);
        self::assertSame('Restaurar a pasta {nup}?', $catalogo['js']['cabecalho']['restaurar_pergunta']);
        self::assertStringContainsString('if (sim) { form.submit(); }', $restaurar);
        self::assertStringNotContainsString('confirm(', str_replace('confirmarPs(', '', $restaurar));

        // Excluir: o prompt fica (é o do desenho); o número errado vira o aviso da tela.
        $excluir = self::trecho($cab, 'class="js-excluir-pasta"', '</form>');
        self::assertStringContainsString("window.avisarPs('{{ 'pasta_cabecalho.cabecalho.menu.excluir_nao_confere'|trans({nup: pasta.nup})|e('js') }}');", $excluir);
        self::assertSame('Número não confere. Digite {nup} (o NUP da pasta, não o número da URL). A pasta não foi excluída.', $catalogo['cabecalho']['menu']['excluir_nao_confere']);
        self::assertStringContainsString("'{{ 'pasta_cabecalho.cabecalho.menu.excluir_sem_dialogo'|trans|e('js') }}'", $excluir, 'prompt silenciado pelo navegador avisa em vez de calar');
        self::assertStringNotContainsString('alert(', $excluir);
        self::assertStringContainsString('window.prompt(', $excluir, 'o prompt é o do desenho (dc L.4335) e fica');

        // Favoritos: as duas falhas pelo aviso.
        $fav = self::trecho($cab, "var item = document.querySelector('.js-pasta-favorito');", '})();');
        self::assertStringContainsString("if (window.avisarPs) { window.avisarPs(mensagem, 'erro'); }", $fav);
        self::assertStringContainsString("avisarErro((r.dados && (r.dados.erro || r.dados.mensagem)) || BJ_T.t('pasta_cabecalho.js.cabecalho.favoritos_erro'));", $fav);
        self::assertStringContainsString(".catch(function () { avisarErro(BJ_T.t('pasta_cabecalho.js.cabecalho.erro_comunicacao')); })", $fav);
        self::assertSame('Não foi possível alterar os favoritos.', $catalogo['js']['cabecalho']['favoritos_erro']);
        self::assertSame('Erro de comunicação. Tente novamente.', $catalogo['js']['cabecalho']['erro_comunicacao']);
        self::assertStringNotContainsString('alert(', $fav);

        // O Duplicar era o confirm do desenho de 05/10 (L.4324); o de 13/10 o passou para a janela
        // azul (`confirmarUI`, L.4511) — E7: `data-ps-cf`, com o confirm só de reserva.
        self::assertStringContainsString('class="js-duplicar-pasta" data-nup="{{ pasta.nup }}"', $cab);
        self::assertStringContainsString("data-ps-cf=\"{{ 'pasta_cabecalho.cabecalho.menu.duplicar_confirmar'|trans({nup: pasta.nup}) }}\"", $cab);
        self::assertStringStartsWith('Duplicar a pasta {nup}? A cópia leva', $catalogo['cabecalho']['menu']['duplicar_confirmar']);
        self::assertStringContainsString('onsubmit="return !!window.confirmarUIPs || window.confirm(this.dataset.psCf);"', $cab);
    }

    #[TestDox('PAS-034b: "Descartar alterações" do Editar dados pela janela da tela, e a janela mora no modal aberto enquanto pergunta (focus trap do Bootstrap)')]
    public function testDescartarAlteracoesPelaJanela(): void
    {
        $show = self::ler(self::SHOW);
        $bloco = self::trecho($show, "var gatilho = e.target.closest('.js-pe-ir');", '}());');
        self::assertStringNotContainsString('window.confirm(', $bloco);
        self::assertStringContainsString("titulo: BJ_T.t('pasta_show.js.editar.descartar_titulo'),", $bloco);
        self::assertStringContainsString("rotulo: BJ_T.t('pasta_show.js.editar.descartar'),", $bloco);
        $editar = CatalogoPtBr::carregar()['pasta_show']['js']['editar'];
        self::assertSame('Descartar as alterações?', $editar['descartar_titulo']);
        self::assertSame('Descartar', $editar['descartar']);
        // Com o "sim": desfaz, reconfere e só então segue para o destino.
        $sim = self::trecho($bloco, '}).then(function (sim) {', '});');
        self::assertLessThan(strpos($sim, 'seguir();'), strpos($sim, 'form.reset();'));
        self::assertStringContainsString('if (serializar() === foto) { seguir(); return; }', $bloco, 'sem alteração, nem pergunta');

        $dlg = self::ler(self::DIALOGOS);
        $hospedar = self::trecho($dlg, 'function hospedarNoModalAberto(fundo) {', 'function confirmarPs(opcoes) {');
        self::assertStringContainsString("var modal = document.querySelector('.modal.show:not([data-bs-focus=\"false\"])');", $hospedar, 'a Nova meta (sem trava, pointer-events: none) não hospeda a janela');
        self::assertStringContainsString('modal.appendChild(fundo);', $hospedar);
        self::assertStringContainsString('var lugar = hospedarNoModalAberto(fundo);', self::trecho($dlg, 'function confirmarPs(opcoes) {', 'function encerrarConfirmacao('));
        self::assertStringContainsString(
            'if (proximo && proximo.parentNode === aberta.lugar.pai) { aberta.lugar.pai.insertBefore(fundo, proximo); }',
            self::trecho($dlg, 'function encerrarConfirmacao(', 'function ligarConfirmacao('),
            'volta ao lugar ao fechar',
        );
    }

    #[TestDox('C6-PAS-09 / C6-EDT-09: o comentário das abas não promete o "0" e o mostraZero morto saiu; o do Imprimir resumo fala da janela e do PDF do servidor')]
    public function testComentariosDoCabecalho(): void
    {
        $cab = self::ler(self::CABECALHO);

        self::assertStringNotContainsString('mostraZero', $cab);
        self::assertStringNotContainsString('INCLUSIVE o 0', $cab);
        self::assertStringContainsString('{% if aba.contagem %}<span class="ps-aba-badge ps-num">', $cab, 'o selo continua só com contagem > 0');

        self::assertStringNotContainsString('"Baixar PDF" é o "Salvar como PDF" do navegador', $cab);
        $imprimir = self::trecho($cab, '{# O clique abre a janela Imprimir', "path('pasta_resumo_imprimir'");
        self::assertStringContainsString('Dompdf', $imprimir);
        self::assertStringContainsString('pasta-impressao.js', $imprimir);
    }
}
