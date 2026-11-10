<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O painel das abas de gestão (Equipe e Configurações, E5 e E6) é buscado por `public/js/ponto-paineis.js` na primeira
 * vez que a aba abre. O PHPUnit não executa script: estes testes travam as escolhas de que a tela depende (o smoke no
 * navegador é do dono).
 */
#[Group('ponto')]
#[CoversNothing]
final class PontoPaineisJsTest extends TestCase
{
    #[TestDox('A busca do painel tem prazo e trata o redirecionamento como falha (sessão sem escritório, vínculo suspenso)')]
    public function testBuscaComPrazo(): void
    {
        $carregar = self::trecho(self::js('ponto-paineis.js'), 'async function carregar(painel, deNovo) {', "document.addEventListener('ponto:aba-aberta'");

        self::assertSame(1, substr_count(self::js('ponto-paineis.js'), 'fetch('), 'um fetch só, o do painel');
        self::assertStringContainsString('signal: aborto.signal', $carregar, 'o painel não fica carregando para sempre');
        self::assertStringContainsString('setTimeout(function () { aborto.abort(); }, CARREGAR_PRAZO_MS)', $carregar);
        self::assertStringContainsString('clearTimeout(prazo)', $carregar);
        self::assertStringContainsString('if (!resposta.ok || resposta.redirected) {', $carregar, 'a página de escolher escritório não entra no painel');
    }

    #[TestDox('Carregando, falha e "Tentar de novo" são texto, nunca HTML; só a resposta do servidor entra como HTML')]
    public function testAvisosSaoTexto(): void
    {
        $js = self::js('ponto-paineis.js');

        self::assertStringContainsString("const TEXTO_CARREGANDO = 'Carregando...';", $js);
        self::assertStringContainsString("const TEXTO_FALHA = 'Não foi possível carregar esta aba.';", $js);
        self::assertStringContainsString("const TEXTO_TENTAR = 'Tentar de novo';", $js);
        self::assertStringContainsString('frase.textContent = texto;', $js);
        self::assertStringContainsString('tentar.textContent = TEXTO_TENTAR;', $js);
        self::assertSame(1, substr_count($js, 'innerHTML'), 'só o painel que veio do servidor');
        self::assertStringContainsString("evento.target.closest('[data-painel-tentar]')", $js, 'o botão busca de novo');
    }

    #[TestDox('O painel abre pela aba (ponto:aba-aberta) e, ao entrar com ?aba=equipe, pelo painel que já veio visível')]
    public function testQuandoBusca(): void
    {
        $paineis = self::js('ponto-paineis.js');
        $abas    = self::js('ponto-abas.js');

        self::assertStringContainsString("document.addEventListener('ponto:aba-aberta', function (evento) {", $paineis);
        self::assertStringContainsString("document.querySelectorAll('section.pe-abas [data-carregar]:not([hidden])')", self::trecho($paineis, 'function iniciar() {', 'window.PontoPaineis'));
        self::assertStringContainsString("if (estado === 'pronto' && !deNovo) {", $paineis, 'abrir de novo não busca de novo');

        self::assertStringContainsString("new CustomEvent('ponto:aba-aberta'", $abas);
        self::assertStringContainsString('avisarAbaAberta(nome);', self::trecho($abas, 'function abrirAba(nome, focar) {', 'function avisarAbaAberta(nome) {'));
        self::assertStringContainsString('avisarAbaAberta(escolhida.dataset.aba);', self::trecho($abas, 'function iniciar() {', "document.readyState === 'loading'"));
    }

