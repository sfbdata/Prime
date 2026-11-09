<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O comportamento das sedes e dos feriados na aba Configurações (E6) mora em `public/js/ponto-configuracoes-sedes.js`.
 * O PHPUnit não executa script: estes testes travam as escolhas de que a tela depende (o smoke no navegador é do
 * dono). D4: o lixo e o "x" só abrem a janela; quem remove é o botão da janela.
 */
#[Group('ponto')]
#[CoversNothing]
final class PontoConfiguracoesSedesJsTest extends TestCase
{
    #[TestDox('Um fetch só, com prazo: AbortController, setTimeout com a constante e o sinal no fetch')]
    public function testFetchComPrazo(): void
    {
        $js = self::js();

        self::assertSame(1, substr_count($js, 'fetch('), 'um fetch só, o de `enviar`');
        $enviar = self::trecho($js, 'async function enviar(url, corpo) {', 'function frase(dados, padrao) {');
        self::assertStringContainsString('const aborto = new AbortController();', $enviar);
        self::assertStringContainsString('setTimeout(function () { aborto.abort(); }, GRAVAR_PRAZO_MS)', $enviar);
        self::assertStringContainsString('signal: aborto.signal', $enviar);
        self::assertStringContainsString("method: 'POST'", $enviar);
        self::assertStringContainsString('clearTimeout(prazo);', $enviar);
        self::assertMatchesRegularExpression('/const GRAVAR_PRAZO_MS = \d+;/', $js);
    }

    #[TestDox('D4: remover sede e remover feriado abrem a janela; só o botão da janela chama o servidor')]
    public function testConfirmaAntesDeRemover(): void
    {
        $js = self::js();

        self::assertStringContainsString("['[data-sede-remover]', pedirRemoverSede]", $js);
        self::assertStringContainsString("['[data-feriado-remover]', pedirRemoverFeriado]", $js);
        self::assertStringContainsString("['[data-config-confirmar]', confirmarRemocao]", $js);
        foreach (['function pedirRemoverSede(botao) {' => '// ----', 'function pedirRemoverFeriado(botao) {' => '// ----'] as $inicio => $fim) {
            $trecho = self::trecho($js, $inicio, $fim);
            self::assertStringContainsString('abrirJanela(', $trecho);
            self::assertStringNotContainsString('enviar(', $trecho);
        }
        self::assertStringContainsString('enviar(botao.dataset.url', self::trecho($js, 'async function confirmarRemocao(botao) {', '// ----'));
    }

    #[TestDox('A janela: Esc fecha, clicar fora não fecha, o foco não sai dela; os atributos não são os da E3')]
    public function testJanela(): void
    {
        $js = self::js();

        self::assertStringContainsString("if (janelaAberta && evento.key === 'Escape') {", $js);
        self::assertStringContainsString("evento.key === 'Tab'", $js);
        self::assertStringContainsString("['[data-config-fechar]', fecharJanela]", $js);
        self::assertStringNotContainsString("'.pe-janela-fundo', fecharJanela", $js, 'clicar no fundo não fecha');
        self::assertStringNotContainsString('data-janela-fechar', $js, 'o "x" da E3 é do js/ponto-abas.js');
        self::assertStringNotContainsString('data-excluir', $js);
    }

    #[TestDox('"Usar minha posição": leitor com prazo do módulo da batida, 6 casas, e o aviso do desenho sem posição')]
    public function testUsarMinhaPosicao(): void
    {
        $js = self::js();
        $usar = self::trecho($js, 'async function usarPosicao(botao) {', 'function tirarLinha(linha) {');

        self::assertStringContainsString('window.PontoBatida.lerPosicaoComPrazo', $usar);
        self::assertStringContainsString('toFixed(CASAS_DA_POSICAO)', $usar);
        self::assertStringContainsString('const CASAS_DA_POSICAO = 6;', $js);
        self::assertStringContainsString("const SEM_POSICAO = 'Localização indisponível neste aparelho.';", $js);
        self::assertStringNotContainsString('getCurrentPosition', $js, 'a posição só vem pelo leitor com prazo');
    }

    #[TestDox('Grava ao sair do campo, uma gravação por linha de cada vez; feriado sem data ou nome não sai da tela')]
    public function testGravacao(): void
    {
        $js = self::js();

        self::assertStringContainsString("document.addEventListener('change', function (evento) {", $js);
        self::assertStringNotContainsString("addEventListener('input'", $js, 'não grava a cada tecla');
        $gravar = self::trecho($js, 'async function gravarSede(linha) {', '// "Nova sede"');
        self::assertStringContainsString("linha.dataset.deNovo = '1';", $gravar);
        self::assertStringContainsString("const FALTA_FERIADO = 'Informe a data e o nome do feriado.';", $js);
        $cadastrar = self::trecho($js, 'async function cadastrarFeriado(form) {', 'function trocarLista(secao, html) {');
        self::assertLessThan(strpos($cadastrar, 'enviar('), strpos($cadastrar, 'avisar(FALTA_FERIADO, true);'), 'confere antes de enviar');
    }

    #[TestDox('Tudo por delegação no documento (o painel é buscado depois e pode ser buscado de novo); sem travessão')]
    public function testDelegacao(): void
    {
        $js = self::js();

        self::assertStringContainsString("document.addEventListener('click', function (evento) {", $js);
        self::assertStringContainsString("document.addEventListener('submit', function (evento) {", $js);
        self::assertStringNotContainsString('getElementById(\'ponto-painel-configuracoes\').addEventListener', $js);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $js, 'sem travessão');
    }

    #[TestDox('A tela carrega o arquivo depois do js/ponto-configuracoes.js')]
    public function testTelaCarregaOArquivo(): void
    {
        $tela = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
        $contrato = strpos($tela, "<script src=\"{{ asset('js/ponto-configuracoes.js') }}\"></script>");
        $sedes = strpos($tela, "<script src=\"{{ asset('js/ponto-configuracoes-sedes.js') }}\"></script>");

        self::assertIsInt($contrato);
        self::assertIsInt($sedes);
        self::assertGreaterThan($contrato, $sedes);
    }

    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-configuracoes-sedes.js');
    }

    private static function trecho(string $fonte, string $inicio, string $fim): string
    {
        $de = strpos($fonte, $inicio);
        self::assertIsInt($de, $inicio);
        $ate = strpos($fonte, $fim, $de + \strlen($inicio));
        self::assertIsInt($ate, $fim);

        return substr($fonte, $de, $ate - $de);
    }
}
