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
}
