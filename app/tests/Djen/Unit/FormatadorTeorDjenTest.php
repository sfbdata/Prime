<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Service\FormatadorTeorDjen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

#[CoversClass(FormatadorTeorDjen::class)]
final class FormatadorTeorDjenTest extends TestCase
{
    private HtmlSanitizerInterface&MockObject $sanitizer;
    private FormatadorTeorDjen $sut;

    protected function setUp(): void
    {
        $this->sanitizer = $this->createMock(HtmlSanitizerInterface::class);
        $this->sut = new FormatadorTeorDjen($this->sanitizer);
    }

    #[Test]
    public function textoCorridoGanhaQuebrasNosRotulosDoDocumento(): void
    {
        // Bloco sem formatação (caso TJDFT): a sanitização NÃO é chamada.
        $this->sanitizer->expects($this->never())->method('sanitize');

        $bruto = 'Poder Judiciário Número do processo: 0702867-57.2022.8.07.0009 '
            . 'Classe judicial: PROCEDIMENTO COMUM AUTOR: MARIA REU: DAVID SENTENÇA MARIA ajuizou a ação.';

        $out = (string) $this->sut->formatar($bruto);

        self::assertStringContainsString('<br>Número do processo:', $out);
        self::assertStringContainsString('<br>Classe judicial:', $out);
        self::assertStringContainsString('<br>AUTOR:', $out);
        self::assertStringContainsString('<br>REU:', $out);
        self::assertStringContainsString('<br>SENTENÇA', $out);
    }

    #[Test]
    public function espacosDuplosViramQuebraDeLinha(): void
    {
        $this->sanitizer->expects($this->never())->method('sanitize');

        $out = (string) $this->sut->formatar('PODER JUDICIÁRIO  JUSTIÇA DO TRABALHO  2ª Vara');

        self::assertStringContainsString('PODER JUDICIÁRIO<br>JUSTIÇA DO TRABALHO<br>2ª Vara', $out);
    }

    #[Test]
    public function teorComHtmlEhSanitizadoSemPrettify(): void
    {
        $bruto = '<section><b>Título</b></section><table><tr><td>REQUERENTE</td></tr></table>';
        $this->sanitizer->expects($this->once())->method('sanitize')->with($bruto)->willReturn('<table>OK</table>');

        self::assertSame('<table>OK</table>', $this->sut->formatar($bruto));
    }

    #[Test]
    public function textoCorridoComTagPerigosaEhEscapado(): void
    {
        // "<script>" não está na lista de tags de formatação → cai no ramo texto-corrido → é escapado.
        $this->sanitizer->expects($this->never())->method('sanitize');

        $out = (string) $this->sut->formatar('Intimação <script>alert(1)</script> do processo.');

        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
    }

    #[Test]
    public function textoCorridoComEntidadesHtmlMostraOsAcentos(): void
    {
        // Caso TJGO (09/11): texto corrido com entidades. Antes saía "JUDICI&amp;Aacute;RIO" e a
        // tela mostrava "JUDICI&Aacute;RIO".
        $this->sanitizer->expects($this->never())->method('sanitize');

        $out = (string) $this->sut->formatar('PODER JUDICI&Aacute;RIO Estado de Goi&aacute;s&nbsp;Comarca A -&gt; B &#45; C');

        self::assertStringContainsString('PODER JUDICIÁRIO', $out);
        self::assertStringContainsString('Goiás', $out);
        self::assertStringContainsString("Goiás\u{00A0}Comarca", $out);
        self::assertStringContainsString('A -&gt; B - C', $out);
        self::assertStringNotContainsString('&amp;', $out);
        self::assertStringNotContainsString('&Aacute;', $out);
    }

    #[Test]
    public function entidadeQueEscondeTagPerigosaContinuaEscapada(): void
    {
        // Decodificar não pode abrir XSS: "&lt;script&gt;" vira "<script>" e é escapado de novo.
        $this->sanitizer->expects($this->never())->method('sanitize');

        $out = (string) $this->sut->formatar('Intima&ccedil;&atilde;o &lt;script&gt;alert(1)&lt;/script&gt; &amp;lt;img&amp;gt;');

        self::assertStringContainsString('Intimação', $out);
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringNotContainsString('<img', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
    }

    #[Test]
    public function vazioOuNuloRetornaNull(): void
    {
        $this->sanitizer->expects($this->never())->method('sanitize');

        self::assertNull($this->sut->formatar(null));
        self::assertNull($this->sut->formatar('   '));
    }
}
