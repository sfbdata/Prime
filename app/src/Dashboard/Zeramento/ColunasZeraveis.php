<?php

declare(strict_types=1);

namespace App\Dashboard\Zeramento;

use App\Dashboard\Exception\ColunaNaoZeravelException;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;

/**
 * As colunas da tabela Desempenho que aceitam "Zerar" (DASH-014). O desenho ("01 - Dashboard
 * 1.2.2", `thMenu` e `thExtras`) põe o menu de zerar em TODA coluna numérica: as 7 fixas e
 * todas as extras do "Adicionar coluna". A chave é a mesma do `data-coluna` da tabela.
 *
 * Rótulo: as fixas pelo catálogo de tradução (`dashboard.tabela.*`), as extras pelo rótulo do
 * desenho em ColunasExtrasDoDashboard::CATALOGO.
 */
final class ColunasZeraveis
{
    public const METAS           = 'metas';
    public const METAS_ATIVAS    = 'metas_ativas';
    public const METAS_VENCIDAS  = 'metas_vencidas';
    public const PRAZOS          = 'prazos';
    public const DEMANDAS        = 'demandas';
    public const DEMANDAS_ATIVAS = 'demandas_ativas';
    public const PASTAS_CRIADAS  = 'pastas_criadas';

    /** Coluna fixa => chave do rótulo no catálogo. */
    public const FIXAS = [
        self::METAS           => 'dashboard.tabela.total_metas',
        self::METAS_ATIVAS    => 'dashboard.tabela.metas_ativas',
        self::METAS_VENCIDAS  => 'dashboard.tabela.metas_vencidas',
        self::PRAZOS          => 'dashboard.tabela.prazos_proximos',
        self::DEMANDAS        => 'dashboard.tabela.total_demandas',
        self::DEMANDAS_ATIVAS => 'dashboard.tabela.demandas_ativas',
        self::PASTAS_CRIADAS  => 'dashboard.tabela.pastas_criadas',
    ];

    public static function existe(string $coluna): bool
    {
        return isset(self::FIXAS[$coluna]) || isset(Extras::CATALOGO[$coluna]);
    }

    /** @throws ColunaNaoZeravelException */
    public static function exigir(string $coluna): void
    {
        if (!self::existe($coluna)) {
            throw new ColunaNaoZeravelException('Esta coluna não existe na tabela Desempenho.');
        }
    }

    /**
     * @param callable(string): string $traduzir chave do catálogo => texto no idioma de quem lê
     */
    public static function rotulo(string $coluna, callable $traduzir): string
    {
        if (isset(self::FIXAS[$coluna])) {
            return $traduzir(self::FIXAS[$coluna]);
        }

        return Extras::CATALOGO[$coluna]['rotulo'] ?? $coluna;
    }
}
