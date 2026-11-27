<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Âncora do Painel do Master (#aba=...&sec=...), lida como FOLHA: não há navegador no PHPUnit, então o que
 * se prova é o contrato do public/js/master/painel.js. Os itens "Exclusivo do Master" do menu do usuário
 * apontam para /master#aba=...&sec=...; com a página já aberta, só o hashchange muda a tela, e ele usa a
 * mesma leitura da âncora do carregamento. Desde o M5.16 (23/11/2026) o painel também ESCREVE a âncora ao trocar
 * de aba ou de seção, para cada uma ter endereço próprio.
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
        self::assertStringContainsString("window.addEventListener('hashchange', () => { if (aplicarAncora()) irPara(S.sec, true); else escreverAncora(true); });", $js);
        self::assertSame(1, substr_count($js, "location.hash.match("), 'a leitura do hash não é duplicada');
    }

    #[TestDox('seção desconhecida ou igual à atual (sem filtro de plano novo) não troca a seção (sem laço nem render à toa)')]
    public function testSecaoDesconhecidaOuIgualNaoTroca(): void
    {
        $js = self::js();

        self::assertStringContainsString("if (!SECS.some(([k]) => k === sec)) return false;", $js);
        self::assertStringContainsString("if (sec === S.sec && !mudouPlano) return false;", $js);
    }

    #[TestDox('aba ausente ou desconhecida abre o Painel do sistema; âncora vazia volta à Visão geral')]
    public function testAbaAusenteVoltaAoPainel(): void
    {
        $js = self::js();

        self::assertStringContainsString("S.aba = aba && raiz.querySelector('[data-aba=\"' + aba + '\"]') ? aba : 'painel';", $js);
        self::assertStringContainsString("const sec = doHash('sec') || (location.hash.length > 1 ? null : 'visao');", $js);
    }

    #[TestDox('M5.16: trocar a aba ou a seção escreve a âncora canônica no endereço, com entrada no histórico')]
    public function testTrocarAbaOuSecaoEscreveAAncora(): void
    {
        $js = self::js();

        // Em "Contas e escritórios" com filtro de plano, a âncora leva também o &plano= (link do cartão de plano).
        self::assertStringContainsString("const ancoraAtual = () => '#aba=' + S.aba + (S.aba === 'painel' ? '&sec=' + S.sec + (S.sec === 'contas' && S.fPlano ? '&plano=' + S.fPlano : '') : '');", $js);
        self::assertStringContainsString("S.aba = b.dataset.aba; mostrarAba(); escreverAncora(); }));", $js, 'clique na aba');
        self::assertMatchesRegularExpression('/function irPara\(sec, trocar\) \{[^\n]*renderSecao\(\); escreverAncora\(trocar\); \}/', $js, 'troca de seção');
        self::assertStringContainsString("history[trocar ? 'replaceState' : 'pushState'](null, '', alvo);", $js);
        // Sem laço: pushState e replaceState não disparam o hashchange; e a âncora igual não escreve de novo.
        self::assertDoesNotMatchRegularExpression('/location\.hash\s*=[^=]/', $js, 'nunca atribui ao hash');
        self::assertStringContainsString("if (location.hash === alvo || location.hash.indexOf(alvo + '&') === 0) return;", $js);
    }
}
