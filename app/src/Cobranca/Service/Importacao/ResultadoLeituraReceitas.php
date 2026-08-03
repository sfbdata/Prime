<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Saída do `TopLifeReceitasAdapter`: os recebimentos legíveis, os NNs recusados com motivo e as duas
 * contagens de descarte, que aqui são informação de negócio e não ruído.
 *
 * ⚠️ `emAberto` é a contagem que mais importa no relatório: são as linhas com `Recebimento = "-"`, que
 * a contábil inclui quando o export pede a situação "Aberta". Remedido em 03/08: **2.094 linhas**,
 * somando **R$ 280.366,71** na coluna nominal. Não são receita — se entrassem, o sistema daria por
 * pagos 2.094 boletos que ninguém pagou. Ver spec §2, que registra por que este docblock afirmava
 * R$ 2.045.780 até hoje e por que esse número não se reproduz em arquivo nenhum.
 *
 * Irmão de `ResultadoLeitura` (inadimplência), `ResultadoLeituraAcordos` e `ResultadoLeituraCadastro`.
 */
final class ResultadoLeituraReceitas
{
    /**
     * @param list<ReceitaImportavel> $receitas
     * @param list<LinhaRejeitada>    $rejeitadas
     * @param array<string, int>      $classesForaDoMapa código da classe de conta → nº de linhas
     */
    public function __construct(
        public readonly array $receitas,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
        public readonly int $emAberto,
        /**
         * Classes de conta que caíram no principal por OMISSÃO, e não por estarem no mapa da spec §5.
         *
         * Hoje são só as raras conhecidas — `1.12` (Água), `1.19` (sobras) e `1.22` (ajuste), 11 linhas
         * nos dois arquivos de 03/08. O campo existe para o dia em que não forem: a contábil pode criar
         * um código novo, e sem esta lista ele entraria no principal em silêncio. O total continuaria
         * fechando (o balde errado soma igual) e o rateio do `Pagamento` sairia errado — que é o modo
         * de falhar que a conferência por total não pega.
         */
        public readonly array $classesForaDoMapa = [],
    ) {
    }
}
