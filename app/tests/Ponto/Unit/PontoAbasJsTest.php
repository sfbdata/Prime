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

    #[TestDox('Excluída: a janela fecha e a linha sai sem recarregar; só sem a linha na tela a aba abre de novo do servidor')]
    public function testExcluirSemRecarregar(): void
    {
        $js        = self::js();
        $confirmar = self::trecho($js, 'async function confirmarExclusao(botao) {', 'function abrirAnalise(botao) {');
        $sucesso   = self::trecho($confirmar, 'if (resposta.ok && dados.ok === true) {', 'falha = dados.mensagem');

        self::assertStringContainsString('const linha = linhaDaExclusao;', $confirmar, 'a linha é a da lixeira que abriu a janela');
        self::assertStringContainsString("linhaDaExclusao = botao.closest('tr.pe-just-lin');", self::trecho($js, 'function abrirExclusao(botao) {', 'async function confirmarExclusao(botao) {'));
        self::assertMatchesRegularExpression(
            '/if \(!linha \|\| !document\.contains\(linha\)\) \{\s+window\.location\.assign\(cartao\(\)\.dataset\.urlVolta\);\s+return;\s+\}/',
            $sucesso,
            'a volta pelo servidor é só o plano B',
        );
        self::assertSame(1, substr_count($js, 'window.location.assign('), 'nenhuma outra navegação na exclusão');
        self::assertStringNotContainsString('reload', $js);
        self::assertStringContainsString("focoAntes = document.getElementById('ponto-aba-justificativas');", $sucesso, 'o foco não se perde com a lixeira que saiu');
        $fechar = strpos($sucesso, 'fecharJanela();');
        $tirar  = strpos($sucesso, 'tirarLinhaExcluida(linha);');
        self::assertIsInt($fechar);
        self::assertIsInt($tirar);
        self::assertLessThan($tirar, $fechar, 'a janela fecha (sem a trava do envio) antes de a linha sair');
        $soltar = strpos($sucesso, 'delete fundo.dataset.ocupada;');
        self::assertIsInt($soltar);
        self::assertLessThan($fechar, $soltar, 'a trava do envio sai antes de fechar');
    }

    #[TestDox('A linha sai e os números acompanham: Minhas Justificativas pela lista, Equipe quando a linha contava lá')]
    public function testLinhaENumerosDasAbas(): void
    {
        $js    = self::js();
        $tirar = self::trecho($js, 'function tirarLinhaExcluida(linha) {', 'async function confirmarExclusao(botao) {');

        self::assertStringContainsString('linha.remove();', $tirar);
        self::assertStringContainsString("const restantes = corpo.querySelectorAll('tr.pe-just-lin').length;", $tirar);
        self::assertStringContainsString("baixarNumeroDaAba(document.getElementById('ponto-aba-justificativas'), restantes);", $tirar);
        self::assertStringContainsString("const contavaNaEquipe = linha.querySelector('[data-analisar]') !== null;", $tirar, 'só a de quem analisa a própria conta na Equipe (P43 a)');
        self::assertMatchesRegularExpression('/if \(contavaNaEquipe\) \{\s+tirarDaEquipe\(\);\s+\}/', $tirar);
        self::assertStringContainsString("document.dispatchEvent(new CustomEvent('ponto:justificativa-excluida'));", $tirar, 'a Folha e as pendências vêm do index');

        $baixar = self::trecho($js, 'function baixarNumeroDaAba(aba, valor) {', 'function tirarDaEquipe() {');
        self::assertStringContainsString('numero.textContent = String(valor);', $baixar);
        self::assertStringContainsString('numero.remove();', $baixar, 'no zero o número some, como o servidor faz');

        $equipe = self::trecho($js, 'function tirarDaEquipe() {', 'function tirarLinhaExcluida(linha) {');
        self::assertStringContainsString("baixarNumeroDaAba(aba, (parseInt(numero.textContent, 10) || 0) - 1);", $equipe);
        self::assertStringContainsString("if (painel && painel.dataset.estado === 'pronto') {", $equipe);
        self::assertStringContainsString('delete painel.dataset.estado;', $equipe, 'o painel da Equipe é buscado de novo quando abrir');
        $paineis = (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-paineis.js');
        self::assertStringContainsString("if (estado === 'carregando' || (estado === 'pronto' && !deNovo)) {", $paineis, 'sem estado, o painel é buscado');
    }

    #[TestDox('Lista vazia depois de excluir: a mesma frase do servidor, por textContent; nada de HTML montado com dado')]
    public function testListaVaziaESemHtml(): void
    {
        $js    = self::js();
        $tirar = self::trecho($js, 'function tirarLinhaExcluida(linha) {', 'async function confirmarExclusao(botao) {');

        self::assertStringContainsString('vazio.textContent = TEXTO_SEM_JUSTIFICATIVAS;', $tirar);
        self::assertStringContainsString("corpo.closest('.pe-abas-rolagem').replaceWith(vazio);", $tirar);
        self::assertMatchesRegularExpression("/const TEXTO_SEM_JUSTIFICATIVAS = '([^']+)';/", $js);
        preg_match("/const TEXTO_SEM_JUSTIFICATIVAS = '([^']+)';/", $js, $frase);
        $twig = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/_abas_justificativas.html.twig');
        self::assertStringContainsString('<div class="pe-abas-vazio">' . $frase[1] . '</div>', $twig, 'a mesma frase e o mesmo molde do servidor');
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $js, $proibido);
        }
    }

    #[TestDox('O index atualiza a Folha e as pendências depois de excluir pelo caminho de depois da batida, sem recarregar')]
    public function testIndexAtualizaDepoisDeExcluir(): void
    {
        $index  = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
        $ouvido = self::trecho($index, "document.addEventListener('ponto:justificativa-excluida', () => {", '});');

        self::assertStringContainsString('atualizarTelaDoPonto().catch(', $ouvido, 'as regiões da batida e o cartão da Folha, tudo ou nada');
        self::assertStringNotContainsString('reload', $ouvido, 'falhou: fica como está');
        self::assertStringNotContainsString('liberarBotaoBatida', $ouvido, 'o botão da batida não é da exclusão');
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
