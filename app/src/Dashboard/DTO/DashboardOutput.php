<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

final class DashboardOutput
{
    /**
     * @param LinhaAdvogadoDashboardOutput[] $porAdvogado
     */
    public function __construct(
        // CARDS
        public readonly int   $totalMetasAtivas,
        public readonly int   $demandasUrgentes,
        public readonly int   $metaGlobalPercent,
        // TABELA
        public readonly array $porAdvogado,
        // CARDS (acrescentados no fim, com default, para não quebrar quem constrói por posição)
        /** Soma de `pastasCriadas` das linhas: respeita período, responsável e cargo. */
        public readonly int   $totalPastasCriadas = 0,
        /** Numerador e denominador do `metaGlobalPercent`, para a legenda "X de Y metas concluídas". */
        public readonly int   $metasConcluidas = 0,
        public readonly int   $metasTotal = 0,
        // TENDÊNCIA — período anterior de mesma duração. Tudo null quando não há período
        // completo (data_de E data_ate válidas): sem período não existe "anterior" e o painel
        // não inventa um.
        /**
         * Totais do período anterior para a linha de Total da tabela. Soma das linhas VISÍVEIS
         * (respeita responsável, cargo e busca), como a própria linha de Total. Chaves = as de
         * ordenação da coluna.
         *
         * - `metas`, `demandas`, `pastas_criadas`: reconstruídas por data de criação no período
         *   anterior de mesma duração — sempre int quando há período.
         * - `metas_vencidas`, `prazos`: estoque lido da foto diária do dia `data_de − 1` (o
         *   painel as conta sem período, mesma base da foto). Int só quando TODA linha visível
         *   tem foto naquele dia; null se faltar a de alguém (ou não houver linha) — somar
         *   parcial compararia grupos diferentes. Metas/Demandas ativas não têm tendência: o
         *   painel as filtra por criação no período e a foto é o estoque inteiro.
         *
         * @var array{metas: int, demandas: int, pastas_criadas: int, metas_vencidas: int|null, prazos: int|null}|null
         */
        public readonly ?array $totaisAnteriores = null,
        /** `totalPastasCriadas` do período anterior, com o mesmo critério do card (antes da busca). */
        public readonly ?int   $totalPastasCriadasAnterior = null,
        /**
         * Janela usada como período anterior, em 'Y-m-d' (para o title "antes → agora").
         *
         * @var array{data_de: string, data_ate: string}|null
         */
        public readonly ?array $periodoAnterior = null,
        // COLUNAS EXTRAS ("Adicionar coluna" do menu ⋮)
        /**
         * Chaves do ColunasExtrasDoDashboard ligadas pelo usuário, na ordem da tabela (cada "+"
         * acrescenta no fim). Vazia = a tabela de sempre.
         *
         * @var list<string>
         */
        public readonly array  $colunasExtras = [],
        /**
         * Total de cada extra sobre as linhas VISÍVEIS: contagem = soma; taxa = Σ concluídas ÷
         * Σ metas; tempo médio = Σ dias ÷ Σ metas com data. Null = sem base ("—").
         *
         * @var array<string, int|null>
         */
        public readonly array  $totaisExtras = [],
    ) {}
}
