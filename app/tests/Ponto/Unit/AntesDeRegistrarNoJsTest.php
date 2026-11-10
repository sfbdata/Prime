<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\AlertasAntesDaBatida;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A janela "Antes de registrar" (E11, P21) no script da tela do ponto e o mesmo alerta no atalho do cabeçalho, lidos no
 * texto: o PHPUnit não executa script. O HTML da janela e o servidor ficam no AlertasAntesDaBatidaControllerTest.
 */
#[Group('ponto')]
final class AntesDeRegistrarNoJsTest extends TestCase
{
    private const CAMINHO_TELA = __DIR__ . '/../../../templates/ponto/index.html.twig';
    private const CAMINHO_ATALHO = __DIR__ . '/../../../public/js/presenca-ponto.js';
    private const CAMINHO_CSS = __DIR__ . '/../../../public/css/ponto-eletronico.css';

    #[TestDox('O toque lê a confirmação logo depois de montar o envio, antes de qualquer espera: vale só para o toque do "Registrar assim mesmo"')]
    public function testConfirmacaoLidaNoToque(): void
    {
        $toque = $this->toque();

        self::assertMatchesRegularExpression(
            // No meio, só o corpo do trabalho externo da E12 (o tipo, a marca e o motivo), que a confirmação não desfaz.
            '/const payload = \{ tipo: tipoRegistro\.value, idEnvio: idDoEnvio\(\), horaAparelho \};\s*(?:\/\/[^\n]*\s*)*if \(externo !== null\) \{[^{}]*\}\s*aplicarConfirmacaoDoAlerta\(payload\);/',
            $toque,
        );
        $leitura = (int) strpos($toque, 'aplicarConfirmacaoDoAlerta(payload);');
        self::assertStringNotContainsString('await ', substr($toque, 0, $leitura), 'o click() do "Registrar assim mesmo" só roda o toque até a primeira espera');

        $aplicar = $this->corpoDaFuncao($this->tela(), 'function aplicarConfirmacaoDoAlerta(payload)');
        self::assertStringContainsString('payload.tipo = confirmacaoDoAlerta.tipo;', $aplicar);
        self::assertStringContainsString('payload.idEnvio = confirmacaoDoAlerta.idEnvio;', $aplicar, 'o mesmo envio do alerta (E13): nada foi gravado nele');
        self::assertStringContainsString('payload.' . AlertasAntesDaBatida::CAMPO_DA_CONFIRMACAO . ' = true;', $aplicar, 'o campo que o servidor lê');
        self::assertStringContainsString('confirmacaoDoAlerta = null;', $aplicar);
    }

    #[TestDox('A resposta com alerta abre a janela, depois da fila e antes do atraso, e não segue para os outros avisos')]
    public function testAlertaAbreAJanela(): void
    {
        $toque = $this->toque();
        $fila = strpos($toque, 'if (resultado.falhaDeRede && await guardarNaFila(payload)) {');
        // E8: pelo `mostrarAntesDeRegistrar`, que fecha a validação em tela cheia antes (testAlertaNoLugarDaTelaCheia).
        $alerta = strpos($toque, "if (resultado.tipo === 'recusa' && resultado.data && typeof resultado.data.alerta === 'string') {\n                mostrarAntesDeRegistrar(resultado.data.alerta, payload);\n                return;\n            }");
        $atraso = strpos($toque, "if (resultado.tipo === 'atrasado') {");

        self::assertIsInt($fila);
        self::assertIsInt($alerta);
        self::assertIsInt($atraso);
        self::assertGreaterThan($fila, $alerta);
        self::assertGreaterThan($alerta, $atraso);
    }

    #[TestDox('A janela usa a moldura das janelas da tela, escreve o alerta por textContent e põe o foco no "Aguardar"')]
    public function testJanelaPelaMoldura(): void
    {
        $abrir = $this->corpoDaFuncao($this->tela(), 'function abrirAntesDeRegistrar(texto, payload)');

        // E8: o foco volta ao título do cartão, nunca ao "Bater Ponto" (um Enter bateria o tipo sugerido).
        self::assertStringContainsString("window.PontoAbas.abrirJanela('ponto-janela-alerta', origemDaJanelaExterna())", $abrir);
        self::assertStringContainsString("fundo.querySelector('[data-alerta-texto]').textContent = String(texto);", $abrir);
        self::assertStringContainsString("fundo.querySelector('.pe-janela-cancelar').focus();", $abrir);
        self::assertStringContainsString('alertaEmEspera = { tipo: payload.tipo, idEnvio: payload.idEnvio };', $abrir);
        self::assertStringNotContainsString('innerHTML', $abrir);
        self::assertStringNotContainsString('bootstrap', $abrir);
    }

