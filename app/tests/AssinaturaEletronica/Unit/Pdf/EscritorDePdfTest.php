<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\EscritorDePdf;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EscritorDePdf::class)]
final class EscritorDePdfTest extends TestCase
{
    #[TestDox('Cada página é uma JPEG em A4; a tabela de referências aponta para o início de cada objeto')]
    public function testEstrutura(): void
    {
        $pdf = new PdfLido(self::pdf(2, 'Documento BlueJus BJA-1'));

        self::assertStringStartsWith('%PDF-1.4', $pdf->bytes);
        self::assertStringEndsWith("%%EOF\n", $pdf->bytes);
        self::assertSame(2, $pdf->paginas());
        self::assertSame(2, substr_count($pdf->bytes, '/MediaBox [0 0 595.28 841.89]'));
        self::assertSame('Documento BlueJus BJA-1', $pdf->titulo());
        self::assertCount(2, $pdf->jpegs());
        self::assertSame([40, 20], \array_slice(getimagesizefromstring($pdf->jpegs()[1]) ?: [], 0, 2));

        preg_match('/startxref\n(\d+)\n/', $pdf->bytes, $m);
        $tabela = substr($pdf->bytes, (int) $m[1]);
        self::assertStringStartsWith("xref\n0 10\n", $tabela);
        preg_match_all('/^(\d{10}) 00000 n $/m', $tabela, $posicoes);
        foreach ($posicoes[1] as $i => $posicao) {
            self::assertStringStartsWith(($i + 1) . ' 0 obj', substr($pdf->bytes, (int) $posicao), 'Objeto ' . ($i + 1));
        }
    }

    #[TestDox('Parênteses e barra invertida do título saem escapados')]
    public function testTituloEscapado(): void
    {
        self::assertStringContainsString('/Title (a \\(b\\) \\\\ c)', self::pdf(1, 'a (b) \\ c'));
    }

    #[TestDox('Sem nenhuma página não há PDF')]
    public function testSemPagina(): void
    {
        $escritor = new EscritorDePdf();
        $escritor->inicio();

        $this->expectException(FalhaAoMontarPdf::class);
        $escritor->fim('vazio');
    }

    private static function pdf(int $paginas, string $titulo): string
    {
        $escritor = new EscritorDePdf();
        $pdf = $escritor->inicio();
        for ($i = 1; $i <= $paginas; ++$i) {
            $imagem = imagecreatetruecolor(20 * $i, 20);
            $pdf .= $escritor->pagina($imagem);
            imagedestroy($imagem);
        }

        return $pdf . $escritor->fim($titulo);
    }
}
