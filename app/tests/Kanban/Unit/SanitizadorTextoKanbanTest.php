<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Kanban\Service\SanitizadorTextoKanban;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * O Kanban gravava o HTML do Quill CRU e o exibia com `|raw` — XSS armazenado: um usuário punha
 * `<script>` na descrição de um card e ele rodava no navegador de todo colega que abrisse o card.
 * Estes testes provam a barreira: o que precisa MORRER (script, handler, style, link perigoso) e o
 * que precisa SOBREVIVER (a formatação que a barra do Kanban oferece — inclusive LINK e código, que
 * a distinguem do editor rico das anotações).
 *
 * A config aqui espelha o sanitizador `kanban` de `config/packages/html_sanitizer.yaml`.
 */
#[CoversClass(SanitizadorTextoKanban::class)]
final class SanitizadorTextoKanbanTest extends TestCase
{
    private SanitizadorTextoKanban $sanitizador;

    protected function setUp(): void
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('ol')
            ->allowElement('ul')
            ->allowElement('li', ['data-list'])
            ->allowElement('pre')
            ->allowElement('code')
            ->allowElement('a', ['href', 'title'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceHttpsUrls(true);

        $this->sanitizador = new SanitizadorTextoKanban(new HtmlSanitizer($config));
    }

    // ── O que precisa MORRER ─────────────────────────────────────────────────────────────────

    #[TestDox('remove o núcleo do XSS armazenado que o Kanban tinha')]
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
        yield 'script no card' => ['<p>ok</p><script>alert(document.cookie)</script>', '<script'];
        yield 'handler inline' => ['<p onclick="roubar()">ok</p>', 'onclick'];
        yield 'style inline' => ['<p style="position:fixed;inset:0">ok</p>', 'style'];
        yield 'link javascript' => ['<a href="javascript:alert(1)">clique</a>', 'javascript:'];
        yield 'link data' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'data:'];
        yield 'iframe' => ['<iframe src="https://evil.test"></iframe>', '<iframe'];
        yield 'img com onerror' => ['<img src=x onerror="alert(1)">', 'onerror'];
        yield 'classe utilitária em pre' => ['<pre class="position-fixed vh-100">x</pre>', 'position-fixed'];
        yield 'target no link' => ['<a href="https://ok.test" target="_blank">x</a>', 'target'];
    }

    // ── O que precisa SOBREVIVER ─────────────────────────────────────────────────────────────

    #[TestDox('mantém a formatação da barra do Kanban, inclusive link e código')]
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
        yield 'negrito' => ['<p><strong>urgente</strong></p>', '<strong>urgente</strong>'];
        yield 'itálico' => ['<p><em>nota</em></p>', '<em>nota</em>'];
        yield 'sublinhado' => ['<p><u>prazo</u></p>', '<u>prazo</u>'];
        yield 'título' => ['<h2>Resumo</h2>', '<h2>Resumo</h2>'];
        yield 'lista' => ['<ul><li>item</li></ul>', '<li>item</li>'];
        yield 'bloco de código' => ['<pre>composer install</pre>', '<pre>composer install</pre>'];
        yield 'código inline' => ['<p><code>bin/phpunit</code></p>', '<code>bin/phpunit</code>'];
        yield 'link https preservado' => ['<a href="https://jus.com.br/x">processo</a>', 'href="https://jus.com.br/x"'];
    }

    #[TestDox('link http é promovido a https (force_https) — não é descartado')]
    public function testLinkHttpViraHttps(): void
    {
        $limpo = $this->sanitizador->limpar('<a href="http://exemplo.test/doc">doc</a>');

        self::assertIsString($limpo);
        self::assertStringContainsString('https://exemplo.test/doc', $limpo);
        self::assertStringContainsString('doc</a>', $limpo);
    }

    // ── Exibição e vazio ─────────────────────────────────────────────────────────────────────

    #[TestDox('paraExibicao sanitiza de novo — cobre o que já foi gravado cru antes desta correção')]
    public function testParaExibicaoSanitizaConteudoLegadoPerigoso(): void
    {
        // Simula um card gravado ANTES do fix, com script já no banco.
        $exibicao = $this->sanitizador->paraExibicao('<p>tarefa</p><script>alert(1)</script>');

        self::assertIsString($exibicao);
        self::assertStringContainsString('tarefa', $exibicao);
        self::assertStringNotContainsStringIgnoringCase('<script', $exibicao);
    }

    #[TestDox('null continua null')]
    public function testNullPermaneceNull(): void
    {
        self::assertNull($this->sanitizador->limpar(null));
        self::assertNull($this->sanitizador->paraExibicao(null));
    }

    #[TestDox('reconhece como vazio o que o Quill entrega sem texto')]
    #[DataProvider('conteudosVazios')]
    public function testReconheceVazio(?string $entrada): void
    {
        self::assertTrue($this->sanitizador->estaVazio($entrada));
    }

    /** @return iterable<string, array{?string}> */
    public static function conteudosVazios(): iterable
    {
        yield 'null' => [null];
        yield 'vazio' => [''];
        yield 'parágrafo vazio do Quill' => ['<p><br></p>'];
        yield 'só espaço rígido' => ['<p>&nbsp;</p>'];
    }

    #[TestDox('conteúdo real não é confundido com vazio')]
    public function testConteudoRealNaoEVazio(): void
    {
        self::assertFalse($this->sanitizador->estaVazio('<p>combinar com o cliente</p>'));
    }
}
