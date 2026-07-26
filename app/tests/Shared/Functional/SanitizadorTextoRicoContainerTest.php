<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Shared\Service\SanitizadorTextoRico;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guarda contra desvio de configuração.
 *
 * Os testes unitários montam o sanitizador pelo trait `CriaSanitizadorTextoRico`, que espelha o
 * sanitizador `textoRico` de `config/packages/html_sanitizer.yaml`. Duas fontes para a mesma
 * verdade desviam com o tempo: alguém libera uma tag no YAML e a suíte unitária continua verde
 * testando outra coisa — ou o contrário, e a produção fica mais permissiva do que o teste prova.
 *
 * Aqui o serviço REAL (montado pelo contêiner, com a config do YAML) é comparado ao do trait nos
 * casos que importam. Se divergirem, este teste acusa.
 */
#[CoversClass(SanitizadorTextoRico::class)]
final class SanitizadorTextoRicoContainerTest extends KernelTestCase
{
    use CriaSanitizadorTextoRico;

    #[TestDox('o serviço do contêiner trata o conteúdo igual ao dos testes unitários')]
    #[DataProvider('casosDeReferencia')]
    public function testContainerEspelhaAConfiguracaoDosTestes(string $entrada): void
    {
        self::bootKernel();
        $doContainer = static::getContainer()->get(SanitizadorTextoRico::class);
        self::assertInstanceOf(SanitizadorTextoRico::class, $doContainer);

        self::assertSame(
            $this->criarSanitizadorTextoRico()->limpar($entrada),
            $doContainer->limpar($entrada),
            'A config do YAML divergiu do trait CriaSanitizadorTextoRico — alinhe as duas.',
        );
    }

    /**
     * O link entrou na barra em 2026-07-26 (SPEC UX §11.1), revertendo a exclusão anterior de `<a>`.
     * Liberar uma tag que carrega URL é o tipo de mudança que precisa de prova própria: o teste acima
     * só garante que contêiner e trait CONCORDAM — dois lugares podem concordar e ambos estarem
     * errados. Aqui afirmamos o comportamento seguro em si.
     */
    #[TestDox('o link liberado aceita http(s) e não deixa passar javascript: nem handlers')]
    public function testLinkLiberadoNaoAbreBrechaDeXss(): void
    {
        self::bootKernel();
        $sanitizador = static::getContainer()->get(SanitizadorTextoRico::class);
        self::assertInstanceOf(SanitizadorTextoRico::class, $sanitizador);

        // 1) Link legítimo sobrevive — é a razão de a tag ter sido liberada.
        $limpo = $sanitizador->limpar('<a href="https://exemplo.test/boleto">2ª via</a>');
        self::assertStringContainsString('https://exemplo.test/boleto', $limpo, 'link https tem de sobreviver');
        self::assertStringContainsString('2ª via', $limpo);

        // 2) `javascript:` NÃO sobrevive — o vetor clássico de XSS via href.
        $perigoso = $sanitizador->limpar('<a href="javascript:alert(document.cookie)">clique</a>');
        self::assertStringNotContainsStringIgnoringCase('javascript:', $perigoso, 'href javascript: não pode sobreviver');

        // 3) Handlers e `target` não entram, mesmo num link válido.
        $comHandler = $sanitizador->limpar('<a href="https://exemplo.test" target="_blank" onclick="roubar()">x</a>');
        self::assertStringNotContainsString('onclick', $comHandler);
        self::assertStringNotContainsString('target', $comHandler);

        // 4) `data:` também não — outro esquema executável em alguns contextos.
        self::assertStringNotContainsStringIgnoringCase(
            'data:text/html',
            $sanitizador->limpar('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function casosDeReferencia(): iterable
    {
        yield 'script' => ['<p>ok</p><script>alert(1)</script>'];
        yield 'handler' => ['<p onclick="x()">ok</p>'];
        yield 'style inline' => ['<p style="position:fixed">ok</p>'];
        yield 'link' => ['<a href="https://exemplo.test">link</a>'];
        yield 'link javascript' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'link com target e handler' => ['<a href="https://x.test" target="_blank" onclick="y()">z</a>'];
        yield 'link http (forçado a https)' => ['<a href="http://exemplo.test">link</a>'];
        yield 'imagem' => ['<img src="x.png">'];
        yield 'ênfase' => ['<p><strong>a</strong><em>b</em><u>c</u><s>d</s></p>'];
        yield 'títulos' => ['<h1>a</h1><h2>b</h2><h3>c</h3>'];
        yield 'listas' => ['<ul><li>a</li></ul><ol><li data-list="ordered">b</li></ol>'];
        yield 'citação' => ['<blockquote>a</blockquote>'];
        yield 'classes do editor' => ['<p class="ql-align-center ql-indent-1"><span class="ql-color-red">a</span></p>'];
        yield 'classe estranha' => ['<p class="d-none">a</p>'];
        yield 'tabela (fora do escopo)' => ['<table><tr><td>a</td></tr></table>'];
        yield 'texto puro' => ['Cliente & Cia'];
    }
}
