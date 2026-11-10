<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * O último índice do PDF, lido como o `carimbar` do protótipo lê (bj-assina-pdf.js:23): o último `startxref`, se ali
 * começa uma tabela `xref` clássica ou um fluxo /XRef, e do dicionário do trailer (ou do fluxo) o /Size, o /Root e, se
 * houver, o /Info e o /ID, com o texto como está no arquivo.
 */
final readonly class CarimboVisivelIndice
{
    private function __construct(
        /** Onde começa o último índice: o /Prev do acréscimo. */
        public int $anterior,
        public bool $classico,
        /** O /Size: o primeiro número livre para os objetos do carimbo. */
        public int $tamanho,
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
        preg_match('/\/Info\s+\d+\s+\d+\s+R/', $dicionario, $informacoes);
        preg_match('/\/ID\s*\[[^\]]*\]/', $dicionario, $identificador);

        return new self(
            $anterior,
            $classico,
            (int) $tamanho[1],
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
}
