<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Tarefa\Service\TextoDoDespacho;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** C10: o campo é simples, mas o despacho é guardado como HTML saneado (parágrafos e quebras). */
#[CoversClass(TextoDoDespacho::class)]
final class TextoDoDespachoTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    #[TestDox('Linha em branco separa parágrafos; quebra simples vira <br>')]
    public function testParagrafosEQuebras(): void
    {
        $html = (new TextoDoDespacho($this->criarSanitizadorTextoRico()))->paraHtml("Cumpra-se:\njuntar a procuração.\r\n\r\nPrazo curto.");

        self::assertSame('<p>Cumpra-se:<br />juntar a procuração.</p><p>Prazo curto.</p>', $html);
    }

    #[TestDox('Marcação digitada é escapada, nunca interpretada')]
    public function testEscapa(): void
    {
        $html = (new TextoDoDespacho($this->criarSanitizadorTextoRico()))->paraHtml('<script>alert(1)</script> & <b>x</b>');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    #[TestDox('normalizar troca CRLF por LF e apara as pontas')]
    public function testNormalizar(): void
    {
        self::assertSame("a\nb", TextoDoDespacho::normalizar("  a\r\nb \n"));
    }
}
