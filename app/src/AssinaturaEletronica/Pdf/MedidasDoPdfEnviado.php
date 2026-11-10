<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\Shared\Service\ExecucaoDoGhostscript;
use Psr\Log\LoggerInterface;

/**
 * O tamanho de cada página do PDF enviado, sem desenhar nenhuma (E3-1). O `-dPDFINFO` do Ghostscript lista as caixas
 * de cada página; daqui sai a largura e a altura, em pontos, que a página terá na imagem do `-dUseCropBox`: a
 * CropBox recortada pela MediaBox, vezes a UserUnit, deitada quando o Rotate é de 90 ou 270 graus.
 *
 * O {@see RasterDoPdfEnviado} escolhe a resolução com isto antes de rasterizar: uma página de 200 x 200 pol a 225 dpi
 * daria 45000 x 45000 px. A medida é só uma estimativa vinda do próprio PDF: quem rasteriza limita os pixels com
 * `-g`, e uma medida falsa corta a página, sem custar mais.
 */
final class MedidasDoPdfEnviado
{
    /**
     * O máximo que o PHP lê do `-dPDFINFO`, qualquer que seja o PDF. Sem teto, 125 KB de PDF (uma página repetida
     * 3000 vezes, com fontes de nome longo) escrevem mais de 160 MB, e juntar isso numa string estoura a memória.
     */
    public const TETO_DA_SAIDA = 1024 * 1024;
    private const TEMPO = 30.0;
    /**
     * O `sh` liga a saída de erro do Ghostscript (onde o `-dPDFINFO` escreve) ao `head`, que para no teto mais 1 byte
     * (o byte a mais denuncia que passou); o Process do Symfony nunca acumula mais que isso. O Ghostscript morre de
     * SIGPIPE na escrita seguinte: o PHP ignora o SIGPIPE e o filho herdaria isso (seguiria lendo o PDF até o fim, 25 s
     * no PDF hostil), por isso o `env --default-signal=PIPE` devolve o sinal ao padrão antes de rodar o Ghostscript.
     */
    private const LIGACAO = 'env --default-signal=PIPE "$@" 2>&1 >/dev/null | head -c %d';

    private function __construct()
    {
    }

    /**
     * Largura e altura em pontos, por número da página. Página que o Ghostscript não descreveu (ou que ficou depois
     * do teto) fica de fora; a falha vai para o log só com o motivo, nunca com o conteúdo do PDF.
     *
     * @return array<int, array{float, float}>
     */
    public static function ler(string $ghostscriptBin, string $pdf, LoggerInterface $logger): array
    {
        $comando = ['sh', '-c', sprintf(self::LIGACAO, self::TETO_DA_SAIDA + 1), 'sh', $ghostscriptBin, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNODISPLAY', '-dPDFINFO', $pdf];

        try {
            $saida = ExecucaoDoGhostscript::rodar($comando, self::TEMPO)->getOutput();
        } catch (\RuntimeException $e) {
            $logger->warning('O tamanho das páginas do PDF enviado não foi lido.', ['motivo' => $e::class]);

            return [];
        }

        if (\strlen($saida) > self::TETO_DA_SAIDA) {
            $logger->warning('O tamanho das páginas do PDF enviado foi lido só até o teto.', ['motivo' => 'teto', 'teto' => self::TETO_DA_SAIDA]);
            // A última linha, cortada no meio, sai inteira.
            $saida = substr($saida, 0, (int) strrpos(substr($saida, 0, self::TETO_DA_SAIDA), "\n"));
        }

        return self::interpretar($saida);
    }

    /**
     * A saída do `-dPDFINFO`, uma linha por página, por exemplo
     * `Page 3 UserUnit: 2.000000  MediaBox: [0 0 600 800] CropBox: [0 0 300 400]    Rotate = 90`.
     *
     * O título do documento (antes das páginas) e o nome de cada fonte (depois da linha da página) vêm do PDF e podem
     * trazer uma linha `Page N` falsa. Por isso, para cada página, vale a maior área entre todas as linhas dela: a
     * linha falsa só consegue aumentar a página, o que baixa a resolução do PDF de quem a escreveu.
     *
     * @return array<int, array{float, float}>
     */
    public static function interpretar(string $saida): array
    {
        preg_match_all('/^Page (\d+) (.*)$/m', $saida, $linhas, \PREG_SET_ORDER);

        $medidas = [];
        foreach ($linhas as [, $numero, $caixas]) {
            $medida = self::medida($caixas);
            $atual = $medidas[(int) $numero] ?? null;
            if ($medida !== null && ($atual === null || $medida[0] * $medida[1] > $atual[0] * $atual[1])) {
                $medidas[(int) $numero] = $medida;
            }
        }

        return $medidas;
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
