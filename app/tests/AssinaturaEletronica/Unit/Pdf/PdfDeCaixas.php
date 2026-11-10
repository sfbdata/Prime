<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

/**
 * Um PDF mínimo escrito à mão, uma página por item, cada uma com as caixas pedidas (`/MediaBox [...]`, `/CropBox`,
 * `/Rotate`, `/UserUnit`) e um quadrado vermelho no canto. Poucos bytes, qualquer tamanho de página.
 *
 * {@see comSaidaGigante()} é o PDF hostil da revisão: uns 450 KB que fazem o `-dPDFINFO` escrever mais de 100 MB.
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

    /**
     * 3000 páginas que dividem um só dicionário de 300 fontes de nome longo (uns 450 KB): o `-dPDFINFO` lista as
     * fontes de cada página e escreveria mais de 100 MB. O nome da fonte `/Falsa` tem uma quebra de linha e põe, na
     * lista de fontes de toda página, a linha `Page 3000 MediaBox: [0 0 100 100]`. As páginas são objetos distintos
     * (o Ghostscript não desenha a página repetida em `/Kids`).
     */
    public static function comSaidaGigante(): string
    {
        $fontes = '/Falsa << /Type /Font /Subtype /Type1 /BaseFont /A#0APage#203000#20MediaBox:#20#5B0#200#20100#20100#5D >>';
        for ($i = 0; $i < 300; ++$i) {
            $fontes .= sprintf(' /F%d << /Type /Font /Subtype /Type1 /BaseFont /%s%d >>', $i, str_repeat('N', 100), $i);
        }

        $filhos = implode(' ', array_map(static fn (int $i): string => (5 + $i) . ' 0 R', range(0, 2999)));
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            sprintf('<< /Type /Pages /Kids [%s] /Count 3000 >>', $filhos),
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen(self::CONTEUDO), self::CONTEUDO),
            sprintf('<< /Font << %s >> >>', $fontes),
        ];

        return self::escrever([...$objetos, ...array_fill(0, 3000, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources 4 0 R /Contents 3 0 R >>')]);
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
