<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Saída do `TopLifeReceitasAdapter`: os recebimentos legíveis, os NNs recusados com motivo e as duas
 * contagens de descarte, que aqui são informação de negócio e não ruído.
 *
 * ⚠️ `emAberto` é a contagem que mais importa no relatório: são as linhas com `Recebimento = "-"`, que
 * a contábil inclui quando o export pede a situação "Aberta". Medido em 03/08: **2.094 linhas**,
 * somando R$ 2.045.780 na coluna nominal. Não são receita — se entrassem, o sistema registraria dois
 * milhões de reais que ninguém recebeu. Ver spec §2.
 *
 * Irmão de `ResultadoLeitura` (inadimplência), `ResultadoLeituraAcordos` e `ResultadoLeituraCadastro`.
 */
final class ResultadoLeituraReceitas
{
    /**
     * @param list<ReceitaImportavel> $receitas
     * @param list<LinhaRejeitada>    $rejeitadas
     */
    public function __construct(
        public readonly array $receitas,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
        public readonly int $emAberto,
    ) {
    }
}