    #[TestDox('"Registrar assim mesmo" fecha a janela e toca no botão com a confirmação de uma vez só')]
    public function testRegistrarAssimMesmo(): void
    {
        $tela = $this->tela();

        self::assertStringContainsString("janelaDoAlerta.querySelector('[data-alerta-registrar]').addEventListener('click', function () {", $tela);
        self::assertMatchesRegularExpression(
            '/window\.PontoAbas\.fecharJanela\(\);\s*soltarAEsperaDaJanela\(\);[^\n]*\s*if \(!batida\) \{\s*return;\s*\}\s*if \(btnPonto\.disabled\) \{[^}]*\}\s*confirmacaoDoAlerta = batida;\s*trabalhoExternoDoToque = batida\.externo \|\| null;[^\n]*\s*btnPonto\.click\(\);\s*confirmacaoDoAlerta = null;\s*trabalhoExternoDoToque = null;/',
            $tela,
        );
    }

    #[TestDox('E8: a janela abre no lugar da validação em tela cheia, que fecha antes (o foco fica no "Aguardar"); as esperas da tela cheia seguem presas até a janela fechar')]
    public function testAlertaNoLugarDaTelaCheia(): void
    {
        $tela = $this->tela();

        // Segura antes de fechar a validação (o alerta de horário, o aviso flutuante, o lembrete e a recarga não saem no
        // meio), fecha a validação ANTES de abrir a janela (o fechamento leva o foco ao título do cartão; a janela o leva
        // ao "Aguardar") e entrega a espera à janela, que a solta ao fechar. Sem a moldura, `abrirAntesDeRegistrar` cai
        // na caixa e a janela segue escondida: a espera sai já.
        self::assertMatchesRegularExpression(
            "/^\{\s+const tela = window\.PontoTelaCheia;\s+const soltar = tela && typeof tela\.segurar === 'function' \? tela\.segurar\(\) : null;\s+try \{\s+"
            . "if \(tela\) \{\s+tela\.fecharSeValidando\(\);\s+\}\s+abrirAntesDeRegistrar\(texto, payload\);\s+"
            . "\} finally \{\s+soltarQuandoAJanelaFechar\('ponto-janela-alerta', soltar\);\s+\}\s+\}$/",
            $this->corpoDaFuncao($tela, 'function mostrarAntesDeRegistrar(texto, payload)'),
        );
        self::assertSame(2, substr_count($tela, 'mostrarAntesDeRegistrar('), 'a declaração e a resposta com alerta');
        self::assertSame(2, substr_count($tela, 'abrirAntesDeRegistrar('), 'a declaração e o `mostrarAntesDeRegistrar`: nenhuma abertura sem fechar a validação antes');
        self::assertStringContainsString("mostrarAvisoBatida(texto, 'warning');   // sem a moldura", $this->corpoDaFuncao($tela, 'function abrirAntesDeRegistrar(texto, payload)'));
        self::assertMatchesRegularExpression(
            "/const fundo = document\.getElementById\(id\);\s+if \(!fundo \|\| fundo\.hidden \|\| typeof MutationObserver !== 'function'\) \{\s+soltar\(\);\s+return;\s+\}/",
            $this->corpoDaFuncao($tela, 'function soltarQuandoAJanelaFechar(id, soltar)'),
            'sem a janela aberta, solta na hora',
        );
        self::assertStringContainsString('<div class="pe-janela-fundo" id="ponto-janela-alerta" hidden>', (string) file_get_contents(__DIR__ . '/../../../templates/ponto/_janela_antes_de_registrar.html.twig'), 'escondida até abrir: sem a moldura, a espera sai já');

        // "Registrar assim mesmo" solta antes do toque refeito, que abre a validação nova (a espera segue nela); as
        // saídas sem toque (`!batida`, botão cinza) já acham a espera solta.
        $registrar = $this->corpoDaFuncao($tela, "janelaDoAlerta.querySelector('[data-alerta-registrar]').addEventListener('click', function ()");
        $soltura = strpos($registrar, 'soltarAEsperaDaJanela();');
        self::assertIsInt($soltura);
        self::assertLessThan($soltura, (int) strpos($registrar, 'window.PontoAbas.fecharJanela();'));
        self::assertLessThan((int) strpos($registrar, 'if (!batida) {'), $soltura);
        self::assertLessThan((int) strpos($registrar, 'btnPonto.click();'), $soltura);
        // O toque abre a validação antes da primeira espera, então a soltura (conferida depois, no `segurar()`) já a acha.
        $toque = $this->toque();
        self::assertStringNotContainsString('await ', substr($toque, 0, (int) strpos($toque, 'iniciarValidacaoDoToque(payload.tipo);')));
    }

