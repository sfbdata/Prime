<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * `totaisEstoque` (PR-F): total do ESTOQUE com seta pela foto, sobre as linhas VISÍVEIS. `atual` soma o
 * estoque de hoje por coluna; `anterior` soma a foto de `data_de − 1` (int só com foto de TODA linha
 * visível, senão null). Null sem período, com pasta oculta pelo cadeado ou sem nenhuma foto.
 */
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
        // TENDÊNCIA — período anterior de mesma duração; tudo null sem período completo (o painel não inventa um).
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
         * Σ metas; tempo médio = média das linhas com tempo > 0 (desenho). Null = sem base ("—").
         *
         * @var array<string, int|null>
         */
        public readonly array  $totaisExtras = [],
        /**
         * Total de cada extra COM TENDÊNCIA no período anterior, pela mesma regra do
         * `totaisExtras` e sobre as mesmas linhas visíveis. Null sem período completo; dentro,
         * null por coluna quando não havia base (sem pílula).
         *
         * @var array<string, int|null>|null
         */
        public readonly ?array $totaisExtrasAnteriores = null,
        /** Quem leva o troféu (mais metas concluídas no período, total menos ativas; spec dashboard-parte-1). Null = ninguém. */
        public readonly ?int   $campeaoId = null,
        /** @var array<string, array{todos: ?string, pessoas: array<int, string>}> cortes ativos do "Zerar" (CortesDoDashboard::paraTela) */
        public readonly array  $zeramentos = [],
        /** @var array{atual: array<string, int>, anterior: array<string, int|null>}|null estoque com seta pela foto (ver a classe) */ public readonly ?array $totaisEstoque = null,
        public readonly ?MetaDePastasDoMesOutput $metaDePastas = null, // meta de pastas do mês (DASH-016)
    ) {}
}
