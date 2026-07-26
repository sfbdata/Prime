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

    /** @return iterable<string, array{string}> */
    public static function casosDeReferencia(): iterable
    {
        yield 'script' => ['<p>ok</p><script>alert(1)</script>'];
        yield 'handler' => ['<p onclick="x()">ok</p>'];
        yield 'style inline' => ['<p style="position:fixed">ok</p>'];
        yield 'link' => ['<a href="https://exemplo.test">link</a>'];
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