    #[TestDox('"Registrar assim mesmo" com o botão cinza (outro envio no meio ou o dia completo) não some calado: o aviso da batida diz que não saiu')]
    public function testRegistrarAssimMesmoComBotaoCinzaAvisa(): void
    {
        $tela = $this->tela();

        self::assertStringContainsString("const AVISO_ALERTA_DURANTE_ENVIO = 'A batida não foi registrada: outro registro estava sendo enviado. Espere terminar e toque no botão de novo.';", $tela);
        self::assertStringContainsString("const AVISO_ALERTA_DIA_CONCLUIDO = 'A batida não foi registrada: a jornada de hoje já está concluída.';", $tela);
        self::assertMatchesRegularExpression(
            "/if \(btnPonto\.disabled\) \{\s*mostrarAvisoBatida\(envioEmAndamento \? AVISO_ALERTA_DURANTE_ENVIO : AVISO_ALERTA_DIA_CONCLUIDO, 'warning'\);\s*return;\s*\}/",
            $tela,
            'o aviso é o da caixa da batida (#batida-aviso), nunca alert(), e nada é enviado',
        );
        $registrar = substr($tela, (int) strpos($tela, "querySelector('[data-alerta-registrar]').addEventListener"));
        self::assertStringNotContainsString('alert(', substr($registrar, 0, (int) strpos($registrar, 'btnPonto.click();')));
    }

    #[TestDox('Atalho do cabeçalho: com o alerta pergunta (a frase fixa da janela); confirmado, refaz a posição, a cerca e a releitura do estado comparada com o retrato do clique antes do segundo envio')]
    public function testAtalhoPergunta(): void
    {
        $js = (string) file_get_contents(self::CAMINHO_ATALHO);
        self::assertStringContainsString("var NOTA_DO_ALERTA = '" . AlertasAntesDaBatida::NOTA . "';", $js);
        self::assertStringContainsString("return resultado.tipo === 'recusa' && resultado.data && typeof resultado.data.alerta === 'string' ? resultado.data.alerta : null;", $js);

        $bater = $this->corpoDaFuncao($js, 'async function bater()');
        self::assertSame(1, substr_count($bater, 'PB.enviarBatida('), 'um caminho só de envio: o segundo é a volta do laço');
        $laco = $this->corpoDaFuncao($bater, 'for (;;)');
        $marcos = [
            'await PB.lerPosicaoComPrazo(POSICAO_PRAZO_MS',
            "if (onde.situacao === 'fora') { avisar(mensagemDaCerca(onde)); return; }",
            'var conferido = await lerEstado(RELEITURA_PRAZO_MS);',
            "if (conferido.estado !== base.estado || conferido.dia !== base.dia) {\n                    estado = conferido;\n                    aoMudar();\n                    avisar(AVISO_MUDOU);\n                    return;\n                }",
            'resultado = await PB.enviarBatida(urlBatida, payload, momentoDoToque);',
            'var alerta = confirmouAlerta ? null : alertaDe(resultado);',
            'if (alerta === null) { break; }',
            "if (!window.confirm(alerta + ' ' + NOTA_DO_ALERTA + ' Registrar assim mesmo?')) { return; }",
            'confirmouAlerta = true;',
            'momentoDoToque = performance.now();',
            'payload.horaAparelho = Date.now();',
            'payload.' . AlertasAntesDaBatida::CAMPO_DA_CONFIRMACAO . ' = true;',
        ];
        $ultima = -1;
        foreach ($marcos as $marco) {
            $pos = strpos($laco, $marco);
            self::assertNotFalse($pos, 'faltou no laço: ' . $marco);
            self::assertGreaterThan($ultima, $pos, 'fora de ordem: ' . $marco);
            $ultima = $pos;
        }
        $depois = strpos($bater, "if (resultado.tipo === 'sucesso') {");
        self::assertIsInt($depois);
        self::assertGreaterThan((int) strpos($bater, 'for (;;)') + strlen($laco), $depois, 'a classificação fica fora do laço');
    }

    #[TestDox('CSS da janela: o corpo do alerta e a nota abaixo dele, com os tokens da tela')]
    public function testCss(): void
    {
        $css = (string) file_get_contents(self::CAMINHO_CSS);

        self::assertStringContainsString('.pe-janela-alerta { padding: 14px 16px; font-size: 14px; line-height: 1.5; color: var(--pe-corpo); }', $css);
        self::assertStringContainsString('.pe-janela-alerta-nota { margin-top: 8px; font-size: 12.5px; color: var(--pe-mudo); }', $css);
    }

    private function tela(): string
    {
        $conteudo = file_get_contents(self::CAMINHO_TELA);
        self::assertNotFalse($conteudo);

        return $conteudo;
    }

    /** O corpo do toque no botão de bater o ponto. */
    private function toque(): string
    {
        return $this->corpoDaFuncao($this->tela(), "btnPonto.addEventListener('click', async function()");
    }

    /** Recorta o corpo de uma função pelo balanceamento de chaves. */
    private function corpoDaFuncao(string $fonte, string $assinatura): string
    {
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, sprintf('não achei `%s`', $assinatura));
        $abre = strpos($fonte, '{', $inicio + strlen($assinatura));
        self::assertIsInt($abre);
        $profundidade = 0;
        for ($i = $abre, $total = strlen($fonte); $i < $total; ++$i) {
            if ($fonte[$i] === '{') {
                ++$profundidade;
            } elseif ($fonte[$i] === '}' && --$profundidade === 0) {
                return substr($fonte, $abre, $i - $abre + 1);
            }
        }
        self::fail('corpo sem fechamento: ' . $assinatura);
    }
}
