<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * EDT-021/022 (docs/specs/engineering-loop/inventario-editor.md): o Enviar dos compositores fica
 * apagado com o editor vazio (bj-editor.js `mudou`: `this.env.disabled = v`; CSS
 * `.bjed-env:disabled{background:#a9c7e2}`) e, depois de enviar, volta ao rótulo do desenho —
 * só o texto "Enviar" (bj-editor.js l.358), nunca "ícone + Enviar".
 *
 * Teste de FOLHA (lê o JS, o CSS e os templates; sem navegador): trava o contrato entre o editor
 * e cada compositor. O comportamento na tela é do smoke.
 */
final class EditorRicoEnvioTest extends TestCase
{
    private const APP = __DIR__ . '/../../..';
    private const JS = self::APP . '/public/js/editor-rico.js';
    private const CSS = self::APP . '/public/css/editor-rico.css';

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js = $this->ler(self::JS);
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    private function ler(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);
        self::assertIsString($conteudo, "não leu {$caminho}");

        return $conteudo;
    }

    #[TestDox('o Enviar apaga pela MESMA regra de vazio do editor (só espaço ou <p><br></p> é vazio)')]
    public function testEnvioUsaARegraDeVazioDoEditor(): void
    {
        $atualizar = $this->funcao('atualizarEnvio');

        self::assertStringContainsString("getAttribute('data-editor-rico-envio')", $this->funcao('botaoDeEnvio'));
        self::assertStringContainsString('vazio(quill)', $atualizar);
        self::assertStringContainsString('_editorRicoEnviando === true', $atualizar, 'envio em curso precisa manter o botão apagado');

        // `vazio` olha o TEXTO (sem espaço), não o HTML — `<p><br></p>` não tem texto.
        self::assertStringContainsString("textoDoEditor(quill) === ''", $this->funcao('vazio'));
        self::assertStringContainsString('quill.getText()', $this->funcao('textoDoEditor'));
    }

    #[TestDox('o estado do Enviar é recalculado ao digitar, ao montar, ao trocar o conteúdo e ao desmontar')]
    public function testEnvioRecalculaEmTodaMudanca(): void
    {
        $js = $this->ler(self::JS);

        self::assertSame(1, preg_match("/quill\\.on\\('text-change', function \\(delta, anterior, origem\\) \\{(.*?)\n        \\}\\);/s", $js, $m));
        self::assertStringContainsString('atualizarEnvio(textarea);', $m[1], 'digitar não atualiza o Enviar');
        self::assertStringContainsString('atualizarEnvio(textarea);', $this->funcao('definirConteudo'), 'limpar depois de enviar não apaga o Enviar');
        self::assertStringContainsString('atualizarEnvio(textarea);', $this->funcao('desmontar'));
        self::assertStringContainsString('atualizarEnvio(textarea);', $this->funcao('montar'));
        self::assertStringContainsString('marcarEnvio: marcarEnvio,', $js, 'API pública sem marcarEnvio');
    }

    #[TestDox('Ctrl+Enter não envia quando o Enviar está apagado')]
    public function testAtalhoRespeitaOBotaoApagado(): void
    {
        self::assertStringContainsString('if (envio && envio.disabled) { return; }', $this->funcao('montar'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function compositores(): iterable
    {
        yield 'Registro (Dados)' => ['templates/pasta/_dados_anotacoes.html.twig', 'templates/pasta/show.html.twig', 'btnEnviarMensagem'];
        yield 'Relatório inicial (Detalhes)' => ['templates/pasta/_detalhes_obs.html.twig', 'templates/pasta/_detalhes_obs.html.twig', 'btnEnviarObsDetalhes'];
        yield 'Relatório financeiro' => ['templates/pasta/_financeiro.html.twig', 'templates/pasta/_financeiro_obs_script.html.twig', 'btnEnviarObsFinanceiro'];
    }

    #[DataProvider('compositores')]
    #[TestDox('$_dataName: a textarea aponta o seu Enviar e o envio devolve a decisão ao editor')]
    public function testCompositorLigaOEnviarAoEditor(string $marcacao, string $script, string $idBotao): void
    {
        $twig = $this->ler(self::APP . '/' . $marcacao);
        $js = $this->ler(self::APP . '/' . $script);

        self::assertStringContainsString('id="' . $idBotao . '"', $twig, "o botão {$idBotao} sumiu");
        $ligadoNaMarcacao = str_contains($twig, 'data-editor-rico-envio="' . $idBotao . '"');
        $ligadoNoScript = str_contains($js, "setAttribute('data-editor-rico-envio', btnEnviarObs.id)");
        self::assertTrue($ligadoNaMarcacao || $ligadoNoScript, 'textarea sem data-editor-rico-envio');

        // Enviando: o editor segura o botão apagado; terminado, ele decide (vazio = apagado).
        self::assertMatchesRegularExpression('/EditorRico\.marcarEnvio\(\w+, true\)/', $js);
        self::assertMatchesRegularExpression('/if \(window\.EditorRico\) \{ EditorRico\.marcarEnvio\(\w+, false\); \} else \{ \w+\.disabled = false; \}/', $js);
    }

    #[TestDox('depois de enviar, o Registro volta a "Enviar" só com texto, como o desenho e o estado inicial')]
    public function testRotuloDoRegistroDepoisDeEnviar(): void
    {
        $show = $this->ler(self::APP . '/templates/pasta/show.html.twig');

        self::assertStringNotContainsString("'<i class=\"bi bi-send me-1\"></i> Enviar'", $show);
        self::assertStringContainsString("btnEnviar.textContent = 'Enviar';", $show);
        self::assertStringContainsString("title=\"{{ 'pasta_documentos.anotacoes.enviar_dica'|trans }}\">{{ 'pasta_documentos.anotacoes.enviar'|trans }}</button>", $this->ler(self::APP . '/templates/pasta/_dados_anotacoes.html.twig'));
        $anotacoes = Yaml::parseFile(self::APP . '/translations/messages+intl-icu.pt_BR.yaml')['pasta_documentos']['anotacoes'];
        self::assertSame('Enviar (Ctrl+Enter)', $anotacoes['enviar_dica']);
        self::assertSame('Enviar', $anotacoes['enviar']);
    }

    #[TestDox('o Enviar apagado tem a cor do desenho (#a9c7e2) e cursor padrão')]
    public function testCssDoEnviarApagado(): void
    {
        self::assertSame(1, preg_match('/\.ps-compositor-rodape \.ps-btn\.ps-btn--primario:disabled,[^{]*\{([^}]*)\}/', $this->ler(self::CSS), $m));
        self::assertStringContainsString('background: #a9c7e2;', $m[1]);
        self::assertStringContainsString('cursor: default;', $m[1]);
    }
}
