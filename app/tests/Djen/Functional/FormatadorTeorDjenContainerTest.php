<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Service\FormatadorTeorDjen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guarda de FIAÇÃO — o furo que o teste unitário não pegava.
 *
 * `FormatadorTeorDjenTest` mocka o `HtmlSanitizerInterface`, então nunca exercita QUAL sanitizador o
 * contêiner injeta. Foi por isso que passou despercebido que o argumento se chamava `$djenSanitizer`
 * e o Symfony, não achando alias com esse nome, injetava o sanitizador `default` — que trunca a
 * entrada em 20 KB. Publicações longas chegavam cortadas, perdendo o final (o dispositivo).
 *
 * Estes testes usam o serviço REAL, montado pelo contêiner, para provar os dois efeitos da correção.
 */
#[CoversClass(FormatadorTeorDjen::class)]
final class FormatadorTeorDjenContainerTest extends KernelTestCase
{
    #[TestDox('teor longo (>20 KB) não é truncado — o sanitizador certo aceita até 1 MB')]
    public function testTeorLongoNaoETruncado(): void
    {
        self::bootKernel();
        $formatador = static::getContainer()->get(FormatadorTeorDjen::class);

        // ~32 KB, com um marcador no FIM (o dispositivo da decisão).
        $texto = '<p>INICIO DA SENTENCA. ' . str_repeat('palavra ', 4000) . ' DISPOSITIVO FINAL.</p>';
        self::assertGreaterThan(20000, \strlen($texto));

        $formatado = $formatador->formatar($texto);

        self::assertIsString($formatado);
        self::assertStringContainsString('DISPOSITIVO FINAL', $formatado, 'o final não pode ser cortado');
    }

    #[TestDox('o link é removido do resultado, mas o TEXTO dele é preservado')]
    public function testTextoDoLinkEPreservado(): void
    {
        self::bootKernel();
        $formatador = static::getContainer()->get(FormatadorTeorDjen::class);

        $formatado = $formatador->formatar('<p>veja em <a href="https://x.test/proc">este processo</a> hoje</p>');

        self::assertIsString($formatado);
        self::assertStringContainsString('este processo', $formatado, 'o texto do link não pode sumir junto com a tag');
        self::assertStringNotContainsString('<a ', $formatado, 'a tag do link sai (o botão da origem cobre o acesso)');
        self::assertStringNotContainsString('href', $formatado);
    }

    #[TestDox('o perigoso continua sendo removido')]
    public function testConteudoPerigosoERemovido(): void
    {
        self::bootKernel();
        $formatador = static::getContainer()->get(FormatadorTeorDjen::class);

        $formatado = $formatador->formatar('<p>teor</p><script>alert(1)</script><p onclick="x()">nota</p>');

        self::assertIsString($formatado);
        self::assertStringNotContainsStringIgnoringCase('<script', $formatado);
        self::assertStringNotContainsString('onclick', $formatado);
        self::assertStringContainsString('teor', $formatado);
        self::assertStringContainsString('nota', $formatado);
    }
}
