<?php

declare(strict_types=1);

namespace App\Agenda\Service;

/**
 * Lê o id que a tela manda: `x-<n>` é data acrescentada pelo escritório; o resto é id estável de data
 * do sistema (`fer-2026-02-17-carnaval`, `est-AL-...`, `rec-...`, `esc-...`, `aniv-...`).
 */
final class IdDeDataDaAgenda
{
    public static function acrescentada(string $id): ?int
    {
        if (1 !== preg_match('/^x-(\d{1,10})$/', $id, $m)) {
            return null;
        }
        if ((int) $m[1] > 2147483647) {
            throw new \DomainException('Data não encontrada.');
        }

        return (int) $m[1];
    }

    public static function doSistema(string $id): string
    {
        if (1 !== preg_match('/^(fer|est|forense|rec|com|trib|esc|aniv)-[A-Za-z0-9-]{1,110}$/', $id)) {
            throw new \DomainException('Data não encontrada.');
        }

        return $id;
    }
}
