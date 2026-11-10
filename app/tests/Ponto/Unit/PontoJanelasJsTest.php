<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As janelas da E4 do Ponto ("Justificar falta", "Registro de esquecimento" e "Editar justificativa") moram em
 * `public/js/ponto-janelas.js`, sobre a moldura do `public/js/ponto-abas.js` (Esc, foco preso, linha de erro). O PHPUnit
 * não executa script: estes testes travam as escolhas de que a tela depende (o smoke no navegador é do dono).
 */
#[Group('ponto')]
#[CoversNothing]
final class PontoJanelasJsTest extends TestCase
{
    #[TestDox('Sem modal do Bootstrap: as três janelas abrem pela moldura do js/ponto-abas.js, por delegação no documento')]
    public function testAbrePelaMolduraDoDesenho(): void
    {
        $js = self::janelas();

        self::assertStringNotContainsString('bootstrap', $js);
        self::assertStringNotContainsString('alert(', $js, 'a frase vai para a linha de erro do desenho, não para o alerta do navegador');
        self::assertStringContainsString("moldura().abrirJanela('ponto-janela-justificar', botao)", $js);
        self::assertStringContainsString("moldura().abrirJanela('ponto-janela-esquecimento', botao)", $js);
        self::assertStringContainsString("moldura().abrirJanela('ponto-janela-editar', botao)", $js);
        self::assertStringContainsString("['[data-janela-justificar]', abrirJustificar]", $js);
        self::assertStringContainsString("['[data-esquecimento-dia]', abrirEsquecimento]", $js, 'o "+" da folha (e quem o clica)');
        self::assertStringContainsString("['[data-justificativa-editar]', abrirEdicao]", $js);
        self::assertStringContainsString("document.addEventListener('click', function (evento) {", $js);
        self::assertStringContainsString("'use strict';", $js);
    }

    #[TestDox('A moldura do js/ponto-abas.js serve às janelas da E4: Esc fecha, o foco não sai e pula o que está escondido')]
    public function testMolduraCompartilhada(): void
    {
        $abas = self::abas();

        self::assertStringContainsString('window.PontoAbas = { EXCLUIR_PRAZO_MS, abrirAba, abrirJanela, fecharJanela, mostrarErro };', $abas);
        self::assertStringContainsString("if (janelaAberta && evento.key === 'Escape') {", $abas, 'Esc fecha qualquer janela aberta');
        self::assertStringContainsString("select:not([disabled])", $abas, 'o tipo entra na volta do Tab');
        self::assertStringContainsString("filter(function (el) { return !el.closest('[hidden]'); })", $abas, 'campo de bloco escondido fica fora da volta do Tab');
        self::assertStringContainsString('if (info) {', self::trecho($abas, 'function abrirJanela(id, origem) {', 'function fecharJanela() {'), 'a janela de justificar não tem a linha de informação');
    }

    #[TestDox('Como no desenho, escrever num campo apaga a frase de erro da janela')]
    public function testErroSomeAoEditar(): void
    {
        $abas = self::abas();

        $apagar = self::trecho($abas, 'function apagarErroAoEditar(evento) {', "document.addEventListener('input', apagarErroAoEditar);");
        self::assertStringContainsString("mostrarErro(janelaAberta, '');", $apagar);
        self::assertStringContainsString("document.addEventListener('change', apagarErroAoEditar);", $abas);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function conferencias(): iterable
    {
        yield 'Justificar falta' => ['function conferirJustificar(evento, form) {', '// ---- Registro de esquecimento', [
            'ERRO_DATA_E_MOTIVO', 'ERRO_TIPO', 'ERRO_ESQUECIDO_UMA_DATA', 'ERRO_ESQUECIDO', 'ERRO_ABONO_UMA_DATA', 'ERRO_ABONO_HORARIOS',
        ]];
        yield 'Registro de esquecimento' => ['function conferirEsquecimento(evento, form) {', '// ---- Editar justificativa', ['ERRO_HORARIO_E_MOTIVO']];
        yield 'Editar justificativa' => ['function conferirEdicao(evento, form) {', '// ---- Delegação', ['ERRO_MOTIVO', 'ERRO_ESQUECIDO', 'ERRO_ABONO_HORARIOS']];
    }

    /** @param list<string> $erros */
    #[TestDox('$_dataName: a conferência antes do envio segura o formulário e põe a frase na linha de erro')]
    #[DataProvider('conferencias')]
    public function testConferenciaAntesDoEnvio(string $inicio, string $fim, array $erros): void
    {
        $conferir = self::trecho(self::janelas(), $inicio, $fim);

        $posicoes = [];
        foreach ($erros as $erro) {
            self::assertStringContainsString('recusar(evento, form, ' . $erro . ',', $conferir, $erro);
            $posicoes[] = (int) strpos($conferir, 'recusar(evento, form, ' . $erro . ',');
        }
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes, 'a ordem das perguntas');
        self::assertStringContainsString('enviar(form);', $conferir, 'tudo certo: envia e trava o botão');

        $recusar = self::trecho(self::janelas(), 'function recusar(evento, form, texto, campo) {', 'function enviar(form) {');
        self::assertStringContainsString('evento.preventDefault();', $recusar);
        self::assertStringContainsString("moldura().mostrarErro(form.closest('.pe-janela-fundo'), texto);", $recusar);
    }

