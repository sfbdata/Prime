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

    public static function aceita(?string $cor): bool
    {
        return $cor === null || in_array($cor, self::DESENHO, true) || in_array($cor, self::ANTIGAS, true);
    }
}
