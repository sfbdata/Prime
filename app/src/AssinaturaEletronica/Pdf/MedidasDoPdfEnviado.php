<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\Shared\Service\ExecucaoDoGhostscript;
use App\Shared\Service\SaidaDoGhostscript;

/**
 * O tamanho de cada página do PDF enviado, sem desenhar nenhuma (E3-1): a largura e a altura, em pontos, que a página
 * terá na imagem do `-dUseCropBox`. É a CropBox recortada pela MediaBox, vezes a UserUnit, deitada quando o Rotate
 * deita a página.
 *
 * O {@see RasterDoPdfEnviado} escolhe a resolução com isto antes de rasterizar: uma página de 200 x 200 pol a 225 dpi
 * daria 45000 x 45000 px.
 *
 * Quem escreve é um programa PostScript nosso ({@see PROGRAMA}), rodado pelo Ghostscript sem desenhar: ele abre o PDF
 * pelo interpretador de PDF do Ghostscript e imprime só números, uma linha por página, começando por uma marca
 * aleatória de cada leitura. Nada do PDF (título, nome de fonte, texto) é impresso pelo programa; o aviso que o
 * próprio Ghostscript escreve ao ler um PDF quebrado não tem a marca e é descartado. Assim o PDF não consegue
 * escrever uma medida: a única medida que ele escolhe é a das próprias caixas, e mentir nelas só corta a página dele
 * (quem rasteriza limita os pixels com `-g`).
 *
 * A leitura tem teto de tempo, de bytes e de páginas (o
 * {@see \App\AssinaturaEletronica\Service\ValidadorDeBlocos::PAGINAS_SEM_CONTAGEM} que quem chama passa, o mesmo
 * teto do bloco de página quando o PDF não foi contado). O que passou do teto fica sem medida, e quem chamou decide.
 */
final class MedidasDoPdfEnviado
{
    /** O máximo de saída lida do Ghostscript. Uma linha tem uns 80 bytes: 2000 páginas cabem com folga. */
    public const TETO_DA_SAIDA = 1024 * 1024;
    private const TEMPO = 30.0;
    /** Um número como o PostScript imprime: inteiro, decimal ou com expoente (`1.0e+30`). */
    private const NUMERO = '-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?';

    /**
     * Para cada página de BJPRIMEIRA a BJULTIMA (sem passar do total): a marca, o número da página, a MediaBox, a
     * CropBox (a MediaBox quando não há), o Rotate (0 quando não há) e a UserUnit (1 quando não há). O que não é
     * número sai como `x`, e erro numa página termina a linha dela no meio: as duas linhas são recusadas na leitura.
     * Sem comentário dentro do programa: ele vai inteiro num argumento `-c`.
     */
    private const PROGRAMA = <<<'PS'
        /BJnum { dup type dup /integertype eq exch /realtype eq or { =only } { pop (x) print } ifelse } bind def
        /BJcaixa {
          2 copy known { get } { pop /MediaBox get } ifelse
          dup type /arraytype eq { dup length 4 eq } { false } ifelse
          { { ( ) print BJnum } forall } { pop ( x) print } ifelse
        } bind def
        /BJvalor { 3 1 roll 2 copy known { get exch pop } { pop pop } ifelse ( ) print BJnum } bind def
        /BJCtx << >> .PDFInit def
        BJARQUIVO (r) file BJCtx .PDFStream
        BJCtx .PDFInfo /NumPages get dup BJULTIMA gt { pop BJULTIMA } if
        BJPRIMEIRA 1 sub exch 1 exch 1 sub {
          /BJi exch def
          {
            BJCtx BJi .PDFPageInfo
            BJNONCE print ( ) print BJi 1 add =only
            dup /MediaBox BJcaixa dup /CropBox BJcaixa dup /Rotate 0 BJvalor /UserUnit 1 BJvalor
            (\n) print flush
          } stopped { clear (\n) print flush } if
        } for
        BJCtx .PDFClose
        quit
        PS;

    private function __construct()
    {
    }

