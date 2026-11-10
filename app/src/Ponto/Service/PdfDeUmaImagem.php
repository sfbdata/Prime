<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * Um PDF 1.4 de uma página A4 retrato (595,28 x 841,89 pontos) com uma imagem JPEG ocupando a página inteira, escrito à
 * mão como o `pdfImagem` do desenho 16 (L706 a L716): catálogo, páginas, a página, a imagem com o filtro DCTDecode (o
 * JPEG vai como está, sem recompressão), o conteúdo que pinta a imagem e o Info com o título, o "Producer" e o
 * "Creator". Sem Ghostscript e sem arquivo temporário (decisão 5 da E16).
 */
final class PdfDeUmaImagem
{
    private const CABECALHO = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    private const PINTURA = 'q 595.28 0 0 841.89 0 0 cm /Im0 Do Q';

    /**
     * @param string $jpeg   a página inteira, em JPEG colorido
     * @param string $titulo o título do documento ("Folha de Ponto 10/2026")
     *
     * @throws \InvalidArgumentException o conteúdo não é um JPEG
     */
    public function embrulhar(string $jpeg, string $titulo): string
    {
        $tamanho = getimagesizefromstring($jpeg);
        if ($tamanho === false || $tamanho[2] !== IMAGETYPE_JPEG) {
            throw new \InvalidArgumentException('A página do PDF precisa ser uma imagem JPEG.');
        }
        $pdf = self::CABECALHO;
        $posicoes = [];
        foreach (self::objetos($jpeg, $tamanho[0], $tamanho[1], $titulo) as $numero => $corpo) {
            $posicoes[$numero] = strlen($pdf);
            $pdf .= $numero . " 0 obj\n" . $corpo . "\nendobj\n";
        }

        return $pdf . self::tabelaDeReferencias($posicoes, strlen($pdf));
    }

    /** @return array<int, string> o corpo de cada objeto, pelo número (L709 a L714) */
    private static function objetos(string $jpeg, int $largura, int $altura, string $titulo): array
    {
        return [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            4 => sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>', $largura, $altura, strlen($jpeg)) . "\nstream\n" . $jpeg . "\nendstream",
            5 => sprintf('<< /Length %d >>', strlen(self::PINTURA)) . "\nstream\n" . self::PINTURA . "\nendstream",
            6 => sprintf('<< /Title (%s) /Producer (BlueJus) /Creator (BlueJus Ponto Eletronico) >>', self::literal($titulo)),
        ];
    }

    /**
     * A tabela `xref` com a posição de cada objeto, o `trailer` e o `startxref` (L715).
     *
     * @param array<int, int> $posicoes
     */
    private static function tabelaDeReferencias(array $posicoes, int $inicioDaTabela): string
    {
        $tabela = "xref\n0 7\n0000000000 65535 f \n";
        foreach ($posicoes as $posicao) {
            $tabela .= sprintf("%010d 00000 n \n", $posicao);
        }

        return $tabela . "trailer\n<< /Size 7 /Root 1 0 R /Info 6 0 R >>\nstartxref\n" . $inicioDaTabela . "\n%%EOF\n";
    }

    /** O texto dentro de `( )`: a barra invertida e os parênteses levam escape. */
    private static function literal(string $texto): string
    {
        return strtr($texto, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
