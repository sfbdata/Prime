<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O JS do Modo avançado (bloco "Modo avançado do painel Intelligence" do
 * `public/js/dashboard-preferencias.js`) lido como FOLHA: não há navegador no PHPUnit, então o que
 * se prova é o contrato com o template, com o CSS e com o endpoint — o clique é smoke do dono.
 */
final class DashboardModoAvancadoJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/dashboard-preferencias.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('a chave e o padrão são os do catálogo do servidor (dashboard.ia_avancado, "desligado")')]
    public function testChaveEPadrao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("var CH_IA = 'dashboard.ia_avancado';", $js);
        self::assertStringContainsString("PADRAO[CH_IA] = 'desligado';", $js);
    }

    #[TestDox('ligado vira a mesma classe que o servidor escreve (db-page--ia-avancado) e ela é limpa ao desligar')]
    public function testClasseIgualADoServidor(): void
    {
        $js = $this->js();

        self::assertStringContainsString("if (e[CH_IA] === 'ligado') { lista.push('db-page--ia-avancado'); }", $js);
        self::assertMatchesRegularExpression('/\^db-page--\([^)]*\|ia-avancado\)\$/', $js, 'aplicarClasses remove a classe antiga');
    }

    #[TestDox('o clique é delegado no .db-page (o interruptor mora no fragmento que o XHR troca) e o aria-checked é reposto a cada recarga')]
    public function testDelegacaoESincronizacao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("pagina.addEventListener('click'", $js);
        self::assertStringContainsString("closest('[data-ia-avancado-switch]')", $js);
        self::assertStringContainsString("pagina.querySelectorAll('[data-ia-avancado-switch]')", $js);
        $encaixar = substr($js, (int) strpos($js, 'function encaixar()'));
        self::assertStringContainsString('sincronizarIa();', substr($encaixar, 0, 200), 'o MutationObserver do fragmento chama encaixar → sincronizarIa');
    }

    #[TestDox('grava pelo mesmo fluxo do menu (mudar → POST com CSRF), nada no navegador')]
    public function testGravaNoServidor(): void
    {
        $js = $this->js();

        self::assertStringContainsString("mudar(CH_IA, 'ligado')", $js);
        self::assertStringContainsString("mudar(CH_IA, 'desligado')", $js);
        self::assertStringContainsString("'X-CSRF-Token': token()", $js);
        self::assertStringNotContainsString('localStorage', $js);
        self::assertStringNotContainsString('sessionStorage', $js);
    }

    #[TestDox('"Restaurar padrão" da tabela preserva o Modo avançado na tela (o servidor também não o apaga)')]
    public function testRestaurarPreserva(): void
    {
        self::assertStringContainsString('padrao[CH_IA] = estado[CH_IA];', $this->js());
    }

    #[TestDox('DASH-026: ligar sem escolha gravada abre a confirmação; o estado vem do servidor (data-ia-avancado-escolhido)')]
    public function testPrimeiraVezPedeConfirmacao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("pagina.getAttribute('data-ia-avancado-escolhido') === 'sim'", $js);
        $ligar = substr($js, (int) strpos($js, 'function ligarIa()'), 200);
        self::assertStringContainsString('if (!iaEscolhido) {', $ligar);
        self::assertStringContainsString('abrirConfirma();', $ligar);
        self::assertStringContainsString('if (corpo.chave === CH_IA) {', $js, 'depois de gravar a chave, não pede de novo');
    }

    #[TestDox('DASH-026: "Ativar Modo Avançado" grava ligado; "Agora não" só fecha; Esc fecha a confirmação')]
    public function testBotoesDaConfirmacao(): void
    {
        $js = $this->js();

        $ativar = substr($js, (int) strpos($js, "if (alvo.hasAttribute('data-ia-confirma-ativar'))"), 200);
        self::assertStringContainsString("mudar(CH_IA, 'ligado');", $ativar);
        $cancelar = substr($js, (int) strpos($js, "if (alvo.hasAttribute('data-ia-confirma-cancelar'))"), 120);
        self::assertStringContainsString('fecharConfirma(true);', $cancelar);
        self::assertStringNotContainsString('mudar(', $cancelar);
        self::assertStringContainsString("if (ev.key === 'Escape') {", substr($js, (int) strpos($js, 'var c = confirmaAberta();')));
    }

    #[TestDox('DASH-026: o cartão abre a cada abertura do painel (toggle na captura) e sai aos 6000ms / some aos 6600ms')]
    public function testCartaoPorSeisSegundos(): void
    {
        $js = $this->js();

        self::assertStringContainsString('var TEASER_SAI = 6000;', $js);
        self::assertStringContainsString('var TEASER_SOME = 6600;', $js);
        self::assertStringContainsString("t.classList.add('is-saindo'); }, TEASER_SAI)", $js);
        self::assertStringContainsString("document.addEventListener('toggle'", $js);
        self::assertStringContainsString("ev.target.id !== 'dbIaPainel'", $js);
        self::assertStringContainsString("if (estado[CH_IA] === 'ligado') { return; }", substr($js, (int) strpos($js, 'function mostrarTeaser()'), 200), 'com o modo ligado o cartão não aparece');
        self::assertStringContainsString('[data-ia-teaser]', $js);
    }
}
