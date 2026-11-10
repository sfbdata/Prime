<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\Shared\Service\ExecucaoDoGhostscript;

/**
 * O tamanho de cada página do PDF enviado, sem desenhar nenhuma (E3-1). O `-dPDFINFO` do Ghostscript lista as caixas
 * de cada página; daqui sai a largura e a altura, em pontos, que a página terá na imagem do `-dUseCropBox`: a
 * CropBox recortada pela MediaBox, vezes a UserUnit, deitada quando o Rotate é de 90 ou 270 graus.
 *
 * O {@see RasterDoPdfEnviado} escolhe a resolução com isto antes de rasterizar: uma página de 200 x 200 pol a 225 dpi
 * daria 45000 x 45000 px.
 */
final class MedidasDoPdfEnviado
{
    private const TEMPO = 30.0;

    private function __construct()
    {
    }

    /**
     * Largura e altura em pontos, por número da página. Página que o Ghostscript não descreveu fica de fora.
     *
     * @return array<int, array{float, float}>
     */
    public static function ler(string $ghostscriptBin, string $pdf): array
    {
        try {
            $processo = ExecucaoDoGhostscript::rodar(
                [$ghostscriptBin, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNODISPLAY', '-dPDFINFO', $pdf],
                self::TEMPO,
            );
        } catch (\RuntimeException) {
            return [];
        }

        return self::interpretar($processo->getErrorOutput());
    }

    /**
     * A saída do `-dPDFINFO` (vem na saída de erro), uma linha por página, por exemplo
     * `Page 3 UserUnit: 2.000000  MediaBox: [0 0 600 800] CropBox: [0 0 300 400]    Rotate = 90`.
     * Vale a última linha de cada página: o título do documento sai antes delas e pode trazer uma linha falsa.
     *
     * @return array<int, array{float, float}>
     */
    public static function interpretar(string $saida): array
    {
        preg_match_all('/^Page (\d+) (.*)$/m', $saida, $linhas, \PREG_SET_ORDER);

        $medidas = [];
        foreach ($linhas as [, $numero, $caixas]) {
            $medidas[(int) $numero] = self::medida($caixas);
        }

        return array_filter($medidas, static fn (?array $medida): bool => $medida !== null);
    }

    /** @return array{float, float}|null */
    private static function medida(string $caixas): ?array
    {
        $caixa = self::caixa($caixas, 'MediaBox');
        $recorte = self::caixa($caixas, 'CropBox');
        if ($caixa !== null && $recorte !== null) {
            $caixa = [max($caixa[0], $recorte[0]), max($caixa[1], $recorte[1]), min($caixa[2], $recorte[2]), min($caixa[3], $recorte[3])];
        }

        $unidade = preg_match('/UserUnit: ([0-9.]+)/', $caixas, $m) === 1 ? (float) $m[1] : 1.0;
        $largura = $caixa === null ? 0.0 : ($caixa[2] - $caixa[0]) * $unidade;
        $altura = $caixa === null ? 0.0 : ($caixa[3] - $caixa[1]) * $unidade;
        if ($largura <= 0 || $altura <= 0) {
            return null;
        }

        $giro = preg_match('/Rotate = (-?\d+)/', $caixas, $m) === 1 ? abs((int) $m[1]) : 0;

        return $giro % 180 === 90 ? [$altura, $largura] : [$largura, $altura];
    }

    /** @return array{float, float, float, float}|null x0, y0, x1 e y1, com x0 <= x1 e y0 <= y1 */
    private static function caixa(string $caixas, string $nome): ?array
    {
        $numero = '\s*(-?[0-9.]+)';
        if (preg_match('/' . $nome . ': \[' . str_repeat($numero, 4) . '\s*\]/', $caixas, $m) !== 1) {
            return null;
        }

        [$x0, $y0, $x1, $y1] = [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];

        return [min($x0, $x1), min($y0, $y1), max($x0, $x1), max($y0, $y1)];
    }
}