    #[TestDox('As frases são as do desenho e as do servidor (o mesmo texto de quem recusa depois)')]
    public function testFrases(): void
    {
        $js = self::janelas();

        foreach ([
            "'Informe a data e o motivo.'",
            "'Informe o horário e o motivo.'",
            "'Informe o motivo.'",
            "'Selecione o tipo de justificativa.'",
            "'Informe o tipo e o horário do registro esquecido.'",
            "'Esquecimento de Registro só pode ser aplicado a uma única data.'",
            "'Abono parcial só pode ser aplicado a uma única data.'",
            "'Informe os horários de saída e retorno corretamente para o abono parcial.'",
        ] as $frase) {
            self::assertStringContainsString($frase, $js);
        }
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $js, 'sem travessão');
    }

    #[TestDox('Envio em andamento: o botão trava e a janela não fecha; o "Voltar" do navegador libera')]
    public function testEnvioTrava(): void
    {
        $js = self::janelas();

        $enviar = self::trecho($js, 'function enviar(form) {', 'function motivoEmBranco(form) {');
        self::assertStringContainsString("form.querySelector('[data-janela-enviar]').disabled = true;", $enviar);
        self::assertStringContainsString("fundo.dataset.ocupada = '1';", $enviar);
        self::assertStringContainsString("window.addEventListener('pageshow', function (evento) {", $js);
    }

    #[TestDox('Justificar falta abre com hoje do servidor já marcado; dia futuro não se escolhe; abono parcial só com um dia')]
    public function testCalendario(): void
    {
        $js = self::janelas();

        $abrir = self::trecho($js, 'function abrirJustificar(botao) {', 'function conferirJustificar(evento, form) {');
        self::assertStringContainsString("form.querySelector('[data-just-calendario]').dataset.hoje", $abrir, 'hoje do servidor, não do aparelho');
        self::assertStringContainsString('calendario.escolhidos = new Set([calendario.hoje]);', $abrir);
        self::assertStringContainsString('form.reset();', $abrir);

        $desenhar = self::trecho($js, 'function desenharCalendario(form) {', 'function atualizarEscolhidos(form) {');
        self::assertStringContainsString("(chave > calendario.hoje ? ' disabled' : '')", $desenhar);
        self::assertStringContainsString('aria-pressed="', $desenhar);

        $abono = self::trecho($js, 'function trocarAbonoJustificar(marca) {', '// Abre com hoje já marcado');
        self::assertStringContainsString('marca.checked && datasEscolhidas().length > 1', $abono);
        self::assertStringContainsString('marca.checked = false;', $abono);
    }

    #[TestDox('Registro de esquecimento: data e batida do "+", resumo "dd/mm/aaaa · Batida" do desenho, foco no Horário')]
    public function testEsquecimento(): void
    {
        $abrir = self::trecho(self::janelas(), 'function abrirEsquecimento(botao) {', 'function conferirEsquecimento(evento, form) {');

        self::assertStringContainsString("form.querySelector('#esquecimentoData').value = botao.dataset.esquecimentoDia;", $abrir);
        self::assertStringContainsString("form.querySelector('#esquecimentoCampo').value = botao.dataset.esquecimentoCampo;", $abrir);
        self::assertStringContainsString("formatarData(botao.dataset.esquecimentoDia) + ' · ' + (botao.dataset.esquecimentoRotulo || '')", $abrir);
        self::assertStringContainsString("form.querySelector('#esquecimentoHora').value = '';", $abrir);
        self::assertStringContainsString("form.querySelector('#esquecimentoHora').focus();", $abrir);
    }

    #[TestDox('Editar: os dados vêm do lápis, inclusive o motivo; atestado de lote analisado fica desligado, não só escondido')]
    public function testEdicao(): void
    {
        $abrir = self::trecho(self::janelas(), 'function abrirEdicao(botao) {', 'function conferirEdicao(evento, form) {');

        self::assertStringContainsString('form.action = dados.url;', $abrir);
        self::assertStringContainsString("form.querySelector('[data-janela-motivo]').value = dados.motivo || '';", $abrir);
        self::assertStringContainsString('anexo.disabled = !podeTrocarAnexo;', $abrir);
        self::assertStringContainsString("anexo.value = '';", $abrir);
    }

    #[TestDox('`?esquecimento=`: o script do link clica o "+" depois do carregamento, quando o js/ponto-janelas.js já ouve o clique')]
    public function testEsquecimentoPeloEndereco(): void
    {
        $parcial = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/_justificativa_esquecimento_modal.html.twig');

        self::assertStringContainsString("if (alvo) { alvo.scrollIntoView({ block: 'center' }); alvo.click(); return; }", $parcial);
        self::assertStringContainsString("if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', abrirPedidoDoLink); }", $parcial);
        self::assertStringNotContainsString('bootstrap', $parcial);
    }

    #[TestDox('Trocar de aba atualiza a aba para onde as janelas de esquecimento e de justificativa voltam (desenho L768)')]
    public function testAbaDaVoltaAcompanhaAAbaAberta(): void
    {
        $gravar = self::trecho(self::abas(), 'function gravarAba(nome) {', 'function abrirAba(');

        self::assertStringContainsString("document.querySelectorAll('input[data-aba-da-volta]').forEach(function (campo) { campo.value = nome; });", $gravar);
        self::assertLessThan(strpos($gravar, "getElementById('competencia-ponto-index-form')"), strpos($gravar, 'data-aba-da-volta'), 'antes do retorno sem o formulário do mês');
    }

    private static function janelas(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-janelas.js');
    }

    private static function abas(): string
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
