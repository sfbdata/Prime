<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O comportamento do painel da aba Equipe (E5) mora em `public/js/ponto-equipe.js`. O PHPUnit não executa script:
 * estes testes travam as escolhas de que a tela depende (o smoke no navegador é do dono).
 */
#[Group('ponto')]
#[CoversNothing]
final class PontoEquipeJsTest extends TestCase
{
    #[TestDox('Trocar a escala: um fetch só, POST com prazo próprio, sem esperar para sempre')]
    public function testTrocaComPrazo(): void
    {
        $js = self::js();
        $trocar = self::trecho($js, 'async function trocarEscala(select) {', 'function ligarAbonar(form) {');

        self::assertSame(1, substr_count($js, 'fetch('), 'um fetch só');
        self::assertStringContainsString('fetch(select.dataset.url, {', $trocar);
        self::assertStringContainsString("method: 'POST'", $trocar);
        self::assertStringContainsString('body: JSON.stringify({ escala: select.value })', $trocar);
        self::assertStringContainsString('signal: aborto.signal', $trocar);
        self::assertStringContainsString('setTimeout(function () { aborto.abort(); }, ESCALA_PRAZO_MS)', $trocar);
        self::assertStringContainsString('clearTimeout(prazo);', $trocar);
        self::assertStringContainsString('const ESCALA_PRAZO_MS = 15000;', $js);
    }

    #[TestDox('Deu certo: o aviso só para leitor de tela; recusa ou sem resposta: a frase no topo e a escolha volta')]
    public function testResultadoDaTroca(): void
    {
        $trocar = self::trecho(self::js(), 'async function trocarEscala(select) {', 'function ligarAbonar(form) {');

        self::assertStringContainsString("anunciar(painel, 'Escala de ' + select.dataset.nome + ' alterada.');", $trocar);
        self::assertStringContainsString('fixarAtual(select);', $trocar);
        self::assertStringContainsString('falha = dados.erro || TEXTO_RECUSA;', $trocar);
        self::assertStringContainsString('anterior.selected = true;', $trocar);
        self::assertStringContainsString('mostrarErro(painel, falha);', $trocar);
        self::assertStringContainsString("querySelector('[data-equipe-erro]')", self::js());
        self::assertStringContainsString("querySelector('[data-equipe-aviso]')", self::js());
    }

    #[TestDox('"Abonar selecionadas" só liga com alguma marcada; enviar sem marca não sai da tela')]
    public function testAbonar(): void
    {
        $js = self::js();

        self::assertStringContainsString("botao.disabled = form.querySelector('input[name=\"ids[]\"]:checked') === null;", $js);
        self::assertStringContainsString("document.addEventListener('submit', function (evento) {", $js);
        self::assertStringContainsString('evento.preventDefault();', self::trecho($js, "document.addEventListener('submit'", "document.addEventListener('ponto:painel-carregado'"));
    }

    #[TestDox('Tudo por delegação no documento: o painel chega depois e scripts dentro dele não rodam')]
    public function testDelegacao(): void
    {
        $js = self::js();

        self::assertStringContainsString("document.addEventListener('change', function (evento) {", $js);
        self::assertStringContainsString("document.addEventListener('ponto:painel-carregado', function (evento) {", $js);
        self::assertStringNotContainsString("getElementById('ponto-painel-equipe').addEventListener", $js);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $js, 'sem travessão');
    }

    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-equipe.js');
    }

    private static function trecho(string $fonte, string $inicio, string $fim): string
    {
        $de = strpos($fonte, $inicio);
        self::assertIsInt($de, $inicio);
        $ate = strpos($fonte, $fim, $de);
        self::assertIsInt($ate, $fim);

        return substr($fonte, $de, $ate - $de);
    }
}
