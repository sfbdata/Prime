<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Saída do adapter: o que a fonte produziu ANTES de qualquer persistência — boletos importáveis,
 * rejeitados (com motivo) e a contagem de linhas ignoradas (rodapé/totais/metadados). É a base do
 * preview e do relatório final (SPEC §21).
 */
final class ResultadoLeitura
{
    /**
     * @param list<BoletoImportavel> $importaveis
     * @param list<LinhaRejeitada>   $rejeitadas
     */
    public function __construct(
        public readonly array $importaveis,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
    ) {
    }
}