    #[TestDox('A Auditoria aberta mostra o evento novo: busca de novo a cada abertura e depois da batida, as outras abas não (E9, desenho L452)')]
    public function testAuditoriaNaoFicaVelha(): void
    {
        $paineis = self::js('ponto-paineis.js');
        $abas    = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/_abas.html.twig');
        $tela    = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');

        self::assertSame(1, substr_count($abas, '" data-recarregar-ao-abrir'), 'só a Auditoria; a Equipe calcula o saldo de cada pessoa e só busca na primeira vez');
        self::assertStringContainsString("data-carregar=\"{{ path('ponto_auditoria') }}\" data-recarregar-ao-abrir", $abas);

        $aoAbrir = self::trecho($paineis, "document.addEventListener('ponto:aba-aberta', function (evento) {", '});');

        $abertos = self::trecho($paineis, 'function recarregarAbertos() {', 'window.PontoPaineis');
        self::assertStringContainsString("document.querySelectorAll('section.pe-abas [data-recarregar-ao-abrir]:not([hidden])')", $abertos, 'fechado, a próxima abertura busca');
        self::assertStringContainsString("if (painel.dataset.estado === 'pronto' || painel.dataset.estado === 'carregando') {", $abertos, 'com falha, fica o "Tentar de novo"');
        self::assertStringContainsString('recarregarAbertos,', self::trecho($paineis, 'window.PontoPaineis = {', '};'));

        // Pedido no meio de outra busca (a batida durante a troca de página): guardado e feito depois, com o endereço de agora.
        $carregar = self::trecho($paineis, 'async function carregar(painel, deNovo) {', "document.addEventListener('ponto:aba-aberta'");
        self::assertMatchesRegularExpression("/if \\(estado === 'carregando'\\) \\{\\s+if \\(deNovo\\) \\{\\s+painel\\.dataset\\.deNovo = '1';\\s+\\}\\s+return;/", $carregar);
        $depois = self::trecho($carregar, '} finally {', "\n    }\n");
        self::assertMatchesRegularExpression("/if \\(painel\\.dataset\\.deNovo === '1'\\) \\{\\s+delete painel\\.dataset\\.deNovo;\\s+carregar\\(painel, true\\);/", $depois);

        // Quando a busca da tela termina (com a tela, descartada ou com falha), e não antes: o PHP atende uma sessão por
        // vez, e a conferência da cadeia seguraria a tela e o botão depois da batida.
        $depoisDaBatida = self::trecho($tela, 'async function atualizarTelaDoPonto() {', 'let batidaAceita = null;');
        self::assertStringContainsString('const novo = await window.PontoBatida.buscarTelaDoPonto(window.location.href).finally(recarregarAuditoriaAberta);', $depoisDaBatida);
        self::assertSame(2, substr_count($tela, 'recarregarAuditoriaAberta'), 'definida uma vez e chamada só no fim da busca da tela');
        self::assertSame(1, substr_count($tela, 'window.PontoPaineis.recarregarAbertos();'));
        self::assertStringNotContainsString('recarregarAbertos', self::js('ponto-batida.js'), 'a "Sincronização" não busca no meio da busca da tela');

        // Abrir com a busca em andamento (entrada com `?aba=auditoria`) não guarda outra: uma busca só na carga.
        self::assertStringContainsString("painel.hasAttribute('data-recarregar-ao-abrir') && painel.dataset.estado !== 'carregando'", $aoAbrir);
    }

    #[TestDox('Na tela, o carregador entra antes do js/ponto-abas.js (ouve antes de a aba avisar)')]
    public function testOrdemDosScripts(): void
    {
        $tela = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');

        $paineis = strpos($tela, "asset('js/ponto-paineis.js')");
        $abas    = strpos($tela, "asset('js/ponto-abas.js')");
        self::assertIsInt($paineis);
        self::assertIsInt($abas);
        self::assertLessThan($abas, $paineis);
    }

    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/' . $arquivo);
    }

    private static function trecho(string $fonte, string $inicio, string $fim): string
    {
        $de  = strpos($fonte, $inicio);
        self::assertIsInt($de, $inicio);
        $ate = strpos($fonte, $fim, $de);
        self::assertIsInt($ate, $fim);

        return substr($fonte, $de, $ate - $de);
    }
}
