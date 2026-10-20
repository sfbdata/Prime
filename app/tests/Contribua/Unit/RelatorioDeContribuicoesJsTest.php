<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O public/js/contribua-relatorio.js mostra o cartão "Contribuições" só na seção "Visão geral" do Painel do
 * Master, lendo os botões de seção que o public/js/master/painel.js desenha. Sem navegador no PHPUnit: prova-se
 * o contrato entre os dois arquivos (se o painel mudar o nome da seção ou das classes, este teste avisa).
 */
#[CoversNothing]
final class RelatorioDeContribuicoesJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/';

    #[TestDox('O cartão lê #mp-secs, .mp-sec.on e data-sec="visao", que o painel.js desenha')]
    public function testContratoComOPainel(): void
    {
        $relatorio = (string) file_get_contents(self::JS . 'contribua-relatorio.js');
        $painel = (string) file_get_contents(self::JS . 'master/painel.js');

        self::assertStringContainsString("document.getElementById('mp-secs')", $relatorio);
        self::assertStringContainsString("querySelector('.mp-sec.on')", $relatorio);
        self::assertStringContainsString("ligada.dataset.sec !== 'visao'", $relatorio);
        self::assertStringContainsString("getElementById('mp-secs')", $painel);
        self::assertStringContainsString("['visao', 'Visão geral',", $painel);
        self::assertStringContainsString('\'<button type="button" class="mp-sec\' + (S.sec === k ? \' on\' : \'\') + \'" data-sec="\' + k', $painel);
    }

    #[TestDox('Sem seção ligada (antes do painel carregar), o cartão fica visível')]
    public function testVisivelSemSecaoLigada(): void
    {
        $relatorio = (string) file_get_contents(self::JS . 'contribua-relatorio.js');

        self::assertStringContainsString('cartao.hidden = Boolean(ligada) && ', $relatorio);
        self::assertStringContainsString('new MutationObserver(mostrar)', $relatorio);
    }
}
