<?php

declare(strict_types=1);

namespace App\Master\Fontes;

/**
 * Validação estrutural de um retrato do Quadro da Advocacia, seja lido da página da OAB, seja
 * enviado pelo Master em planilha. Só aceita se: as seccionais forem exatamente as 27 UFs, todas
 * com advogados > 0, e a soma de cada coluna fechar com o TOTAL quando o TOTAL existir.
 */
final class ValidadorQuadroOab
{
    /**
     * @param array<string, list<int|null>> $porUf       UF => 14 posições (ver {@see LeitorQuadroOab})
     * @param array<int, int|null>          $totais      posição => TOTAL informado (nulo/ausente = sem total)
     * @param list<int>                     $obrigatorias posições cujo TOTAL tem de existir
     *
     * @return string|null o problema encontrado, ou nulo se o retrato é aceitável
     */
    public static function problema(array $porUf, array $totais, array $obrigatorias = []): ?string
    {
        $siglas = array_keys($porUf);
        sort($siglas);
        $esperadas = array_keys(ClienteIbge::CODIGO_UF);
        sort($esperadas);
        if ($siglas !== $esperadas) {
            $diferenca = array_merge(array_diff($siglas, $esperadas), array_diff($esperadas, $siglas));

            return 'seccionais diferentes das 27 UFs (' . implode(', ', $diferenca) . ')';
        }

        foreach ($porUf as $uf => $valores) {
            if (($valores[0] ?? null) === null || $valores[0] <= 0) {
                return sprintf('sem advogados em %s', $uf);
            }
        }

        for ($posicao = 0; $posicao < 14; $posicao++) {
            $total = $totais[$posicao] ?? null;
            if ($total === null) {
                if (in_array($posicao, $obrigatorias, true)) {
                    return sprintf('sem o TOTAL da coluna %d', $posicao + 1);
                }
                continue;
            }

            $soma = array_sum(array_map(static fn (array $v): int => (int) ($v[$posicao] ?? 0), $porUf));
            if ($soma !== $total) {
                return sprintf('a soma da coluna %d (%d) não fecha com o TOTAL (%d)', $posicao + 1, $soma, $total);
            }
        }

        return null;
    }
}
