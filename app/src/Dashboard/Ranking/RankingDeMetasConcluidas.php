<?php

declare(strict_types=1);

namespace App\Dashboard\Ranking;

/**
 * Regra do troféu e do ranking do cartão do colaborador (spec dashboard-parte-1, itens 3 e 4), a
 * do desenho (dc 1.2.2, script l.2928 e l.3040): mais metas concluídas no período (Total metas −
 * Metas ativas da pessoa) vem primeiro; empate, menos metas vencidas. Persistindo o empate, as
 * pessoas DIVIDEM a posição (1º, 1º, 3º) e ninguém leva o troféu (o desenho é omisso aí; o painel
 * não escolhe vencedor por ordem alfabética). Quem não concluiu nenhuma nunca é campeão.
 */
final class RankingDeMetasConcluidas
{
    /**
     * Concluídas por pessoa = total − ativas, nos mapas que o painel já calcula.
     *
     * @param array<int, int> $total
     * @param array<int, int> $ativas
     *
     * @return array<int, int>
     */
    public static function concluidas(array $total, array $ativas): array
    {
        $mapa = [];
        foreach ($total as $id => $n) {
            $mapa[$id] = max(0, $n - ($ativas[$id] ?? 0));
        }

        return $mapa;
    }

    /**
     * @param list<int>       $pessoas    universo do ranking (colaboradores ativos do escritório)
     * @param array<int, int> $concluidas userId => metas concluídas no período
     * @param array<int, int> $vencidas   userId => metas vencidas hoje
     *
     * @return array<int, int> userId => posição (1 = primeiro), na ordem do ranking
     */
    public static function posicoes(array $pessoas, array $concluidas, array $vencidas): array
    {
        $chave = static fn (int $id): array => [-($concluidas[$id] ?? 0), $vencidas[$id] ?? 0];
        $ordem = $pessoas;
        usort($ordem, static fn (int $a, int $b): int => $chave($a) <=> $chave($b));

        $posicoes = [];
        $anterior = null;
        $posicao  = 0;
        foreach ($ordem as $i => $id) {
            if ($chave($id) !== $anterior) {
                $posicao  = $i + 1;
                $anterior = $chave($id);
            }
            $posicoes[$id] = $posicao;
        }

        return $posicoes;
    }

    /**
     * O único primeiro colocado com pelo menos uma meta concluída; null sem ninguém ou com empate.
     *
     * @param list<int>       $pessoas
     * @param array<int, int> $concluidas
     * @param array<int, int> $vencidas
     */
    public static function campeao(array $pessoas, array $concluidas, array $vencidas): ?int
    {
        $primeiros = array_keys(array_filter(
            self::posicoes($pessoas, $concluidas, $vencidas),
            static fn (int $posicao): bool => $posicao === 1,
        ));

        if (count($primeiros) !== 1 || ($concluidas[$primeiros[0]] ?? 0) <= 0) {
            return null;
        }

        return $primeiros[0];
    }
}
