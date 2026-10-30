<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O JS do Chat I.A lido como texto (padrão de CentralJsTest): sem caixas nativas, sem HTML montado em string (a resposta
 * da IA é dado não confiável), CSRF no cabeçalho, parada por AbortController, os três eventos do SSE e links só
 * http, https e mailto. O comportamento na tela é do smoke do dono.
 */
final class ChatIaJsTest extends TestCase
{
    private function js(): string
    {
        // O renderer de markdown mora em chat-ia-markdown.js (compartilhado com o mini chat); as garantias valem para os dois.
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/chat-ia.js')
            . "\n" . (string) file_get_contents(__DIR__ . '/../../../public/js/chat-ia-markdown.js');
        self::assertNotSame('', $js);

        return $js;
    }

    #[TestDox('Envio imediato lê anexos e pasta ANTES de prepararTela esvaziar o estado (o servidor recebe anexoIds e pastaId)')]
    public function testEnvioImediatoLeOEstadoAntesDeLimpar(): void
    {
        $js = $this->js();
        $leitura = strpos($js, 'enviadosAgora = estado.anexos.filter');
        $limpeza = strpos($js, 'm = prepararTela(op, texto, enviadosAgora, pastaAgora)');
        self::assertNotFalse($leitura);
        self::assertNotFalse($limpeza);
        self::assertLessThan($limpeza, $leitura);
        self::assertStringContainsString('var enviados = otimista ? enviadosAgora', $js);
        self::assertStringContainsString('corpo.anexoIds = enviados.map', $js);
    }

    #[TestDox('Nenhum alert/confirm/prompt nativo')]
    public function testSemCaixaNativa(): void
    {
        self::assertDoesNotMatchRegularExpression('/\b(window\.)?(alert|confirm|prompt)\s*\(/', $this->js());
    }

    #[TestDox('Nenhum innerHTML, outerHTML, insertAdjacentHTML nem document.write')]
    public function testSemHtmlEmString(): void
    {
        self::assertDoesNotMatchRegularExpression('/\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML|document\.write/', $this->js());
        self::assertStringNotContainsString('innerHTML', $this->js());
    }

    #[TestDox('Toda chamada leva o X-CSRF-Token e o botão Parar usa AbortController')]
    public function testCsrfEParar(): void
    {
        $js = $this->js();
        self::assertGreaterThanOrEqual(3, substr_count($js, "'X-CSRF-Token': csrf"), 'JSON, envio da mensagem e anexo');
        self::assertStringContainsString('new AbortController()', $js);
        self::assertStringContainsString('signal: ctl ? ctl.signal', $js);
        self::assertStringContainsString('getReader()', $js);
        self::assertStringContainsString('TextDecoder', $js);
    }

    #[TestDox('O parser de SSE trata delta, fim e erro e lê event:/data:')]
    public function testParserDeSse(): void
    {
        $js = $this->js();
        self::assertStringContainsString("evento === 'delta'", $js);
        self::assertStringContainsString("evento === 'fim'", $js);
        self::assertStringContainsString("evento === 'erro'", $js);
        self::assertStringContainsString("evento === 'proposta'", $js);
        self::assertStringContainsString("indexOf('event:')", $js);
        self::assertStringContainsString("indexOf('data:')", $js);
        self::assertStringContainsString('e.isComposing', $js, 'Enter respeita a composição de IME');
    }

    #[TestDox('Links só http, https e mailto, sempre com rel noopener noreferrer em nova aba')]
    public function testLinksRestritos(): void
    {
        $js = $this->js();
        self::assertStringContainsString('/^(https?:\/\/|mailto:)/i', $js);
        self::assertStringNotContainsString('javascript:', $js);
        self::assertStringContainsString("a.rel = 'noopener noreferrer'", $js);
        self::assertStringContainsString("a.target = '_blank'", $js);
        self::assertDoesNotMatchRegularExpression('/\.href\s*=\s*m\[(4)\]/', $js, 'o href do markdown passa por urlSegura');
    }

    #[TestDox('localStorage sempre dentro de try/catch e sem travessão em texto')]
    public function testArmazenamentoETravessao(): void
    {
        $js = $this->js();
        self::assertSame(2, substr_count($js, 'localStorage'), 'só em guardar() e ler()');
        self::assertMatchesRegularExpression('/try \{ window\.localStorage\.setItem[^\n]*catch/', $js);
        self::assertMatchesRegularExpression('/try \{ return window\.localStorage\.getItem[^\n]*catch/', $js);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $js);
    }

    #[TestDox('O cartão da proposta só usa textContent e createElement, confirma e cancela pelas URLs do servidor')]
    public function testCartaoDaProposta(): void
    {
        $js = $this->js();
        self::assertStringContainsString('function adicionarCartao', $js);
        self::assertStringContainsString('urls.propostaConfirmar', $js);
        self::assertStringContainsString('urls.propostaCancelar', $js);
        self::assertStringContainsString("campo.value = grupo === 'campos' ? dataParaTela(chave, valor) : valor", $js, 'o valor do campo entra como propriedade, nunca como HTML');
        self::assertStringContainsString('{ usarExistente: true, criarMesmoAssim: true }', $js, '"Abrir nova pasta com o cliente existente" com pasta parecida segue sem depender do Confirmar escondido');
        self::assertStringContainsString('dataParaServidor(k, i.value)', $js, 'a data digitada em dd/mm/aaaa volta ao servidor como AAAA-MM-DD');
    }
}
