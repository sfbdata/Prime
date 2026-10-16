<?php

declare(strict_types=1);

namespace App\Expediente\Service;

/**
 * Cores aceitas para um marcador. As 10 do desenho 1.1 (dc `MK_CORES`, E12) são as que a tela oferece;
 * as pastéis antigas continuam aceitas porque há marcadores gravados com elas.
 */
final class CoresDoMarcador
{
    public const DESENHO = [
        '#0a6fa0', '#5b4fb5', '#c0392b', '#d18a12', '#3a7bb8',
        '#4a6373', '#1f8a4c', '#c2457a', '#0f8b8d', '#8a5a12',
    ];

    public const ANTIGAS = [
        '#fde8e8', '#fde8f5', '#ede8fd', '#e8eafd', '#e8f4fd',
        '#e8fdf0', '#fdf6e8', '#fde8d0', '#f0f0f0',
    ];

    /** Cada pastel antigo na cor do desenho de mesmo tom; sem cor, o cinza-azulado do desenho. */
    private const PASTEL_PARA_DESENHO = [
        '#fde8e8' => '#c0392b',
        '#fde8f5' => '#c2457a',
        '#ede8fd' => '#5b4fb5',
        '#e8eafd' => '#3a7bb8',
        '#e8f4fd' => '#0a6fa0',
        '#e8fdf0' => '#1f8a4c',
        '#fdf6e8' => '#d18a12',
        '#fde8d0' => '#8a5a12',
        '#f0f0f0' => '#4a6373',
    ];

    public const SEM_COR = '#4a6373';

    /**
     * A cor com que o marcador aparece na tela: o desenho usa a cor no ícone, no texto do chip e na borda
     * do selecionado, o que só funciona com as cores saturadas. Pastel antigo vira o equivalente.
     */
    public static function paraTela(?string $cor): string
    {
        if ($cor === null || $cor === '') {
            return self::SEM_COR;
        }

        return self::PASTEL_PARA_DESENHO[$cor] ?? $cor;
    }

    public static function aceita(?string $cor): bool
    {
        return $cor === null || in_array($cor, self::DESENHO, true) || in_array($cor, self::ANTIGAS, true);
    }
}
