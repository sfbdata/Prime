<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L7 (cabeçalho, CSS da pasta e aba Processo), lido como FOLHA: não há navegador no
 * PHPUnit, então o que se prova é o contrato do código-fonte com o desenho 1.2.3. O efeito na
 * tela (posição, cor, tempo) é smoke do dono.
 */
#[CoversNothing]
final class PastaCabecalhoL7FolhaTest extends TestCase
{
    private const CSS        = __DIR__ . '/../../../public/css/pasta-show.css';
    private const PASTA_JS   = __DIR__ . '/../../../public/js/pasta-show.js';
    private const CABECALHO  = __DIR__ . '/../../../templates/pasta/_cabecalho.html.twig';

    private static function ler(string $arquivo): string
    {
        $conteudo = file_get_contents($arquivo);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    /** Trecho da fonte entre dois marcadores (o segundo é opcional: sem ele, vai até o fim). */
    private static function trecho(string $fonte, string $inicio, ?string $fim = null): string
    {
        $a = strpos($fonte, $inicio);
        self::assertNotFalse($a, "marcador {$inicio} existe");
        if ($fim === null) {
            return substr($fonte, $a);
        }
        $b = strpos($fonte, $fim, $a + strlen($inicio));
        self::assertNotFalse($b, "marcador {$fim} existe depois de {$inicio}");

        return substr($fonte, $a, $b - $a);
    }

    /** Corpo da regra CSS cujo seletor começa a linha exatamente como `$seletor {`. */
    private static function regra(string $css, string $seletor): string
    {
        $ok = preg_match('/^' . preg_quote($seletor, '/') . ' \{([^}]*)\}/m', $css, $m);
        self::assertSame(1, $ok, "regra {$seletor} existe");

        return $m[1];
    }

    #[TestDox('EDT-007: "Copiar link" troca bi-link por bi-check2 só quando copiou, e fecha em 900 ms')]
    public function testCopiarLinkTrocaIconeEFechaEm900ms(): void
    {
        $bloco = self::trecho(self::ler(self::PASTA_JS), '"Copiar link": real', 'Atalhos do menu');

        self::assertStringContainsString("classList.replace('bi-link', 'bi-check2')", $bloco);
        self::assertStringContainsString("classList.replace('bi-check2', 'bi-link')", $bloco, 'o ícone volta ao fechar');
        self::assertStringContainsString('}, 900);', $bloco);
        self::assertStringNotContainsString('1600', $bloco);
        self::assertStringContainsString("avisar('Link copiado', true)", $bloco);
        self::assertStringContainsString("avisar('Não foi possível copiar', false)", $bloco, 'na falha não há check');
        self::assertStringContainsString('if (iconeCopiar && copiou)', $bloco);

        self::assertStringContainsString('<i class="bi bi-link"></i><span class="js-copiar-link-rotulo">', self::ler(self::CABECALHO));
    }

    #[TestDox('PAS-024: o painel da Timeline tem quinas de 4px (README de Quinas), não os 6px do JS do desenho')]
    public function testQuinasDaTimelineEm4px(): void
    {
        $painel = self::regra(self::ler(self::CSS), '.ps-tl');

        self::assertStringContainsString('border-radius: 4px;', $painel);
        self::assertStringNotContainsString('border-radius: 6px', $painel);
    }

    #[TestDox('PAS-049i: a Timeline não escurece a tela, mas o overlay segue cobrindo a página para o clique fora fechar')]
    public function testTimelineSemFundoEscurecido(): void
    {
        $overlay = self::regra(self::ler(self::CSS), '.ps-tl-overlay');

        self::assertStringContainsString('background: transparent;', $overlay);
        self::assertStringNotContainsString('rgba(', $overlay);
        self::assertStringContainsString('position: fixed;', $overlay, 'continua cobrindo a página');
        self::assertStringContainsString('inset: 0;', $overlay);

        $js = self::ler(__DIR__ . '/../../../public/js/pasta-timeline.js');
        self::assertStringContainsString("overlay.addEventListener('click', fecharPainel)", $js, 'o clique fora fecha');
    }

    #[TestDox('PMC-025: abaixo de 768px, Vincular processo, Peticionar na pasta e Nova meta mantêm o rótulo e o cabeçalho quebra a linha')]
    public function testRotulosDosBotoesDoCartaoNoCelular(): void
    {
        $css   = self::ler(self::CSS);
        $movel = self::trecho($css, "   Responsivo\n", '   Peças herdadas que mudaram de lugar');

        self::assertStringContainsString('.ps-btn span { display: none; }', $movel, 'a regra geral continua');
        self::assertMatchesRegularExpression(
            '/\.ps-processos > \.ps-card-cab \.ps-btn span,\s*\.ps-metas > \.ps-card-cab \.ps-btn span \{ display: inline; \}/',
            $movel,
        );
        self::assertStringContainsString('flex-wrap: wrap;', self::regra($css, '.ps-processos > .ps-card-cab--painel'));
        self::assertStringContainsString('flex-wrap: wrap;', self::regra($css, '.ps-metas > .ps-card-cab'));

        // Os três botões ainda são .ps-btn com o rótulo num <span> filho, dentro do cabeçalho do cartão.
        $proc = self::ler(__DIR__ . '/../../../templates/pasta/_processos_vinculados.html.twig');
        self::assertStringContainsString("<i class=\"bi bi-link-45deg\"></i><span>{{ 'pasta_processo.cabecalho.vincular'|trans }}</span>", $proc);
        self::assertStringContainsString("<i class=\"bi bi-file-earmark-text\"></i><span>{{ 'pasta_processo.cabecalho.peticionar'|trans }}</span>", $proc);
        $catalogo = CatalogoPtBr::carregar();
        self::assertSame('Vincular processo', $catalogo['pasta_processo']['cabecalho']['vincular']);
        self::assertSame('Peticionar na pasta', $catalogo['pasta_processo']['cabecalho']['peticionar']);
        $metas = self::ler(__DIR__ . '/../../../templates/pasta/_metas.html.twig');
        self::assertStringContainsString("<i class=\"bi bi-plus-lg\"></i><span>{{ 'pasta_documentos.metas.nova'|trans }}</span>", $metas);
        self::assertSame('Nova meta', $catalogo['pasta_documentos']['metas']['nova']);
    }

    #[TestDox('PMC-026: o interruptor Administrativo saiu do <style> do parcial e usa tokens com par escuro')]
    public function testInterruptorAdministrativoComTokens(): void
    {
        $proc = self::ler(__DIR__ . '/../../../templates/pasta/_processos_vinculados.html.twig');
        self::assertStringNotContainsString('<style>', $proc, 'sem CSS inline no parcial');

        $css = self::ler(self::CSS);
        self::assertStringContainsString('var(--ps-adm-rotulo-on)', self::regra($css, '.ps-adm-sw.is-ligado .ps-adm-sw-rotulo'));
        self::assertStringContainsString('var(--ps-adm-trilho-on)', self::regra($css, '.ps-adm-sw.is-ligado .ps-adm-sw-trilho'));
        self::assertStringContainsString('var(--ps-adm-trilho)', self::regra($css, '.ps-adm-sw-trilho'));

        // Cada token de cor existe no claro e no escuro.
        $bloco  = self::trecho($css, 'PMC-026: interruptor', '.ps-adm-form {');
        $claro  = self::trecho($bloco, '.ps-page {', '[data-bs-theme="dark"] .ps-page {');
        $escuro = self::trecho($bloco, '[data-bs-theme="dark"] .ps-page {');
        foreach (['--ps-adm-rotulo-on:     #b4460a', '--ps-adm-trilho:        #c5d0d8', '--ps-adm-trilho-on:     #e8873a'] as $token) {
            self::assertStringContainsString($token, $claro, 'o claro é o do desenho');
        }
        foreach (['--ps-adm-rotulo-on:', '--ps-adm-trilho:', '--ps-adm-trilho-on:', '--ps-adm-bola:'] as $token) {
            self::assertStringContainsString($token, $escuro, "{$token} tem par escuro");
        }
        self::assertStringNotContainsString('#b4460a', $escuro);
    }

    #[TestDox('PAS-002 (E12d): pasta ativa ganha o carimbo ATIVO 1,4 s depois de abrir (desenho `ativoSeloMostrar`) e ele sai em 3,3 s')]
    public function testSeloAtivoPassageiro(): void
    {
        $script = self::trecho(self::ler(self::CABECALHO), 'Carimbo ATIVO passageiro');

        self::assertStringContainsString("pilula.classList.contains('ps-situacao-pill--ativo')", $script, 'só na pasta ativa');
        self::assertStringContainsString("insertAdjacentHTML('afterbegin', faixa.dataset.psSeloAtivoModelo)", $script);
        self::assertStringContainsString('}, 3300);', $script);
        self::assertStringContainsString('}, 1400);', $script);
        self::assertStringContainsString("faixa.querySelector(':scope > .ps-cab-selo--ativo')", $script);

        $css = self::ler(self::CSS);
        $ativo = self::regra($css, '.ps-cab-selo--ativo');
        self::assertStringContainsString('--selo-cor: #1f9d61;', $ativo);
        self::assertStringContainsString('psSeloSai 2.6s ease-in-out .55s forwards', $ativo);
        self::assertStringContainsString('@keyframes psSeloSai', $css);
        // O `animation: none` do reduced-motion vem DEPOIS e vale para o ATIVO também.
        self::assertGreaterThan(strpos($css, '.ps-cab-selo--ativo {'), strpos($css, '.ps-cab-selo, .ps-cab-selo-onda { animation: none; }'));
    }

    #[TestDox('PAS-022: todo popover de `popovers()` ganha o X (na tarja, quando há), com data-sem-x como saída, e o X fecha e devolve o foco')]
    public function testXDeFecharNosPopovers(): void
    {
        $js = self::ler(self::PASTA_JS);

        $popovers = self::trecho($js, 'function popovers()', 'function botaoFechar(');
        self::assertStringContainsString('botaoFechar(alvo, function () {', $popovers, 'cada alvo de [data-ps-pop] recebe o X');
        self::assertStringContainsString("alvo.classList.remove('is-aberto');", $popovers);
        self::assertStringContainsString('gatilho.focus();', $popovers, 'o foco volta ao gatilho');

        $x = self::trecho($js, 'function botaoFechar(', '/* ── 4. Atalhos do trilho');
        self::assertStringContainsString("painel.hasAttribute('data-sem-x')", $x);
        self::assertStringContainsString("painel.querySelector('.ps-pop-x')", $x, 'sem X duplicado');
        self::assertStringContainsString("painel.querySelector(':scope > .ps-pop-tarja')", $x);
        self::assertStringContainsString("'ps-pop-x' + (tarja ? ' ps-pop-x--tarja' : '')", $x);
        self::assertStringContainsString("botao.setAttribute('aria-label', 'Fechar');", $x);
        self::assertStringContainsString("botao.type      = 'button';", $x, 'não submete o form em volta');
        self::assertStringContainsString('e.stopPropagation();', $x);
        self::assertStringContainsString('(tarja || painel).appendChild(botao);', $x);

        $css = self::ler(self::CSS);
        $base = self::regra($css, '.ps-pop-x');
        foreach (['width: 26px;', 'height: 26px;', 'border-radius: 4px;', 'background: var(--ps-pop-x-bg);', 'color: var(--ps-pop-x-tx);'] as $decl) {
            self::assertStringContainsString($decl, $base);
        }
        self::assertStringContainsString('color: #fff;', self::regra($css, '.ps-pop-x--tarja'), 'branco sobre a tarja');
        self::assertStringContainsString('--ps-pop-x-bg:', self::regra($css, '[data-bs-theme="dark"] .ps-pop'), 'par escuro');

        // Os dois menus com tarja do cabeçalho são os que hospedam o X branco.
        $cab = self::ler(self::CABECALHO);
        self::assertSame(2, substr_count($cab, '<div class="ps-pop-tarja">'));
    }

    #[TestDox('PAS-023: o arraste é um utilitário só (PsArrastar), usado pela janela do cliente, pelas tarjas dos menus e pelo modal Editar dados')]
    public function testArrasteExtraidoParaUtilitario(): void
    {
        $js = self::ler(self::PASTA_JS);

        // Definido na carga do script, antes do DOMContentLoaded (o pasta-clientes.js é defer).
        $util = self::trecho($js, 'window.PsArrastar = (function () {', "document.addEventListener('DOMContentLoaded'");
        self::assertStringContainsString("document.addEventListener('pointerdown', function (e) {", $util);
        self::assertStringContainsString('e.target.closest(NAO_ARRASTA)', $util, 'botão e campo do cabeçalho continuam clicáveis');
        self::assertStringContainsString('if (e.button !== undefined && e.button !== 0) { return; }', $util);
        self::assertStringContainsString('if (opcoes.posicionar) { opcoes.posicionar(janela, x, y); return; }', $util);
        self::assertStringContainsString("janela.style.translate = x + 'px ' + y + 'px';", $util, 'translate não briga com o transform do Bootstrap');
        self::assertStringContainsString("window.addEventListener('click', engolir, { capture: true, once: true });", $util, 'soltar fora não fecha o menu');
        self::assertStringContainsString('return { ligar: ligar, zerar: zerar };', $util);

        $uso = self::trecho($js, 'function arrasteDasJanelas()', '/* ── 4. Atalhos do trilho');
        self::assertStringContainsString("cabecalho: '.ps-pop.is-aberto > .ps-pop-tarja'", $uso);
        self::assertStringContainsString("cabecalho: '#modalEditarPasta:not([hidden]) .ps-modal-pasta-cab'", $uso);
        self::assertStringContainsString("cab.closest('.ps-modal-pasta')", $uso);
        self::assertStringContainsString("modal.addEventListener('pe:fechada'", $uso, 'a janela volta ao lugar ao fechar');
        self::assertStringContainsString('if (vaiAbrir) { window.PsArrastar.zerar(alvo); }', self::trecho($js, 'function popovers()', 'function botaoFechar('), 'o menu reabre junto do gatilho');

        $cli = self::ler(__DIR__ . '/../../../public/js/pasta-clientes.js');
        self::assertStringContainsString("cabecalho: '[data-ps-cli-arrastar]'", $cli);
        self::assertStringContainsString('posicionar: posicionar', $cli, 'a janela do cliente continua presa à tela');
        self::assertStringNotContainsString("document.addEventListener('pointerdown'", $cli, 'o arraste próprio saiu');

        // Os ganchos existem nos templates.
        self::assertStringContainsString('data-ps-cli-arrastar', self::ler(__DIR__ . '/../../../templates/cliente/_resumo.html.twig'));
        $show = self::ler(__DIR__ . '/../../../templates/pasta/show.html.twig');
        self::assertMatchesRegularExpression('/id="modalEditarPasta"[^>]*>\s*<div class="ps-modal-pasta" role="dialog"[^>]*>\s*<div class="ps-modal-pasta-cab">/', $show);

        $css = self::ler(self::CSS);
        self::assertStringContainsString("#modalEditarPasta .ps-modal-pasta-cab { cursor: grab; touch-action: none; user-select: none; }", $css);
    }
}
