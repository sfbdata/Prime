<?php

declare(strict_types=1);

namespace App\Dashboard\Ranking;

/**
 * "1º do mês" do cartão do colaborador (spec dashboard-parte-1, item 4; README do desenho, "Botão
 * direito no colaborador"): em cada mês FECHADO, o campeão pela regra do troféu
 * (RankingDeMetasConcluidas): mais metas concluídas entre as criadas no mês (total − ativas),
 * desempate menos vencidas (as de hoje, como no script do desenho), empate total sem campeão.
 *
 * Calculado a cada abertura do cartão, sobre os colaboradores ativos de hoje: não há tabela
 * `dashboard_campeao_mes` neste PR (sem migration), então um mês antigo pode mudar de dono se uma
 * meta daquele mês for reaberta ou concluída depois.
 */
final class HistoricoDeCampeoes
{
    /**
     * @param list<array{ano: int, mes: int, userId: int, total: int, ativas: int}> $linhas
     * @param list<int>                                                         $pessoas
     * @param array<int, int>                                                   $vencidas
     *
     * @return array{anos: list<int>, anoAtual: int, ultimoMesFechado: int, ganhos: array<int, list<int>>}
     */
    public static function doColaborador(int $userId, array $linhas, array $pessoas, array $vencidas, \DateTimeImmutable $hoje): array
    {
        $anoAtual = (int) $hoje->format('Y');
        $mesAtual = (int) $hoje->format('n');
        $ganhos   = [];
        foreach (self::concluidasPorMes($linhas) as $ano => $meses) {
            foreach ($meses as $mes => $concluidas) {
                $fechado = $ano < $anoAtual || ($ano === $anoAtual && $mes < $mesAtual);
                if ($fechado && RankingDeMetasConcluidas::campeao($pessoas, $concluidas, $vencidas) === $userId) {
                    $ganhos[$ano][] = $mes;
                }
            }
        }

        return [
            'anos'             => self::anos($linhas, $anoAtual),
            'anoAtual'         => $anoAtual,
            'ultimoMesFechado' => $mesAtual - 1,
            'ganhos'           => array_map(static fn (array $m): array => self::ordenados($m), $ganhos),
        ];
    }

    /**
     * @param list<array{ano: int, mes: int, userId: int, total: int, ativas: int}> $linhas
     *
     * @return array<int, array<int, array<int, int>>> ano => mês => userId => concluídas
     */
    private static function concluidasPorMes(array $linhas): array
    {
        $mapa = [];
        foreach ($linhas as $l) {
            $mapa[$l['ano']][$l['mes']][$l['userId']] = max(0, $l['total'] - $l['ativas']);
        }

        return $mapa;
    }

    /**
     * Do ano corrente até o primeiro ano com meta no escritório, do mais novo para o mais antigo.
     *
     * @param list<array{ano: int, mes: int, userId: int, total: int, ativas: int}> $linhas
     *
     * @return list<int>
     */
    private static function anos(array $linhas, int $anoAtual): array
    {
        $primeiro = min([$anoAtual, ...array_column($linhas, 'ano')]);

        return range($anoAtual, $primeiro);
    }

    /**
     * @param list<int> $meses
     *
     * @return list<int>
     */
    private static function ordenados(array $meses): array
    {
        sort($meses);

        return $meses;
    }
}
