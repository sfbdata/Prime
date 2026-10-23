<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Âncora do Painel do Master (#aba=...&sec=...), lida como FOLHA: não há navegador no PHPUnit, então o que
 * se prova é o contrato do public/js/master/painel.js. Os itens "Exclusivo do Master" do menu do usuário
 * apontam para /master#aba=...&sec=...; com a página já aberta, só o hashchange muda a tela, e ele usa a
 * mesma leitura da âncora do carregamento.
 */
final class PainelAncoraJsTest extends TestCase
{
    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/master/painel.js');
    }

    #[TestDox('o carregamento e o hashchange usam a mesma função de leitura da âncora')]
    public function testHashchangeUsaAMesmaLeitura(): void
    {
        $js = self::js();

        self::assertSame(1, substr_count($js, 'function aplicarAncora()'), 'uma única função lê a âncora');
        self::assertMatchesRegularExpression('/^\s*aplicarAncora\(\);$/m', $js, 'o carregamento lê a âncora');
        self::assertStringContainsString("window.addEventListener('hashchange', () => { if (aplicarAncora()) irPara(S.sec); });", $js);
        self::assertSame(1, substr_count($js, "location.hash.match("), 'a leitura do hash não é duplicada');
    }

    #[TestDox('seção desconhecida ou igual à atual não troca a seção (sem laço nem render à toa)')]
    public function testSecaoDesconhecidaOuIgualNaoTroca(): void
    {
        $js = self::js();

        self::assertStringContainsString("if (!SECS.some(([k]) => k === sec) || sec === S.sec) return false;", $js);
        self::assertStringNotContainsString('location.hash =', $js, 'o painel não escreve no hash');
        self::assertStringNotContainsString('history.replaceState', $js);
        self::assertStringNotContainsString('history.pushState', $js);
    }
}
