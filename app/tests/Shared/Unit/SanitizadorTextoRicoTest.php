<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\SanitizadorTextoRico;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O sanitizador é a única barreira entre o que o usuário escreve no editor e o que volta para a
 * tela como HTML. Estes testes provam as duas metades: o que precisa MORRER (script, handler,
 * style, link) e o que precisa SOBREVIVER (a formatação que a barra oferece) — mais o legado em
 * texto puro, que não pode regredir.
 *
 * A config aqui espelha `config/packages/html_sanitizer.yaml` (sanitizador `textoRico`); um teste
 * funcional confere que o serviço real recebe essa mesma configuração pelo contêiner.
 */
#[CoversClass(SanitizadorTextoRico::class)]
final class SanitizadorTextoRicoTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private SanitizadorTextoRico $sanitizador;

    protected function setUp(): void
    {
        $this->sanitizador = $this->criarSanitizadorTextoRico();
    }

    // ── O que precisa MORRER ─────────────────────────────────────────────────────────────────

    #[TestDox('remove script, handler de evento, style e link — o núcleo do XSS armazenado')]
    #[DataProvider('cargasMaliciosas')]
    public function testDescartaCargaMaliciosa(string $entrada, string $naoPodeConter): void
    {
        $limpo = $this->sanitizador->limpar($entrada);

        self::assertIsString($limpo);
        self::assertStringNotContainsStringIgnoringCase($naoPodeConter, $limpo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function cargasMaliciosas(): iterable
    {
        yield 'tag script' => ['<p>oi</p><script>alert(1)</script>', '<script'];
        yield 'handler inline' => ['<p onclick="alert(1)">oi</p>', 'onclick'];
        yield 'handler em span' => ['<span onmouseover="alert(1)">oi</span>', 'onmouseover'];
        yield 'style inline' => ['<p style="position:fixed;top:0">oi</p>', 'style'];
        yield 'link javascript' => ['<a href="javascript:alert(1)">clique</a>', 'javascript:'];
        yield 'iframe' => ['<iframe src="https://evil.test"></iframe>', '<iframe'];
        yield 'imagem com onerror' => ['<img src=x onerror="alert(1)">', 'onerror'];
        yield 'svg com script' => ['<svg><script>alert(1)</script></svg>', '<script'];
        yield 'form' => ['<form action="/x"><input name="a"></form>', '<form'];
    }

    #[TestDox('descarta classe que não é do editor — senão dá para injetar utilitário do Bootstrap')]
    public function testDescartaClasseForaDoEditor(): void
    {
        $limpo = $this->sanitizador->limpar('<p class="d-none position-fixed">sumiu</p>');

        self::assertIsString($limpo);
        self::assertStringNotContainsString('d-none', $limpo);
        self::assertStringNotContainsString('position-fixed', $limpo);
        self::assertStringContainsString('sumiu', $limpo);
    }

    // ── O que precisa SOBREVIVER ─────────────────────────────────────────────────────────────

    #[TestDox('mantém a formatação que a barra oferece')]
    #[DataProvider('formatacaoLegitima')]
    public function testPreservaFormatacaoLegitima(string $entrada, string $precisaConter): void
    {
        $limpo = $this->sanitizador->limpar($entrada);

        self::assertIsString($limpo);
        self::assertStringContainsString($precisaConter, $limpo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function formatacaoLegitima(): iterable
    {
        yield 'negrito' => ['<p><strong>importante</strong></p>', '<strong>importante</strong>'];
        yield 'itálico' => ['<p><em>obs</em></p>', '<em>obs</em>'];
        yield 'sublinhado' => ['<p><u>prazo</u></p>', '<u>prazo</u>'];
        yield 'tachado' => ['<p><s>cancelado</s></p>', '<s>cancelado</s>'];
        yield 'título' => ['<h2>Resumo</h2>', '<h2>Resumo</h2>'];
        yield 'lista com marcador' => ['<ul><li>um</li></ul>', '<li>um</li>'];
        yield 'lista numerada' => ['<ol><li data-list="ordered">um</li></ol>', 'data-list="ordered"'];
        yield 'citação' => ['<blockquote>dito</blockquote>', '<blockquote>dito</blockquote>'];
        yield 'quebra de linha' => ['<p>a<br>b</p>', '<br'];
        yield 'alinhamento por classe' => ['<p class="ql-align-center">meio</p>', 'ql-align-center'];
        yield 'recuo por classe' => ['<p class="ql-indent-1">recuado</p>', 'ql-indent-1'];
        yield 'cor por classe' => ['<span class="ql-color-red">vermelho</span>', 'ql-color-red'];
    }

    // ── Legado em texto puro (não pode regredir) ─────────────────────────────────────────────

    #[TestDox('legado multilinha continua exibido com as quebras de linha')]
    public function testLegadoMultilinhaPreservaQuebras(): void
    {
        $exibicao = $this->sanitizador->paraExibicao("primeira linha\nsegunda linha");

        // `<br>` + a quebra original preservada: é byte a byte o que o `|nl2br` do Twig já produzia
        // nestas telas. A paridade é o ponto — o legado precisa continuar exibido igual.
        self::assertSame("primeira linha<br>\nsegunda linha", $exibicao);
    }

    #[TestDox('legado é escapado — texto puro nunca vira marcação')]
    public function testLegadoEEscapado(): void
    {
        $exibicao = $this->sanitizador->paraExibicao('Cliente & Cia — valor "alto"');

        self::assertIsString($exibicao);
        self::assertStringContainsString('&amp;', $exibicao);
        self::assertStringNotContainsString(' & ', $exibicao);
    }

    #[TestDox('conteúdo novo (do editor) é reexibido como HTML, já limpo')]
    public function testConteudoRicoEExibidoComoHtml(): void
    {
        $exibicao = $this->sanitizador->paraExibicao('<p><strong>ok</strong></p><script>alert(1)</script>');

        self::assertIsString($exibicao);
        self::assertStringContainsString('<strong>ok</strong>', $exibicao);
        self::assertStringNotContainsStringIgnoringCase('<script', $exibicao);
    }

    #[TestDox('null continua null — campo opcional não vira string vazia')]
    public function testNullPermaneceNull(): void
    {
        self::assertNull($this->sanitizador->limpar(null));
        self::assertNull($this->sanitizador->paraExibicao(null));
    }

    #[TestDox('texto puro atravessa limpar() intacto — senão o & seria escapado duas vezes')]
    public function testTextoPuroNaoEAlteradoAoLimpar(): void
    {
        // Sem `<` não há marcação possível, logo não há o que sanitizar. Se fosse sanitizado,
        // `&` viraria `&amp;` no banco e a exibição (que trata conteúdo sem `<` como legado)
        // escaparia de novo — `&amp;amp;` na tela.
        $entrada = "Cliente & Cia\nvalor \"alto\" > 100";

        self::assertSame($entrada, $this->sanitizador->limpar($entrada));
    }

    #[TestDox('texto puro sobrevive ao ciclo completo gravar → exibir')]
    public function testTextoPuroSobreviveAoCicloCompleto(): void
    {
        $exibicao = $this->sanitizador->paraExibicao($this->sanitizador->limpar('Cliente & Cia'));

        self::assertSame('Cliente &amp; Cia', $exibicao);
    }

    #[TestDox('o limite de caracteres conta o texto visível, não a marcação')]
    public function testComprimentoIgnoraMarcacao(): void
    {
        self::assertSame(4, $this->sanitizador->comprimentoDoTexto('<p><strong>2026</strong></p>'));
        self::assertSame(0, $this->sanitizador->comprimentoDoTexto('<p><br></p>'));
        self::assertSame(0, $this->sanitizador->comprimentoDoTexto(null));

        // Mesmo texto, um formatado e outro não: o limite precisa tratá-los igual.
        $semFormato = str_repeat('a', 300);
        $comFormato = '<p><strong>' . $semFormato . '</strong></p>';

        self::assertSame(
            $this->sanitizador->comprimentoDoTexto($semFormato),
            $this->sanitizador->comprimentoDoTexto($comFormato),
        );
    }

    // ── Vazio do editor (o "<p><br></p>" que parece preenchido) ──────────────────────────────

    #[TestDox('reconhece como vazio o que o editor produz quando nada foi digitado')]
    #[DataProvider('conteudosVazios')]
    public function testReconheceVazio(?string $entrada): void
    {
        self::assertTrue($this->sanitizador->estaVazio($entrada));
    }

    /** @return iterable<string, array{?string}> */
    public static function conteudosVazios(): iterable
    {
        yield 'null' => [null];
        yield 'string vazia' => [''];
        yield 'só espaços' => ['   '];
        yield 'parágrafo vazio do Quill' => ['<p><br></p>'];
        yield 'vários parágrafos vazios' => ['<p><br></p><p><br></p>'];
        yield 'só espaço rígido' => ['<p>&nbsp;</p>'];
        yield 'só formatação sem texto' => ['<p><strong></strong></p>'];
    }

    #[TestDox('não confunde conteúdo real com vazio')]
    #[DataProvider('conteudosPreenchidos')]
    public function testReconhecePreenchido(string $entrada): void
    {
        self::assertFalse($this->sanitizador->estaVazio($entrada));
    }

    /** @return iterable<string, array{string}> */
    public static function conteudosPreenchidos(): iterable
    {
        yield 'texto simples' => ['<p>oi</p>'];
        yield 'texto puro legado' => ['observação antiga'];
        yield 'só um caractere' => ['<p>x</p>'];
        yield 'texto dentro de formatação' => ['<p><strong>importante</strong></p>'];
    }
}
