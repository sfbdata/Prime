<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Djen\DTO\LinhaDoProcessoDaOab;

/**
 * Filtros e cartões da aba "Processos da OAB" (desenho 11), sobre as linhas do escritório já carregadas.
 * Lógica pura: o relógio entra por parâmetro.
 */
final class FiltroDeProcessosDaOab
{
    public const MOVIMENTACOES = ['h', '7', '30', 'p30', 'p45', 'p90', 'nc'];

    /**
     * @param list<LinhaDoProcessoDaOab> $linhas
     * @param array{busca?: string, oab?: string, mov?: string, area?: string, fase?: string, pasta?: string} $criterios
     *
     * @return list<LinhaDoProcessoDaOab>
     */
    public function filtrar(array $linhas, array $criterios, \DateTimeImmutable $hoje): array
    {
        $busca = self::normalizar($criterios['busca'] ?? '');
        $oab = $criterios['oab'] ?? '';
        $mov = $criterios['mov'] ?? '';
        $area = $criterios['area'] ?? '';
        $fase = $criterios['fase'] ?? '';
        $pasta = $criterios['pasta'] ?? '';

        return array_values(array_filter($linhas, function (LinhaDoProcessoDaOab $l) use ($busca, $oab, $mov, $area, $fase, $pasta, $hoje): bool {
            if ($busca !== '' && !str_contains(self::normalizar($l->numero . ' ' . $l->numeroComMascara() . ' ' . $l->partes . ' ' . $l->orgao . ' ' . ($l->observacao ?? '')), $busca)) {
                return false;
            }

            return ($oab === '' || $l->oab === $oab)
                && ($area === '' || $l->area === $area)
                && ($fase === '' || $l->fase === $fase)
                && ($pasta !== 'c' || $l->pastaId !== null)
                && ($pasta !== 's' || $l->pastaId === null)
                && self::passaPelaMovimentacao($l, $mov, $hoje);
        }));
    }

    /**
     * @param list<LinhaDoProcessoDaOab> $linhas
     *
     * @return array{processos: int, movidos7: int, parados30: int, nuncaCapturados: int, semPasta: int}
     */
    public function indicadores(array $linhas, \DateTimeImmutable $hoje): array
    {
        $r = ['processos' => \count($linhas), 'movidos7' => 0, 'parados30' => 0, 'nuncaCapturados' => 0, 'semPasta' => 0];
        foreach ($linhas as $l) {
            $dias = $l->diasParado($hoje);
            $r['movidos7'] += $dias !== null && $dias <= 7 ? 1 : 0;
            $r['parados30'] += $dias !== null && $dias > 30 ? 1 : 0;
            $r['nuncaCapturados'] += $dias === null ? 1 : 0;
            $r['semPasta'] += $l->pastaId === null ? 1 : 0;
        }

        return $r;
    }

    private static function passaPelaMovimentacao(LinhaDoProcessoDaOab $l, string $mov, \DateTimeImmutable $hoje): bool
    {
        if ($mov === '') {
            return true;
        }
        $dias = $l->diasParado($hoje);
        if ($mov === 'nc') {
            return $dias === null;
        }
        if ($dias === null) {
            return false;
        }

        return match ($mov) {
            'h' => $dias === 0,
            '7' => $dias <= 7,
            '30' => $dias <= 30,
            'p30' => $dias > 30,
            'p45' => $dias > 45,
            'p90' => $dias > 90,
            default => true,
        };
    }

    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        return strtr($texto, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);
    }
}