    /**
     * As medidas das páginas de `$primeira` a `$ultima` (a partir de 1), por número da página, e o motivo quando a
     * leitura não terminou bem (`tempo`, `teto`, `codigo N` ou a classe da falha). Mesmo com motivo, as linhas
     * completas que chegaram valem: só o nosso programa escreve linha com a marca.
     *
     * @param float $tempo em segundos; o padrão é o de produção, e o teste do processo parado no tempo usa menos
     *
     * @return array{array<int, array{float, float}>, ?string}
     */
    public static function ler(string $ghostscriptBin, string $pdf, int $primeira, int $ultima, float $tempo = self::TEMPO): array
    {
        $marca = bin2hex(random_bytes(16));

        try {
            $comando = self::comando($ghostscriptBin, $pdf, $marca, $primeira, $ultima);
            $execucao = ExecucaoDoGhostscript::rodarComTeto($comando, $tempo, self::TETO_DA_SAIDA);
        } catch (\RuntimeException $e) {
            return [[], $e::class];
        }

        return [self::interpretar($execucao->saida, $marca), self::motivo($execucao)];
    }

    /**
     * As linhas do programa com a marca, por exemplo `<marca> 2 0 0 600 800 -100 -100 300 400 90 2`: página 2,
     * MediaBox, CropBox, Rotate e UserUnit. Linha sem a marca, com algo que não é número, com campo a mais ou a menos
     * ou sem a quebra de linha do fim (cortada no teto) não vale.
     *
     * @return array<int, array{float, float}>
     */
    public static function interpretar(string $saida, string $marca): array
    {
        $padrao = '/^' . preg_quote($marca, '/') . ' (\d+)((?: ' . self::NUMERO . '){10})\n/m';
        preg_match_all($padrao, $saida, $linhas, \PREG_SET_ORDER);

        $medidas = [];
        foreach ($linhas as [, $numero, $valores]) {
            $medida = self::medida(array_map(floatval(...), explode(' ', ltrim($valores))));
            if ($medida !== null && !isset($medidas[(int) $numero])) {
                $medidas[(int) $numero] = $medida;
            }
        }

        return $medidas;
    }

    /** @return list<string> */
    private static function comando(string $ghostscriptBin, string $pdf, string $marca, int $primeira, int $ultima): array
    {
        return [
            $ghostscriptBin,
            '-q',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-dNODISPLAY',
            // O SAFER continua: o programa só pode abrir o próprio PDF, para leitura.
            '--permit-file-read=' . $pdf,
            '-sBJARQUIVO=' . $pdf,
            '-sBJNONCE=' . $marca,
            '-dBJPRIMEIRA=' . $primeira,
            '-dBJULTIMA=' . $ultima,
            '-c',
            self::PROGRAMA,
        ];
    }

    private static function motivo(SaidaDoGhostscript $execucao): ?string
    {
        return match (true) {
            $execucao->tempoEstourado => 'tempo',
            $execucao->tetoEstourado => 'teto',
            $execucao->codigo !== 0 => 'codigo ' . ($execucao->codigo ?? 'desconhecido'),
            default => null,
        };
    }

    /**
     * Largura e altura na imagem. O Ghostscript deita a página quando a parte inteira de Rotate / 90 é ímpar
     * (conferido de -450 a 1000, com valores fora dos múltiplos de 90): 90, -90, 270 e também 100 ou 135; 45 e 181 não.
     *
     * @param list<float> $valores MediaBox (4), CropBox (4), Rotate e UserUnit
     *
     * @return array{float, float}|null
     */
    private static function medida(array $valores): ?array
    {
        $caixa = self::caixa(\array_slice($valores, 0, 4));
        $recorte = self::caixa(\array_slice($valores, 4, 4));
        $largura = (min($caixa[2], $recorte[2]) - max($caixa[0], $recorte[0])) * $valores[9];
        $altura = (min($caixa[3], $recorte[3]) - max($caixa[1], $recorte[1])) * $valores[9];
        if (!is_finite($largura) || !is_finite($altura) || $largura <= 0 || $altura <= 0) {
            return null;
        }

        return fmod(floor(abs($valores[8]) / 90), 2.0) === 1.0 ? [$altura, $largura] : [$largura, $altura];
    }

    /**
     * @param list<float> $pontos x0, y0, x1 e y1 em qualquer ordem
     *
     * @return array{float, float, float, float} com x0 <= x1 e y0 <= y1
     */
    private static function caixa(array $pontos): array
    {
        return [min($pontos[0], $pontos[2]), min($pontos[1], $pontos[3]), max($pontos[0], $pontos[2]), max($pontos[1], $pontos[3])];
    }
}
