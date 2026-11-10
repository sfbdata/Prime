<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * Escreve o acréscimo incremental do carimbo, como o fim do `carimbar` do protótipo (bj-assina-pdf.js:23): os objetos
 * na ordem dada e um índice do mesmo tipo do último do arquivo. Tabela `xref` clássica com as entradas em grupos de
 * números seguidos e trailer com /Size, /Root, /Info, /ID e /Prev; ou fluxo /XRef sem compressão (`/W[1 4 2]`, uma
 * entrada por grupo no /Index), que ganha o número seguinte.
 */
final class CarimboVisivelAcrescimo
{
    private function __construct()
    {
    }

    /**
     * Só o que vai depois do original: quem chama junta os dois.
     *
     * @param list<array{int, int, string}> $objetos número, geração e corpo, na ordem de escrita
     */
    public static function escrever(string $pdf, array $objetos, CarimboVisivelIndice $indice, int $proximoNumero): string
    {
        $cauda = str_ends_with($pdf, "\n") ? '' : "\n";
        $posicao = \strlen($pdf) + \strlen($cauda);
        $entradas = [];
        foreach ($objetos as [$numero, $geracao, $corpo]) {
            $texto = $numero . ' ' . $geracao . " obj\n" . $corpo . "\nendobj\n";
            $entradas[] = [$numero, $geracao, $posicao];
            $cauda .= $texto;
            $posicao += \strlen($texto);
        }

        return $cauda . ($indice->classico
            ? self::tabela($entradas, $indice, $proximoNumero, $posicao)
            : self::fluxo($entradas, $indice, $proximoNumero, $posicao));
    }

    /** @param list<array{int, int, int}> $entradas número, geração e posição */
    private static function tabela(array $entradas, CarimboVisivelIndice $indice, int $tamanho, int $posicao): string
    {
        usort($entradas, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $texto = "xref\n";
        $total = \count($entradas);
        for ($i = 0; $i < $total; $i = $j + 1) {
            $j = $i;
            while ($j + 1 < $total && $entradas[$j + 1][0] === $entradas[$j][0] + 1) {
                ++$j;
            }
            $texto .= $entradas[$i][0] . ' ' . ($j - $i + 1) . "\n";
            for ($k = $i; $k <= $j; ++$k) {
                $texto .= \sprintf('%010d %05d n ', $entradas[$k][2], $entradas[$k][1]) . "\n";
            }
        }

        return $texto . "trailer\n<</Size " . $tamanho . $indice->raiz . $indice->complemento . '/Prev ' . $indice->anterior
            . ">>\nstartxref\n" . $posicao . "\n%%EOF\n";
    }

    /** @param list<array{int, int, int}> $entradas número, geração e posição */
    private static function fluxo(array $entradas, CarimboVisivelIndice $indice, int $numero, int $posicao): string
    {
        $entradas[] = [$numero, 0, $posicao];
        usort($entradas, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $grupos = [];
        $dados = '';
        foreach ($entradas as [$numeroDaEntrada, $geracao, $posicaoDaEntrada]) {
            $grupos[] = $numeroDaEntrada . ' 1';
            $dados .= "\x01" . pack('N', $posicaoDaEntrada) . pack('n', $geracao);
        }

        return $numero . " 0 obj\n<</Type/XRef/Size " . ($numero + 1) . '/W[1 4 2]/Index[' . implode(' ', $grupos) . ']'
            . $indice->raiz . $indice->complemento . '/Prev ' . $indice->anterior . '/Length ' . \strlen($dados)
            . ">>\nstream\n" . $dados . "\nendstream\nendobj\nstartxref\n" . $posicao . "\n%%EOF\n";
    }
}
