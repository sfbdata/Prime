<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * PAS-022b — os popovers das linhas das Metas trocadas pelo XHR (concluir/reabrir sem
 * recarregar, PMC-031) ganham o X de fechar e reabrem junto do gatilho (`PsArrastar.zerar`),
 * como os do carregamento; e o arraste solta também no `pointercancel`.
 *
 * Lido como FOLHA: não há navegador no PHPUnit. Prova-se o contrato entre os dois scripts
 * (`window.PsPopovers.religar` exposto pelo pasta-show.js e chamado pelo pasta-metas.js logo
 * depois da troca). O clique em si é smoke do dono.
 */
#[CoversNothing]
final class PastaPopoversTrocadosJsTest extends TestCase
{
    private const PASTA_JS = __DIR__ . '/../../../public/js/pasta-show.js';
    private const METAS_JS = __DIR__ . '/../../../public/js/pasta-metas.js';

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

    #[TestDox('PAS-022b: o pasta-show.js expõe PsPopovers.religar(raiz), que põe o X (botaoFechar) em cada [data-ps-pop] da raiz, com o mesmo fechar de popovers()')]
    public function testPastaShowExpoeReligar(): void
    {
        $js = self::ler(self::PASTA_JS);

        $religar = self::trecho($js, 'window.PsPopovers = {', '/* Onde o arraste vale nesta tela');
        self::assertStringContainsString("(raiz || document).querySelectorAll('[data-ps-pop]')", $religar);
        self::assertStringContainsString("document.getElementById(gatilho.getAttribute('data-ps-pop'))", $religar);
        self::assertStringContainsString('botaoFechar(alvo, function () {', $religar);
        self::assertStringContainsString("alvo.classList.remove('is-aberto');", $religar);
        self::assertStringContainsString("gatilho.setAttribute('aria-expanded', 'false');", $religar);
        self::assertStringContainsString('gatilho.focus();', $religar, 'o foco volta ao gatilho');

        // Definido na carga do script (fora do DOMContentLoaded): o pasta-metas.js vem antes na página.
        $boot = self::trecho($js, "document.addEventListener('DOMContentLoaded', function () {", '/* ── 1. Indicador das abas');
        self::assertStringNotContainsString('PsPopovers', $boot);
        self::assertGreaterThan(strpos($js, 'function botaoFechar('), strpos($js, 'window.PsPopovers = {'));

        // O X não é duplicado em quem já tem (a religação pode passar duas vezes no mesmo painel).
        self::assertStringContainsString("painel.querySelector('.ps-pop-x')", self::trecho($js, 'function botaoFechar(', 'window.PsPopovers = {'));
    }

    #[TestDox('PAS-022b: depois da troca da aba, o pasta-metas.js religa o X no cartão e, ao abrir um popover trocado, zera o arraste')]
    public function testPastaMetasReligaEZera(): void
    {
        $js = self::ler(self::METAS_JS);

        $troca = self::trecho($js, 'function aplicarAbaNova(cartao, html) {', 'function metaDoFormulario(form) {');
        $marca   = strpos($troca, "g.setAttribute('data-ps-pop-local', '1');");
        $religar = strpos($troca, 'if (window.PsPopovers) { window.PsPopovers.religar(cartao); }');
        $copia   = strpos($troca, 'while (novoCartao.firstChild) { cartao.appendChild(novoCartao.firstChild); }');
        self::assertNotFalse($marca);
        self::assertNotFalse($religar, 'o X das linhas novas');
        self::assertNotFalse($copia);
        self::assertGreaterThan($copia, $religar, 'religa DEPOIS de o conteúdo novo entrar no cartão');

        $abrir = self::trecho($js, 'function popoversDasLinhasNovas() {', 'function aplicarAbaNova(cartao, html) {');
        $zerar = strpos($abrir, 'if (vaiAbrir && window.PsArrastar) { window.PsArrastar.zerar(painel); }');
        self::assertNotFalse($zerar, 'o menu reabre junto do gatilho, como no popovers() do pasta-show.js');
        self::assertLessThan(strpos($abrir, "painel.classList.toggle('is-aberto', vaiAbrir);"), $zerar, 'zera antes de abrir');
    }

    #[TestDox('PAS-022b: o PsArrastar trata pointercancel como pointerup — liga e desliga junto')]
    public function testArrasteSoltaNoPointercancel(): void
    {
        $util = self::trecho(self::ler(self::PASTA_JS), 'window.PsArrastar = (function () {', 'return { ligar: ligar, zerar: zerar };');

        self::assertStringContainsString("window.addEventListener('pointerup', soltar);", $util);
        self::assertStringContainsString("window.addEventListener('pointercancel', soltar);", $util);
        $soltar = self::trecho($util, 'function soltar() {', "janela.classList.remove('is-arrastando');");
        self::assertStringContainsString("window.removeEventListener('pointerup', soltar);", $soltar);
        self::assertStringContainsString("window.removeEventListener('pointercancel', soltar);", $soltar);
        self::assertStringContainsString("window.removeEventListener('pointermove', mover);", $soltar);
    }
}
