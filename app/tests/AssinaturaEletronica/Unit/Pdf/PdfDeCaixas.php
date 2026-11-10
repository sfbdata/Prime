<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

/**
 * Um PDF mínimo escrito à mão, uma página por item, cada uma com as caixas pedidas (`/MediaBox [...]`, `/CropBox`,
 * `/Rotate`, `/UserUnit`) e um quadrado vermelho no canto. Poucos bytes, qualquer tamanho de página.
 */
final class PdfDeCaixas
{
    private const CONTEUDO = '1 0 0 rg 0 0 50 50 re f';

    private function __construct()
    {
    }

    public static function gerar(string ...$caixasPorPagina): string
    {
        $filhos = [];
        foreach (array_keys($caixasPorPagina) as $i) {
            $filhos[] = (3 + $i * 2) . ' 0 R';
        }

        $objetos = ['<< /Type /Catalog /Pages 2 0 R >>', sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $filhos), \count($filhos))];
        foreach ($caixasPorPagina as $caixas) {
            $objetos[] = sprintf('<< /Type /Page /Parent 2 0 R %s /Contents %d 0 R >>', $caixas, \count($objetos) + 2);
            $objetos[] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen(self::CONTEUDO), self::CONTEUDO);
        }

        return self::escrever($objetos);
    }

    /** @param list<string> $objetos */
    private static function escrever(array $objetos): string
    {
        $pdf = "%PDF-1.4\n";
        $posicoes = [];
        foreach ($objetos as $i => $objeto) {
            $posicoes[] = \strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $i + 1, $objeto);
        }

        $xref = \strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objetos) + 1);
        foreach ($posicoes as $posicao) {
            $pdf .= sprintf("%010d 00000 n \n", $posicao);
        }

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objetos) + 1, $xref);
    }
}
