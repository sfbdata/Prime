<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * A regra única do código de convite de um convênio (spec docs/specs/convenios.md, §5.3): como o texto digitado vira
 * código e o que é um código válido. Vale para quem aplica (conta) e para quem escolhe (Master).
 */
final class CodigoDeConvenio
{
    public const FORMATO = '/^[A-Z0-9]{4,16}$/';

    /** O código como o Master o vê: maiúsculas, sem espaço nem hífen (a pessoa pode copiar "abcd-2345"). */
    public static function normalizar(string $codigo): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $codigo));
    }

    /** Já normalizado: 4 a 16 letras ou números. */
    public static function valido(string $codigoNormalizado): bool
    {
        return preg_match(self::FORMATO, $codigoNormalizado) === 1;
    }
}
