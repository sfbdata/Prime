<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

/**
 * Um PDF mínimo escrito à mão, uma página por item, cada uma com as caixas pedidas (`/MediaBox [...]`, `/CropBox`,
 * `/Rotate`, `/UserUnit`) e um quadrado vermelho no canto. Poucos bytes, qualquer tamanho de página.
 *
 * {@see comSaidaGigante()} é o PDF hostil da revisão: uns 450 KB com 3000 páginas e 300 fontes de nome longo.
 */
final class PdfDeCaixas
{
    private const CONTEUDO = '1 0 0 rg 0 0 50 50 re f';
    /** Nome de fonte com quebra de linha (`#0A`): no antigo `-dPDFINFO` virava a linha `Page 1 MediaBox: [0 0 1 1]`. */
    public const FONTE_COM_LINHA_FALSA = '/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /A#0APage#201#20MediaBox:#20#5B0#200#201#201#5D >> >> >>';

    private function __construct()
    {
    }

    public static function gerar(string ...$caixasPorPagina): string
    {
        return self::escrever(self::paginas($caixasPorPagina));
    }

    /** Como {@see gerar()}, com um título no dicionário `/Info` (texto literal do PDF, sem os parênteses de fora). */
    public static function comTitulo(string $titulo, string ...$caixasPorPagina): string
    {
        $objetos = self::paginas($caixasPorPagina);
        $objetos[] = sprintf('<< /Title (%s) >>', $titulo);

        return self::escrever($objetos, \count($objetos));
    }

    /**
     * Uma página de `$largura` x `$altura` pt com um quadrado vermelho no canto de baixo à esquerda e um azul no de
     * cima à direita, os dois de 50 pt: a página cortada perde o azul.
     */
    public static function comCantos(int $largura, int $altura, string $caixasExtras = ''): string
    {
        $conteudo = sprintf('%s 0 0 1 rg %d %d 50 50 re f', self::CONTEUDO, $largura - 50, $altura - 50);

        return self::escrever([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] %s /Contents 4 0 R >>', $largura, $altura, $caixasExtras),
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($conteudo), $conteudo),
        ]);
    }

    /**
     * `$paginas` páginas A4 (595 x 842) que dividem um só dicionário de `$fontes` fontes de nome longo, mais a fonte
     * `$extra`. As páginas são objetos distintos (o Ghostscript não desenha a página repetida em `/Kids`).
     */
    public static function comFontes(int $paginas, int $fontes, string $extra = ''): string
    {
        $dicionario = $extra;
        for ($i = 0; $i < $fontes; ++$i) {
            $dicionario .= sprintf(' /F%d << /Type /Font /Subtype /Type1 /BaseFont /%s%d >>', $i, str_repeat('N', 100), $i);
        }

        $filhos = implode(' ', array_map(static fn (int $i): string => (5 + $i) . ' 0 R', range(0, $paginas - 1)));
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $filhos, $paginas),
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen(self::CONTEUDO), self::CONTEUDO),
            sprintf('<< /Font << %s >> >>', $dicionario),
        ];

        return self::escrever([...$objetos, ...array_fill(0, $paginas, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources 4 0 R /Contents 3 0 R >>')]);
    }

    /**
     * 3000 páginas que dividem um só dicionário de 300 fontes de nome longo (uns 450 KB). Com o antigo `-dPDFINFO`,
     * que listava as fontes de cada página, a saída passava de 100 MB; o nome da fonte `/Falsa` tem uma quebra de
     * linha e punha, na lista de fontes de toda página, a linha `Page 3000 MediaBox: [0 0 100 100]`.
     */
    public static function comSaidaGigante(): string
    {
        return self::comFontes(3000, 300, '/Falsa << /Type /Font /Subtype /Type1 /BaseFont /A#0APage#203000#20MediaBox:#20#5B0#200#20100#20100#5D >>');
    }

    /**
     * @param array<string> $caixasPorPagina
     *
     * @return list<string>
     */
    private static function paginas(array $caixasPorPagina): array
    {
        $filhos = [];
        for ($i = 0; $i < \count($caixasPorPagina); ++$i) {
            $filhos[] = (3 + $i * 2) . ' 0 R';
        }

        $objetos = ['<< /Type /Catalog /Pages 2 0 R >>', sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $filhos), \count($filhos))];
        foreach ($caixasPorPagina as $caixas) {
            $objetos[] = sprintf('<< /Type /Page /Parent 2 0 R %s /Contents %d 0 R >>', $caixas, \count($objetos) + 2);
            $objetos[] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen(self::CONTEUDO), self::CONTEUDO);
        }

        return $objetos;
    }

    /**
     * @param list<string> $objetos
     * @param int|null     $info    número do objeto do dicionário `/Info`, se houver
     */
    private static function escrever(array $objetos, ?int $info = null): string
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

        $trailer = $info === null ? '' : sprintf(' /Info %d 0 R', $info);

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R%s >>\nstartxref\n%d\n%%%%EOF\n", \count($objetos) + 1, $trailer, $xref);
    }
}
