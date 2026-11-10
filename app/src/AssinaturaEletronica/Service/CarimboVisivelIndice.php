<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;

/**
 * O último índice do PDF, lido como o `carimbar` do protótipo lê (bj-assina-pdf.js:23): o último `startxref`, se ali
 * começa uma tabela `xref` clássica ou um fluxo /XRef, e do dicionário do trailer (ou do fluxo) o /Size, o /Root e, se
 * houver, o /Info e o /ID, com o texto como está no arquivo.
 *
 * Diferente do protótipo, o primeiro número livre não confia só no /Size do último índice: um /Size menor que os
 * números em uso faria o carimbo escrever por cima de um objeto do arquivo. E, se os objetos do carimbo passariam do
 * limite de números do PDF, o índice não é reconhecido.
 */
final readonly class CarimboVisivelIndice
{
    /** O maior número de objeto do PDF 1.7 (Anexo C). */
    public const NUMERO_MAXIMO_DE_OBJETO = 8_388_607;

    private function __construct(
        /** Onde começa o último índice: o /Prev do acréscimo. */
        public int $anterior,
        public bool $classico,
        /** O /Size do último índice, como está no arquivo. */
        public int $tamanho,
        /**
         * O primeiro número livre para os objetos do carimbo: o maior entre todo /Size do arquivo (de qualquer trailer
         * e de qualquer fluxo /XRef) e um a mais que o maior número em uso (cabeçalho solto `n g obj` ou faixa do
         * /Index de um fluxo /XRef).
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

    /**
     * Null quando o protótipo diria "Estrutura do PDF não reconhecida.", e também quando o /Size novo (o primeiro
     * livre mais os três objetos do carimbo e o do fluxo /XRef) passaria de `NUMERO_MAXIMO_DE_OBJETO`.
     */
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
        if ($emUso === null || max((int) $tamanho[1], $emUso) + 4 > self::NUMERO_MAXIMO_DE_OBJETO) {
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
     * O maior entre todo /Size do arquivo (um /Size fora de trailer ou fluxo /XRef só deixa o número maior) e um a mais
     * que o maior número em uso: cabeçalhos soltos (o padrão do leitor do motor) e faixas `primeiro quantidade` do
     * /Index dos fluxos /XRef (os objetos dentro de /ObjStm são achados por elas ou pelo /Size). Número de mais de 9
     * algarismos conta como acima do limite. Null acima do teto de cabeçalhos do motor (M27).
     */
    private static function seguinteAoMaiorEmUso(string $pdf): ?int
    {
        $maiores = [
            self::maiorDasOcorrencias($pdf, '/\/Size\s*+(\d++)/', self::numero(...)),
            self::maiorDasOcorrencias($pdf, '/(?:^|[\s>])(\d++)\s++\d++\s++obj\b/', static fn (string $numero): int => self::numero($numero) + 1),
            self::maiorDasOcorrencias($pdf, '/\/Index\s*+\[([\d\s]*+)\]/', self::fimDasFaixas(...)),
        ];

        return \in_array(null, $maiores, true) ? null : max($maiores);
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

    /** O fim (primeiro mais quantidade) da faixa que vai mais longe num /Index, par a par, sem montar a lista. */
    private static function fimDasFaixas(string $miolo): int
    {
        $fim = 0;
        $posicao = 0;
        while (preg_match('/\G\s*+(\d++)\s++(\d++)/', $miolo, $faixa, 0, $posicao) === 1) {
            $posicao += \strlen($faixa[0]);
            $fim = max($fim, self::numero($faixa[1]) + self::numero($faixa[2]));
        }

        return $fim;
    }

    /** O número do texto; com mais de 9 algarismos, um acima do limite (sem estourar o inteiro). */
    private static function numero(string $algarismos): int
    {
        return \strlen($algarismos) <= 9 ? (int) $algarismos : self::NUMERO_MAXIMO_DE_OBJETO + 1;
    }
}
