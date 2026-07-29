<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Enum;

/**
 * Séries oficiais que alimentam a calculadora de atualização monetária.
 *
 * Todas vêm da API pública do Banco Central (SGS) e são dado público, idêntico para todos os
 * escritórios — daí a tabela `indice_monetario` não ter `tenant_id` (exceção justificada na spec §7.1).
 */
enum SerieIndice: string
{
    case INPC = 'INPC';
    case IPCA = 'IPCA';

    /** Taxa legal de juros da Lei 14.905/2024, publicada pelo BCB a partir de 08/2024. */
    case TAXA_LEGAL = 'TAXA_LEGAL';

    /**
     * Código da série no SGS do Banco Central.
     *
     * Medido em 29/07/2026: 188 cobre de 04/1979, 433 de 01/1980 e 29543 de 08/2024.
     */
    public function codigoSgs(): string
    {
        return match ($this) {
            self::INPC => '188',
            self::IPCA => '433',
            self::TAXA_LEGAL => '29543',
        };
    }

    /** Identificação da procedência gravada em cada registro importado (coluna `fonte`). */
    public function fonte(): string
    {
        return 'BCB/SGS/' . $this->codigoSgs();
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::INPC => 'INPC',
            self::IPCA => 'IPCA',
            self::TAXA_LEGAL => 'Taxa legal (Lei 14.905/2024)',
        };
    }
}
