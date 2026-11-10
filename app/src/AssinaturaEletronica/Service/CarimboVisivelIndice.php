<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;

/**
 * O último índice do PDF, lido como o `carimbar` do protótipo lê (bj-assina-pdf.js:23): o último `startxref`, se ali
 * começa uma tabela `xref` clássica ou um fluxo /XRef, e do dicionário do trailer (ou do fluxo) o /Size, o /Root e, se
 * houver, o /Info e o /ID, com o texto como está no arquivo.
 *
 * Diferente do protótipo, o primeiro número livre não confia só no /Size: um /Size menor que os números em uso faria
 * o carimbo escrever por cima de um objeto do arquivo.
 */
final readonly class CarimboVisivelIndice
{
    private function __construct(
        /** Onde começa o último índice: o /Prev do acréscimo. */
        public int $anterior,
        public bool $classico,
        /** O /Size do último índice, como está no arquivo. */
        public int $tamanho,
        /**
         * O primeiro número livre para os objetos do carimbo: o maior entre o /Size e um a mais que o maior número em
         * uso no arquivo (cabeçalho solto `n g obj` ou faixa do /Index de um fluxo /XRef).
         */
        public int $primeiroLivre,
        /** O texto `/Root n g R` do arquivo. */
        public string $raiz,
        public int $numeroDaRaiz,
        public int $geracaoDaRaiz,
        /** O `' ' + /Info + ' ' + /ID` do protótipo, com o texto de cada um (ou vazio). */
        public string $complemento,
    ) {
    }

    /** Null quando o protótipo diria "Estrutura do PDF não reconhecida.". */
    public static function ler(string $pdf): ?self
    {
        $anterior = self::ultimoStartxref($pdf);
        if ($anterior === null) {
            return null;
        }
        $classico = preg_match('/^\s*xref/', substr($pdf, $anterior, 40)) === 1;
        $dicionario = self::dicionario($pdf, $anterior, $classico);
        if ($dicionario === null
            || preg_match('/\/Size\s+(\d{1,9})(?!\d)/', $dicionario, $tamanho) !== 1
            || preg_match('/\/Root\s+(\d{1,10})\s+(\d{1,5})\s+R/', $dicionario, $raiz) !== 1) {
            return null;
        }
        $emUso = self::seguinteAoMaiorEmUso($pdf);
        if ($emUso === null) {
            return null;
        }
        preg_match('/\/Info\s+\d+\s+\d+\s+R/', $dicionario, $informacoes);
        preg_match('/\/ID\s*\[[^\]]*\]/', $dicionario, $identificador);

        return new self(
            $anterior,
            $classico,
            (int) $tamanho[1],
            max((int) $tamanho[1], $emUso),
            $raiz[0],
            (int) $raiz[1],
            (int) $raiz[2],
            ' ' . ($informacoes[0] ?? '') . ' ' . ($identificador[0] ?? ''),
        );
    }

    /** O número do último `startxref`, se ele aponta para dentro do arquivo. */
    private static function ultimoStartxref(string $pdf): ?int
    {
        if ((int) preg_match_all('/startxref\s+(\d+)/', $pdf, $partes) === 0) {
            return null;
        }
        $texto = $partes[1][\count($partes[1]) - 1];

        return \strlen($texto) <= 9 && (int) $texto < \strlen($pdf) ? (int) $texto : null;
    }

    /** Clássico: do `trailer` depois do índice até o `startxref`. Fluxo: do índice até a palavra `stream`. */
    private static function dicionario(string $pdf, int $anterior, bool $classico): ?string
    {
        $inicio = $classico ? strpos($pdf, 'trailer', $anterior) : $anterior;
        if ($inicio === false) {
            return null;
        }
        $fim = strpos($pdf, $classico ? 'startxref' : 'stream', $inicio);

        return $fim === false ? null : substr($pdf, $inicio, $fim - $inicio);
    }

    /**
     * Um a mais que o maior número em uso: cabeçalhos soltos (o padrão do leitor do motor, com até 9 algarismos) e
     * faixas `primeiro quantidade` do /Index dos fluxos /XRef (os objetos dentro de /ObjStm só são achados por elas).
     * Null acima do teto de cabeçalhos do motor (M27).
     */
    private static function seguinteAoMaiorEmUso(string $pdf): ?int
    {
        $soltos = self::maiorDasOcorrencias($pdf, '/(?:^|[\s>])(\d{1,9})\s++\d{1,5}\s++obj\b/', static fn (string $numero): int => (int) $numero + 1);
        $faixas = self::maiorDasOcorrencias($pdf, '/\/Index\s*+\[([\d\s]*+)\]/', self::fimDasFaixas(...));

        return $soltos === null || $faixas === null ? null : max($soltos, $faixas);
    }

    /**
     * O maior valor do primeiro grupo de cada ocorrência do padrão, numa varredura só; null acima do teto de
     * cabeçalhos do motor.
     *
     * @param \Closure(string): int $valor
     */
    private static function maiorDasOcorrencias(string $pdf, string $padrao, \Closure $valor): ?int
    {
        $maior = 0;
        $vezes = 0;
        $posicao = 0;
        while (preg_match($padrao, $pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            if (++$vezes > LimitesDaConferencia::CABECALHOS_MAXIMOS_DO_ARQUIVO) {
                return null;
            }
            $maior = max($maior, $valor($partes[1][0]));
            $posicao = $partes[0][1] + \strlen($partes[0][0]);
        }

        return $maior;
    }

    /**
     * O fim (primeiro mais quantidade) da faixa que vai mais longe num /Index, par a par, sem montar a lista; faixa com
     * número de mais de 9 algarismos não conta.
     */
    private static function fimDasFaixas(string $miolo): int
    {
        $fim = 0;
        $posicao = 0;
        while (preg_match('/\G\s*+(\d++)\s++(\d++)/', $miolo, $faixa, 0, $posicao) === 1) {
            $posicao += \strlen($faixa[0]);
            if (\strlen($faixa[1]) <= 9 && \strlen($faixa[2]) <= 9) {
                $fim = max($fim, (int) $faixa[1] + (int) $faixa[2]);
            }
        }

        return $fim;
    }
}
