<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * Normalização da inscrição na OAB para contar "OABs únicas" (desenho 05 - Perfis 1.0, `norm`):
 * número só com dígitos e sem zeros à esquerda + "/" + UF em maiúsculas.
 */
final class InscricaoOab
{
    public static function normalizar(?string $numero, ?string $uf): string
    {
        $digitos = ltrim((string) preg_replace('/\D/', '', (string) $numero), '0');

        return $digitos . '/' . strtoupper(trim((string) $uf));
    }

    /** O número gravado difere do normalizado (pontos, zeros à esquerda, UF minúscula)? */
    public static function precisaFormatar(string $numero, string $uf): bool
    {
        $digitos = ltrim((string) preg_replace('/\D/', '', $numero), '0');

        return $numero !== $digitos || $uf !== strtoupper($uf);
    }
}
