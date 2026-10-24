<?php

declare(strict_types=1);

namespace App\Dashboard\Zeramento;

/**
 * Refaz a contagem de uma coluna para quem tem corte (DASH-014): para cada grupo de pessoas com o
 * mesmo corte, a MESMA consulta da coluna roda de novo a partir dele, e o valor delas é trocado
 * pelo recontado. Quem não tem corte fica com o valor de sempre. Pessoa sem nada depois do corte
 * sai do mapa (o painel já lê a falta como zero, ou "Sem dado" nas médias).
 */
final class RecontagemComCorte
{
    /**
     * @template T
     *
     * @param array<int, T>                                    $mapa        userId => valor sem corte
     * @param list<int>                                        $pessoas     universo da tabela
     * @param callable(\DateTimeImmutable): array<int, T>      $contarDesde a consulta da coluna a partir do corte
     *
     * @return array<int, T>
     */
    public static function aplicar(array $mapa, CortesDoDashboard $cortes, string $coluna, array $pessoas, callable $contarDesde): array
    {
        if (!$cortes->temCorte($coluna)) {
            return $mapa;
        }

        foreach ($cortes->grupos($coluna, $pessoas) as $grupo) {
            $recontado = $contarDesde($grupo['desde']);
            foreach ($grupo['pessoas'] as $id) {
                unset($mapa[$id]);
                if (array_key_exists($id, $recontado)) {
                    $mapa[$id] = $recontado[$id];
                }
            }
        }

        return $mapa;
    }
}
