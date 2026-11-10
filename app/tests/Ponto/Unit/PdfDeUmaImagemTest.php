<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\PdfDeUmaImagem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O PDF de uma página da folha (E16, decisão 5; desenho 16 L706 a L716): PDF 1.4, uma página A4, a imagem JPEG como
 * está (DCTDecode), o Info com título, "Producer" e "Creator", e a tabela de referências com as posições certas.
 */
#[Group('ponto')]
#[CoversClass(PdfDeUmaImagem::class)]
final class PdfDeUmaImagemTest extends TestCase
{
    #[TestDox('Uma página A4 com a imagem JPEG inteira, sem recompressão, em DCTDecode com a largura e a altura dela')]
    public function testUmaPaginaA4ComAImagem(): void
    {
        $jpeg = $this->jpeg(40, 57);

        $pdf = (new PdfDeUmaImagem())->embrulhar($jpeg, 'Folha de Ponto 10/2026');

        self::assertStringStartsWith("%PDF-1.4\n", $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
        self::assertSame(1, substr_count($pdf, '/Type /Page '), 'uma página só');
        self::assertStringContainsString('/Type /Pages /Kids [3 0 R] /Count 1', $pdf);
        self::assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $pdf);
        self::assertSame(1, substr_count($pdf, '/Subtype /Image'));
        self::assertStringContainsString('/Width 40 /Height 57 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg), $pdf);
        self::assertStringContainsString("stream\n" . $jpeg . "\nendstream", $pdf, 'o JPEG vai byte a byte');
        self::assertStringContainsString('q 595.28 0 0 841.89 0 0 cm /Im0 Do Q', $pdf);
    }

    #[TestDox('O Info: título "Folha de Ponto MM/AAAA", Producer "BlueJus" e Creator "BlueJus Ponto Eletronico"')]
    public function testInfo(): void
    {
        $pdf = (new PdfDeUmaImagem())->embrulhar($this->jpeg(), 'Folha de Ponto 10/2026');

        self::assertStringContainsString('<< /Title (Folha de Ponto 10/2026) /Producer (BlueJus) /Creator (BlueJus Ponto Eletronico) >>', $pdf);
        self::assertStringContainsString("trailer\n<< /Size 7 /Root 1 0 R /Info 6 0 R >>", $pdf);
    }

    #[TestDox('Parênteses e barra invertida no título levam escape')]
    public function testEscapeDoTitulo(): void
    {
        $pdf = (new PdfDeUmaImagem())->embrulhar($this->jpeg(), 'Folha (rascunho) \\ 10/2026');

        self::assertStringContainsString('/Title (Folha \\(rascunho\\) \\\\ 10/2026)', $pdf);
    }

    #[TestDox('A tabela de referências aponta o começo de cada objeto, e o startxref, o começo da tabela')]
    public function testTabelaDeReferencias(): void
    {
        $pdf = (new PdfDeUmaImagem())->embrulhar($this->jpeg(), 'Folha de Ponto 10/2026');

        self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $fim));
        $inicioDaTabela = (int) $fim[1];
        self::assertStringStartsWith("xref\n0 7\n0000000000 65535 f \n", substr($pdf, $inicioDaTabela));
        self::assertSame(6, preg_match_all('/^(\d{10}) 00000 n $/m', substr($pdf, $inicioDaTabela), $posicoes));
        foreach ($posicoes[1] as $i => $posicao) {
            self::assertStringStartsWith(($i + 1) . " 0 obj\n", substr($pdf, (int) $posicao), 'objeto ' . ($i + 1));
        }
    }

    #[TestDox('Conteúdo que não é JPEG é recusado')]
    public function testRecusaOQueNaoEJpeg(): void
    {
        $imagem = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($imagem);
        $png = (string) ob_get_clean();

        $this->expectException(\InvalidArgumentException::class);
        (new PdfDeUmaImagem())->embrulhar($png, 'Folha de Ponto 10/2026');
    }

    private function jpeg(int $largura = 8, int $altura = 12): string
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        ob_start();
        imagejpeg($imagem, null, 92);

        return (string) ob_get_clean();
    }
}
