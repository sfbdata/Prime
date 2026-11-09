<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O comportamento do cartão de abas (E3) mora em `public/js/ponto-abas.js`. O PHPUnit não executa script: estes testes
 * travam as escolhas que a tela depende (o smoke no navegador é do dono). D4: a lixeira só abre a janela; quem apaga é o
 * "Excluir" da janela, com prazo próprio.
 */
#[Group('ponto')]
#[CoversNothing]
final class PontoAbasJsTest extends TestCase
{
    #[TestDox('D4: a lixeira abre a janela; o único fetch do arquivo é o do "Excluir" da janela, com prazo')]
    public function testConfirmaAntesDeApagar(): void
    {
        $js = self::js();

        self::assertStringContainsString("['[data-excluir-url]', abrirExclusao]", $js, 'a lixeira só abre a janela');
        self::assertStringContainsString("['[data-excluir-confirmar]', confirmarExclusao]", $js, 'quem apaga é o botão da janela');
        self::assertSame(1, substr_count($js, 'fetch('), 'um fetch só');
        $confirmar = self::trecho($js, 'async function confirmarExclusao(botao) {', 'function abrirAnalise(botao) {');
        self::assertStringContainsString('fetch(', $confirmar);
        self::assertStringContainsString('signal: aborto.signal', $confirmar, 'a exclusão não fica esperando para sempre');
        self::assertStringContainsString('setTimeout(function () { aborto.abort(); }, EXCLUIR_PRAZO_MS)', $confirmar);
        self::assertStringContainsString("method: 'POST'", $confirmar);
        self::assertStringNotContainsString('fetch(', self::trecho($js, 'function abrirExclusao(botao) {', 'async function confirmarExclusao(botao) {'));
    }

    #[TestDox('A janela: Esc fecha, clicar fora não fecha (o desenho não fecha), o foco não sai dela')]
    public function testJanela(): void
    {
        $js = self::js();

        self::assertStringContainsString("if (janelaAberta && evento.key === 'Escape') {", $js);
        self::assertStringNotContainsString("'.pe-janela-fundo', fecharJanela", $js, 'clicar no fundo não fecha');
        self::assertStringContainsString("['[data-janela-fechar]', fecharJanela]", $js, 'o "x" e o "Cancelar" fecham');
        self::assertStringContainsString("evento.key === 'Tab'", $js);
    }

    #[TestDox('Recusar sem motivo não sai da janela (mesma frase do desenho e do servidor)')]
    public function testRecusarPedeMotivo(): void
    {
        $enviar = self::trecho(self::js(), 'function enviarAnalise(evento) {', 'const ACOES_DO_CLIQUE');

        self::assertStringContainsString("form.dataset.recusar === '1' && form.querySelector('textarea').value.trim() === ''", $enviar);
        self::assertStringContainsString('evento.preventDefault();', $enviar);
        self::assertStringContainsString("'Informe o motivo da recusa.'", $enviar);
    }

    #[TestDox('A aba aberta vai para o endereço e para o formulário do mês (a tela buscada depois da batida volta nela)')]
    public function testAbaNoEndereco(): void
    {
        $gravar = self::trecho(self::js(), 'function gravarAba(nome) {', 'function abrirAba(nome, focar) {');

        self::assertStringContainsString("url.searchParams.set('aba', nome);", $gravar);
        self::assertStringContainsString('window.history.replaceState(', $gravar);
        self::assertStringContainsString("document.getElementById('competencia-ponto-index-form')", $gravar);
    }

    #[TestDox('Tudo por delegação no documento: o painel e a aba das pendências são trocados depois da batida')]
    public function testDelegacao(): void
    {
        $js = self::js();

        self::assertStringContainsString("document.addEventListener('click', function (evento) {", $js);
        self::assertStringNotContainsString("getElementById('ponto-aba-pendencias').addEventListener", $js);
        self::assertStringNotContainsString("getElementById('ponto-regiao-pendencias').addEventListener", $js);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $js, 'sem travessão');
    }

    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-abas.js');
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
