<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * C6-IA-01: no drawer, baixar e imprimir avisam o servidor (POST `inteligencia_analise_registro`)
 * para o "Registro de auditoria"; copiar, não (desenho, dc L5741-5743). Regex não executa JS: isto
 * prova que as peças estão no lugar, não que o navegador as usa. O comportamento é smoke do dono.
 */
#[Group('inteligencia')]
final class PastaIaAgentesRegistroJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-ia-agentes.js';

    private function js(): string
    {
        $conteudo = file_get_contents(self::JS);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    /** O corpo do listener de clique que trata `$seletor`, até o `});` que o fecha (8 espaços). */
    private function listenerDe(string $seletor): string
    {
        $js = $this->js();
        $inicio = strpos($js, "closest('" . $seletor . "')");
        self::assertNotFalse($inicio, $seletor);
        $fim = strpos($js, "\n        });\n", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('o registro é um POST com o token do cartão e a ação, que não espera a página (keepalive) e não avisa erro')]
    public function testRegistrarExportacao(): void
    {
        $js = $this->js();
        $inicio = strpos($js, 'function registrarExportacao(el, acao) {');
        self::assertNotFalse($inicio);
        $corpo = substr($js, $inicio, (int) strpos($js, "\n        }\n", $inicio) - $inicio);

        self::assertStringContainsString("getAttribute('data-ia-registro-url')", $corpo);
        self::assertStringContainsString("dados.append('_token', cartao.getAttribute('data-ia-registro-token')", $corpo);
        self::assertStringContainsString("dados.append('acao', acao);", $corpo);
        self::assertStringContainsString("method: 'POST'", $corpo);
        self::assertStringContainsString('keepalive: true', $corpo);
        self::assertStringContainsString('recarregarAuditoria();', $corpo, 'o rodapé relê depois de registrar');
        self::assertStringNotContainsString('avisar(', $corpo, 'falha do registro não atrapalha quem baixou');
    }

    #[TestDox('baixar dispara o registro "download" sem impedir o link de baixar')]
    public function testBaixarRegistra(): void
    {
        $trecho = $this->listenerDe('[data-ia-baixar]');

        self::assertStringContainsString("registrarExportacao(link, 'download');", $trecho);
        self::assertStringNotContainsString('preventDefault', $trecho, 'o .txt continua saindo pelo link comum');
    }

    #[TestDox('imprimir dispara o registro "impressao" depois de abrir a janela (pop-up bloqueado não registra)')]
    public function testImprimirRegistra(): void
    {
        $trecho = $this->listenerDe('[data-ia-imprimir]');

        $abre = strpos($trecho, "window.open('', '_blank')");
        $bloqueado = strpos($trecho, 'if (!janela) {');
        $registra = strpos($trecho, "registrarExportacao(botao, 'impressao');");
        self::assertNotFalse($abre);
        self::assertNotFalse($bloqueado);
        self::assertNotFalse($registra);
        self::assertGreaterThan($bloqueado, $registra);
    }

    #[TestDox('copiar não registra nada')]
    public function testCopiarNaoRegistra(): void
    {
        $js = $this->js();
        $inicio = strpos($js, 'function copiar(url) {');
        self::assertNotFalse($inicio);
        $funcao = substr($js, $inicio, (int) strpos($js, "\n        }\n", $inicio) - $inicio);

        self::assertStringNotContainsString('registrarExportacao', $funcao);
        self::assertStringNotContainsString('registrarExportacao', $this->listenerDe('[data-ia-copiar]'));
        self::assertSame(3, substr_count($js, 'registrarExportacao('), 'a definição e as duas chamadas (baixar, imprimir); nenhuma outra');
    }
}
