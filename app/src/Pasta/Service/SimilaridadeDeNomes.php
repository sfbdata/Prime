<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Quão parecidos são dois nomes de documento — `norm` e `similar` de `bj-docsug.js` (L9, L62).
 *
 * Extraída do `SugestorDeDocumentos` (que continua usando a mesma regra para o "já no checklist")
 * para servir também ao "nome parecido" da aba Documentos (DOC-63, L9). Serviço puro: só texto.
 *
 * REGRA (a do JS, sem nada a mais):
 *  - normalização: minúsculas e sem acento (NFD sem as marcas U+0300–U+036F);
 *  - palavras: sem a extensão final, separadas por tudo que não é [a-z0-9], com 3+ letras, fora
 *    números puros e "assinado/assinada/final/copia/versao/vN";
 *  - similaridade = palavras em comum ÷ tamanho do MENOR conjunto (0 se algum lado não tem palavra).
 */
final class SimilaridadeDeNomes
{
    /** Minúsculas e sem acento — `norm` de bj-docsug.js L9. */
    public static function normalizar(string $texto): string
    {
        $decomposto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
        if ($decomposto === false) {
            $decomposto = $texto;
        }

        return mb_strtolower((string) preg_replace('/[\x{0300}-\x{036f}]/u', '', $decomposto));
    }

    /** Palavras em comum ÷ tamanho do menor conjunto — `similar` de bj-docsug.js L62. */
    public static function similaridade(string $a, string $b): float
    {
        return self::similaridadeEntre(self::palavras($a), self::palavras($b));
    }

    /**
     * O conjunto de palavras que entra na conta (chave = palavra).
     *
     * @return array<string, true>
     */
    public static function palavras(string $texto): array
    {
        $semExtensao = (string) preg_replace('/\.\w+$/u', '', self::normalizar($texto));
        $palavras    = [];

        foreach (preg_split('/[^a-z0-9]+/', $semExtensao) ?: [] as $palavra) {
            if (\strlen($palavra) > 2 && preg_match('/^(\d+|assinad[oa]|final|copia|versao|v\d)$/', $palavra) !== 1) {
                $palavras[$palavra] = true;
            }
        }

        return $palavras;
    }

    /**
     * Pares de nomes com similaridade ≥ `$minimo` e nomes normalizados DIFERENTES — a regra de
     * "possíveis duplicados" do desenho (bj-docsug.js L107-109). Mesmo resultado da comparação de
     * todos contra todos, sem fazê-la: a pasta de produção tem 1.128 documentos (≈636 mil pares).
     *
     * Filtro de prefixo: para A (o conjunto menor ou igual) chegar a `k` palavras em comum com B,
     * B precisa conter alguma das `|A| − k + 1` primeiras palavras de A numa ordem global fixa —
     * se nenhuma delas estivesse em B, sobrariam no máximo `k − 1` em comum. A ordem é pela
     * raridade (palavra rara primeiro), então cada nome só é confrontado com os poucos que
     * compartilham uma palavra rara com ele. Cada candidato é conferido pela conta exata.
     *
     * @param array<int, string> $nomes id => nome
     *
     * @return list<array{a: int, b: int, similaridade: float}> com `a < b`, em ordem de (a, b)
     */
    public static function paresParecidos(array $nomes, float $minimo): array
    {
        $conjuntos   = [];
        $normalizado = [];
        $frequencia  = [];
        foreach ($nomes as $id => $nome) {
            $p = self::palavras($nome);
            if ($p === []) {
                continue;
            }
            $conjuntos[$id]   = $p;
            $normalizado[$id] = self::normalizar($nome);
            foreach ($p as $palavra => $_) {
                $frequencia[$palavra] = ($frequencia[$palavra] ?? 0) + 1;
            }
        }

        $indice   = [];
        $ordenado = [];
        foreach ($conjuntos as $id => $p) {
            $lista = array_map('strval', array_keys($p));
            usort($lista, static fn (string $x, string $y): int => [$frequencia[$x], $x] <=> [$frequencia[$y], $y]);
            $ordenado[$id] = $lista;
            foreach ($lista as $palavra) {
                $indice[$palavra][] = $id;
            }
        }

        $pares = [];
        foreach ($ordenado as $id => $lista) {
            $tamanho = \count($lista);
            $prefixo = $tamanho - self::minimoEmComum($tamanho, $minimo) + 1;
            if ($prefixo < 1) {
                continue; // nem com todas as palavras em comum chegaria ao mínimo
            }
            foreach (\array_slice($lista, 0, $prefixo) as $palavra) {
                foreach ($indice[$palavra] as $outro) {
                    if ($outro === $id || \count($ordenado[$outro]) < $tamanho) {
                        continue; // o menor dos dois é quem procura
                    }
                    [$a, $b] = $id < $outro ? [$id, $outro] : [$outro, $id];
                    if (isset($pares[$a . ':' . $b]) || $normalizado[$a] === $normalizado[$b]) {
                        continue;
                    }
                    $s = self::similaridadeEntre($conjuntos[$a], $conjuntos[$b]);
                    if ($s >= $minimo) {
                        $pares[$a . ':' . $b] = ['a' => $a, 'b' => $b, 'similaridade' => $s];
                    }
                }
            }
        }

        $lista = array_values($pares);
        usort($lista, static fn (array $x, array $y): int => [$x['a'], $x['b']] <=> [$y['a'], $y['b']]);

        return $lista;
    }

    /**
     * @param array<string, true> $a
     * @param array<string, true> $b
     */
    private static function similaridadeEntre(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return \count(array_intersect_key($a, $b)) / min(\count($a), \count($b));
    }

    /**
     * O menor número de palavras em comum `c` com `c / $tamanho >= $minimo` — pela MESMA divisão
     * da conta exata, para o filtro nunca descartar um par que ela aceitaria. Acima do tamanho
     * (mínimo > 1) devolve `$tamanho + 1`: nenhum par chega lá.
     */
    private static function minimoEmComum(int $tamanho, float $minimo): int
    {
        for ($c = 0; $c <= $tamanho; ++$c) {
            if ($c / $tamanho >= $minimo) {
                return $c;
            }
        }

        return $tamanho + 1;
    }
}
