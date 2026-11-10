<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\EscritorDePdf;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Pdf\PerfilDoPdf;
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

    #[TestDox('Sem perfil é o do desenho: a imagem inteira, em JPEG 90')]
    public function testPadraoEhODesenho(): void
    {
        $pdf = new PdfLido(self::pdf(1, 'padrão'));

        self::assertSame([[20, 20]], $pdf->medidasDasImagens());
        self::assertSame([20, 20], \array_slice(getimagesizefromstring($pdf->jpegs()[0]) ?: [], 0, 2));
        self::assertSame(PdfLido::quantizacaoDaQualidade(90), PdfLido::quantizacao($pdf->jpegs()[0]));
        self::assertSame($pdf->bytes, self::pdf(1, 'padrão', PerfilDoPdf::Desenho));
    }

    #[TestDox('Perfil leve: a imagem com metade dos pixels (arredondada) em JPEG 70, na mesma folha e na mesma matriz')]
    public function testPerfilLeve(): void
    {
        $leve = new PdfLido(self::pdf(2, 'leve', PerfilDoPdf::Leve, 41, 21));
        $desenho = new PdfLido(self::pdf(2, 'leve', PerfilDoPdf::Desenho, 41, 21));

        // round(41 x 0,5) = 21, round(82 x 0,5) = 41 e round(21 x 0,5) = 11: o meio arredonda para cima.
        self::assertSame([[21, 11], [41, 11]], $leve->medidasDasImagens());
        self::assertSame([[41, 21], [82, 21]], $desenho->medidasDasImagens());
        self::assertSame([21, 11], \array_slice(getimagesizefromstring($leve->jpegs()[0]) ?: [], 0, 2));
        self::assertSame(PdfLido::quantizacaoDaQualidade(70), PdfLido::quantizacao($leve->jpegs()[0]));
        self::assertNotSame(PdfLido::quantizacaoDaQualidade(90), PdfLido::quantizacaoDaQualidade(70));

        self::assertSame(2, substr_count($leve->bytes, '/MediaBox [0 0 595.28 841.89]'));
        self::assertSame(2, substr_count($leve->bytes, 'q 595.28 0 0 841.89 0 0 cm /Im0 Do Q'));
        self::assertSame(2, substr_count($desenho->bytes, 'q 595.28 0 0 841.89 0 0 cm /Im0 Do Q'));
    }

    #[TestDox('Sem nenhuma página não há PDF')]
    public function testSemPagina(): void
    {
        $escritor = new EscritorDePdf();
        $escritor->inicio();

        $this->expectException(FalhaAoMontarPdf::class);
        $escritor->fim('vazio');
    }

    private static function pdf(int $paginas, string $titulo, ?PerfilDoPdf $perfil = null, int $largura = 20, int $altura = 20): string
    {
        $escritor = $perfil === null ? new EscritorDePdf() : new EscritorDePdf($perfil);
        $pdf = $escritor->inicio();
        for ($i = 1; $i <= $paginas; ++$i) {
            $imagem = imagecreatetruecolor($largura * $i, $altura);
            $pdf .= $escritor->pagina($imagem);
            imagedestroy($imagem);
        }

        return $pdf . $escritor->fim($titulo);
    }
}
